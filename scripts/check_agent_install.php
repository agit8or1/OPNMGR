<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);
/**
 * Flag firewalls whose agent installation is missing its etc/ tree.
 *
 * The 1.5.5 and 1.5.6 agent packages shipped with only their `opnsense/`
 * directory. `install_opnmanager_agent.sh` copies
 * `etc/inc/plugins.inc.d/opnmanageragent.inc` and `etc/rc.d/opnmanager_agent`
 * out of the archive and checks neither `cp` exit code, so a fresh install from
 * either package carried on past two silent failures, printed a non-fatal
 * "WARNING: Missing files", and then ran `sysrc opnmanager_agent_enable="YES"`
 * for a service whose rc.d script had never been installed. The agent keeps
 * checking in - it is started by other means - so nothing about the fleet view
 * looks wrong, which is exactly why this needs its own check.
 *
 * A firewall installed from an earlier package and upgraded in place is fine:
 * in-place upgrades never remove those two files.
 *
 * Usage:
 *   php scripts/check_agent_install.php            report
 *   php scripts/check_agent_install.php --probe    queue a probe on at-risk hosts
 *   php scripts/check_agent_install.php --probe --all
 *   php scripts/check_agent_install.php --json
 *
 * Reporting is inference until a probe comes back: enrolment date says whether a
 * firewall COULD have been installed from a bad package, the probe says whether
 * it was. --probe queues the read-only `agent_install_verify` action, which the
 * agent runs on its next check-in (~2 min); re-run the report afterwards.
 *
 * @since 3.20.1
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/agent_commands.php';

$root    = dirname(__DIR__);
$probe   = in_array('--probe', $argv, true);
$all     = in_array('--all', $argv, true);
$asJson  = in_array('--json', $argv, true);

// --- which releases are affected --------------------------------------------
// Packaged without etc/. 1.5.6 was repaired on 2026-08-31 (CHANGELOG 3.20.1),
// but installs made from the original package are still affected, so it stays
// listed with its original release date.
$affected = ['1.5.5' => '2026-02-09', '1.5.6' => '2026-02-09'];

// Also scan the published tarballs, so a future regression is caught without
// anyone having to remember to edit the list above. Only a REGRESSION counts:
// releases before 1.1.1 never carried an etc/ tree and used a different install
// layout, so their lacking one is not this bug.
//
// A release's date is the mtime of its tarball, except where a repaired original
// is kept in archive/ - then the original's mtime is the real release date, so a
// rebuild does not make a release look newer than it is.
$releases = [];
foreach (glob($root . '/downloads/plugins/os-opnmanager-agent-*.tar.gz') ?: [] as $tarball) {
    if (!preg_match('/-(\d+\.\d+\.\d+)\.tar\.gz$/', basename($tarball), $m)) {
        continue;
    }
    $hasEtc = false;
    try {
        $prefix = 'phar://' . realpath($tarball) . '/';
        foreach (new RecursiveIteratorIterator(new PharData($tarball)) as $entry) {
            if (strpos(substr($entry->getPathname(), strlen($prefix)), 'etc/') === 0) {
                $hasEtc = true;
                break;
            }
        }
    } catch (Throwable $e) {
        continue;   // an unreadable archive is a packaging problem, not a fleet one
    }

    $originals = glob($root . '/downloads/plugins/archive/'
                    . basename($tarball) . '.*') ?: [];
    $dateFrom = $originals ? min($originals) : $tarball;

    $releases[$m[1]] = ['etc' => $hasEtc, 'date' => date('Y-m-d', filemtime($dateFrom))];
}

uksort($releases, 'version_compare');
$seenEtc = false;
foreach ($releases as $version => $info) {
    if ($info['etc']) {
        $seenEtc = true;
    } elseif ($seenEtc) {
        $affected[$version] = $info['date'];
    }
}

ksort($affected);

/**
 * The newest release published on or before a given date - what a firewall
 * enrolling that day would have installed. Ties on date break to the highest
 * version. Returns null for a date that predates every release.
 */
function release_current_on(?string $date, array $releases): ?string {
    if ($date === null) {
        return null;
    }
    $best = null;
    foreach ($releases as $version => $info) {
        if ($info['date'] <= $date
            && ($best === null || version_compare($version, $best, '>'))) {
            $best = $version;
        }
    }
    return $best;
}

// --- fleet ------------------------------------------------------------------
$stmt = db()->query(
    'SELECT f.id, f.hostname, f.customer_name, f.enrolled_at, f.agent_version,
            fa.last_checkin AS agent_last_checkin
       FROM firewalls f
       LEFT JOIN firewall_agents fa
              ON f.id = fa.firewall_id AND fa.agent_type = \'primary\'
      ORDER BY f.hostname'
);
$firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Most recent probe per firewall.
$probes = [];
$pstmt = db()->query(
    "SELECT firewall_id, status, result, created_at, completed_at
       FROM firewall_commands
      WHERE action = 'agent_install_verify'
      ORDER BY id ASC"
);
foreach ($pstmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $probes[$row['firewall_id']] = $row;   // later rows overwrite earlier ones
}

/**
 * Read a probe result. Returns null when it cannot be interpreted.
 *
 * @return array{missing:string[], rc_enable:string}|null
 */
function parse_probe(?string $result): ?array {
    if ($result === null || strpos($result, 'OPNMGR_INSTALL_PROBE_V1') === false) {
        return null;
    }
    $missing = [];
    $rcEnable = 'unknown';
    foreach (preg_split('/\R/', $result) as $line) {
        $line = trim($line);
        if (substr($line, -8) === '=MISSING') {
            $missing[] = substr($line, 0, -8);
        } elseif (strpos($line, 'rc_enable=') === 0) {
            $rcEnable = substr($line, 10);
        }
    }
    return ['missing' => $missing, 'rc_enable' => $rcEnable];
}

