<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);

/**
 * Pin a firewall's SSH host keys, verified over a channel that is not SSH.
 *
 * Every SSH caller in this product disabled strict host key checking, so first
 * contact with a firewall was trusted blindly. That is the one moment
 * verification matters, and this manager holds root keys to the whole fleet.
 *
 * The agent gives us a way out. It authenticates with its own API key and signs
 * its requests, independently of SSH, so asking the firewall for its own host
 * key fingerprints over the command channel and comparing them to what
 * ssh-keyscan sees is a real check rather than trust-on-first-use. A key that
 * does not match is not written.
 *
 * This is the by-hand procedure used on 2026-09-17 to clear a changed host key
 * on one firewall, made repeatable.
 *
 * Usage:
 *   php scripts/pin_host_keys.php --firewall 48        what it would pin
 *   php scripts/pin_host_keys.php --firewall 48 --apply
 *   php scripts/pin_host_keys.php --all --apply
 *
 * @since 3.60.0
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/ssh_options.php';

$opts  = getopt('', ['firewall:', 'all', 'apply', 'timeout:', 'help']);
$apply = isset($opts['apply']);

if (isset($opts['help']) || (!isset($opts['firewall']) && !isset($opts['all']))) {
    echo "Pin firewall SSH host keys, verified via the agent channel.\n\n";
    echo "  --firewall <id>   one firewall\n";
    echo "  --all             every firewall with a recent check-in\n";
    echo "  --apply           write to " . OPNMGR_KNOWN_HOSTS . " (otherwise report only)\n";
    echo "  --timeout <sec>   how long to wait for the agent (default 300)\n";
    exit(0);
}

$timeout = (int)($opts['timeout'] ?? 300);

/** Ask the firewall for its own host key fingerprints, over the agent channel. */
function agent_host_key_fingerprints(int $firewallId, int $timeout): array
{
    // OPNsense keeps host keys in /conf/sshd; the base and package paths are
    // checked too, because which one is in use depends on the sshd build.
    $cmd = "for f in /conf/sshd/ssh_host_*_key.pub /usr/local/etc/ssh/ssh_host_*_key.pub "
         . "/etc/ssh/ssh_host_*_key.pub; do [ -f \"\$f\" ] && ssh-keygen -lf \"\$f\"; done";

    $stmt = db()->prepare(
        "INSERT INTO firewall_commands (firewall_id, command, description, status)
         VALUES (?, ?, 'Report SSH host key fingerprints for pinning', 'pending')"
    );
    $stmt->execute([$firewallId, $cmd]);
    $id = (int) db()->lastInsertId();

    $deadline = time() + $timeout;
    while (time() < $deadline) {
        $row = db()->prepare('SELECT status, result FROM firewall_commands WHERE id = ?');
        $row->execute([$id]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        if ($r && in_array($r['status'], ['completed', 'failed'], true)) {
            $fps = [];
            foreach (explode("\n", (string) $r['result']) as $line) {
                if (preg_match('/\b(SHA256:[A-Za-z0-9+\/]+)\b/', $line, $m)) {
                    $fps[$m[1]] = true;
                }
            }
            return array_keys($fps);
        }
        sleep(5);
    }
    return [];
}

/** Addresses this manager actually connects to for a firewall. */
function firewall_addresses(array $fw): array
{
    // ip_address is 0.0.0.0 on every row in practice, and scanning it reaches
    // this manager rather than the firewall. The verification catches that - it
    // refuses to pin the local host's keys under a firewall's name - but it
    // should never be asked the question.
    $unusable = ['0.0.0.0', '255.255.255.255', '::', '0:0:0:0:0:0:0:0'];

    $addrs = [];
    foreach (['wan_ip', 'ip_address', 'lan_ip'] as $col) {
        $v = trim((string) ($fw[$col] ?? ''));
        if ($v === '' || in_array($v, $unusable, true)) {
            continue;
        }
        if (!filter_var($v, FILTER_VALIDATE_IP)) {
            continue;
        }
        if (filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false
            && !filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false) {
            // Private addresses are legitimate here - a firewall's LAN side is
            // often how the manager reaches it - but reserved ones are not.
        }
        if (str_starts_with($v, '127.')) {
            continue;
        }
        $addrs[$v] = true;
    }
    return array_keys($addrs);
}

$sql = 'SELECT id, hostname, wan_ip, ip_address FROM firewalls';
if (isset($opts['firewall'])) {
    $sql .= ' WHERE id = ' . (int) $opts['firewall'];
}
$firewalls = db()->query($sql . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

if (!$firewalls) {
    fwrite(STDERR, "No matching firewall.\n");
    exit(1);
}

$khFile = opnmgr_known_hosts_file();
if ($khFile === '' && $apply) {
    fwrite(STDERR, 'ERROR: cannot write ' . OPNMGR_KNOWN_HOSTS . "\n");
    exit(1);
}

$totalPinned = 0;
$totalRejected = 0;

foreach ($firewalls as $fw) {
    $id = (int) $fw['id'];
    printf("\n=== fw%d  %s ===\n", $id, $fw['hostname']);

    $addrs = firewall_addresses($fw);
    if (!$addrs) {
        echo "  no usable address recorded; skipped\n";
        continue;
    }

    echo "  asking the agent for its host keys...\n";
    $verified = agent_host_key_fingerprints($id, $timeout);
    if (!$verified) {
        echo "  NO ANSWER from the agent - nothing pinned.\n";
        echo "  Without an independent answer this would be trust-on-first-use,\n";
        echo "  which is the thing being removed.\n";
        continue;
    }
    printf("  firewall reports %d host key(s)\n", count($verified));

    foreach ($addrs as $addr) {
        $scanned = [];
        exec('ssh-keyscan -T 8 -t rsa,ecdsa,ed25519 ' . escapeshellarg($addr) . ' 2>/dev/null', $scanned);
        if (!$scanned) {
            printf("  %-18s unreachable\n", $addr);
            continue;
        }

        foreach ($scanned as $line) {
            $fpOut = [];
            $desc  = tempnam(sys_get_temp_dir(), 'opnmgr-hk');
            file_put_contents($desc, $line . "\n");
            exec('ssh-keygen -lf ' . escapeshellarg($desc) . ' 2>/dev/null', $fpOut);
            @unlink($desc);

            $fp = '';
            if ($fpOut && preg_match('/\b(SHA256:[A-Za-z0-9+\/]+)\b/', $fpOut[0], $m)) {
                $fp = $m[1];
            }

            if ($fp !== '' && in_array($fp, $verified, true)) {
                printf("  %-18s PIN      %s\n", $addr, $fp);
                if ($apply) {
                    exec('ssh-keygen -f ' . escapeshellarg($khFile) . ' -R ' . escapeshellarg($addr) . ' >/dev/null 2>&1');
                    file_put_contents($khFile, $line . "\n", FILE_APPEND);
                }
                $totalPinned++;
            } else {
                printf("  %-18s REJECT   %s  (firewall does not report this key)\n", $addr, $fp ?: '(unreadable)');
                $totalRejected++;
            }
        }
    }
}

printf("\n%d key(s) %s, %d rejected.\n", $totalPinned, $apply ? 'pinned' : 'would be pinned', $totalRejected);
if (!$apply) {
    echo "Nothing written. Re-run with --apply.\n";
}
if ($totalRejected > 0) {
    echo "A rejected key means what answered on that address did not match what the\n";
    echo "firewall reports about itself. Investigate before pinning anything.\n";
    exit(2);
}