$rows = [];
foreach ($firewalls as $fw) {
    $enrolled   = $fw['enrolled_at'] ? substr($fw['enrolled_at'], 0, 10) : null;
    $installed  = release_current_on($enrolled, $releases);
    $couldBeBad = $installed !== null && isset($affected[$installed]);
    $probeRow   = $probes[$fw['id']] ?? null;
    $parsed    = $probeRow ? parse_probe($probeRow['result']) : null;

    if ($parsed !== null) {
        $state  = $parsed['missing'] ? 'BROKEN' : 'OK';
        $detail = $parsed['missing']
            ? 'missing: ' . implode(', ', $parsed['missing'])
              . ($parsed['rc_enable'] === 'YES' ? ' (service enabled anyway)' : '')
            : 'verified by probe ' . substr((string)$probeRow['completed_at'], 0, 16);
    } elseif ($probeRow && in_array($probeRow['status'], ['pending', 'sent'], true)) {
        $state  = 'PENDING';
        $detail = 'probe queued ' . substr((string)$probeRow['created_at'], 0, 16)
                . ', runs on next check-in';
    } elseif ($probeRow && $probeRow['status'] === 'failed') {
        $state  = 'UNKNOWN';
        $detail = 'probe failed; re-run with --probe';
    } elseif (!$couldBeBad) {
        $state  = 'OK';
        $detail = $enrolled === null
            ? 'no enrolment date'
            : ($installed === null
                ? "enrolled {$enrolled}, before any published release"
                : "enrolled {$enrolled}, when v{$installed} was current (unaffected)");
    } else {
        $state  = 'AT RISK';
        $detail = "enrolled {$enrolled}, when v{$installed} was current"
                . " - that package shipped without etc/; unverified, run --probe";
    }

    $rows[] = [
        'id'              => (int)$fw['id'],
        'hostname'        => $fw['hostname'],
        'customer'        => $fw['customer_name'],
        'enrolled_at'     => $enrolled,
        'agent_version'   => $fw['agent_version'],
        'installed_from'  => $installed,
        'state'           => $state,
        'detail'          => $detail,
    ];
}

// --- probe ------------------------------------------------------------------
if ($probe) {
    $built = build_structured_command('agent_install_verify', []);
    if (!$built['ok']) {
        fwrite(STDERR, "Could not build the probe: {$built['error']}\n");
        exit(1);
    }

    $queued = 0;
    foreach ($rows as $row) {
        if (!$all && !in_array($row['state'], ['AT RISK', 'UNKNOWN'], true)) {
            continue;
        }
        $res = queue_firewall_command(
            $row['id'],
            $built['command'],
            'Verify agent installation (plugin hook and rc.d script)',
            ['action' => 'agent_install_verify', 'parameters' => [],
             'is_raw' => false, 'risk' => $built['risk']]
        );
        if ($res['ok']) {
            printf("queued probe on %s (command %d)\n", $res['hostname'], $res['command_id']);
            $queued++;
        } else {
            fwrite(STDERR, sprintf("could not queue on %s: %s\n", $row['hostname'], $res['error']));
        }
    }

    if ($queued === 0) {
        echo $all
            ? "No firewalls to probe.\n"
            : "Nothing at risk to probe. Use --probe --all to probe the whole fleet.\n";
    } else {
        printf("\n%d probe(s) queued. Agents pick these up on their next check-in (~2 min);\n", $queued);
        echo "re-run this script without --probe to read the results.\n";
    }
    exit(0);
}

// --- report -----------------------------------------------------------------
if ($asJson) {
    echo json_encode([
        'affected_releases' => $affected,
        'firewalls'         => $rows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(array_filter($rows, fn($r) => $r['state'] === 'BROKEN') ? 1 : 0);
}

printf("Agent releases packaged without etc/: %s\n",
       implode(', ', array_map(fn($v, $k) => "v{$k} ({$v})",
                               $affected, array_keys($affected))));
echo "A firewall is at risk when the release current on its enrolment date was one\n";
echo "of those. In-place upgrades never remove the files, so only the install matters.\n\n";

$w = max(8, ...array_map(fn($r) => strlen($r['hostname']), $rows ?: [['hostname' => '']]));
foreach ($rows as $row) {
    printf("  %-9s %-{$w}s  %s\n", $row['state'], $row['hostname'], $row['detail']);
}

$broken  = array_filter($rows, fn($r) => $r['state'] === 'BROKEN');
$atRisk  = array_filter($rows, fn($r) => $r['state'] === 'AT RISK');
$pending = array_filter($rows, fn($r) => $r['state'] === 'PENDING');

echo "\n";
printf("%d firewall(s): %d ok, %d broken, %d at risk, %d probe pending.\n",
       count($rows),
       count($rows) - count($broken) - count($atRisk) - count($pending),
       count($broken), count($atRisk), count($pending));

if ($atRisk) {
    echo "\nRun with --probe to ask the at-risk agents directly.\n";
}
if ($broken) {
    echo "\nTo repair a broken install, reinstall the agent - the rebuilt v1.5.6\n";
    echo "package now contains the etc/ tree:\n";
    echo "  fetch -o - https://opn.agit8or.net/downloads/plugins/install_opnmanager_agent.sh | sh\n";
    exit(1);
}

exit(0);
