<?php
/**
 * First contact with a firewall must not be trusted blindly.
 *
 * Run with: php tests/ssh_host_key_test.php
 *
 * Every SSH caller passed StrictHostKeyChecking=no. That silently accepts an
 * unknown host, and this manager holds root keys to every firewall in the fleet,
 * so first contact is exactly the moment verification matters. It also handles a
 * changed key in a way that is almost undiagnosable: ssh connects but refuses
 * port forwarding, which surfaced as "Proxy Error: Failed to connect to
 * 127.0.0.1:8101" with no mention of host keys anywhere.
 *
 * accept-new keeps first contact working, refuses a changed key outright, and
 * where keys have been pinned in advance by scripts/pin_host_keys.php - which
 * reads them from the firewall over the agent's signed channel, not over SSH -
 * even first contact is verified.
 */

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) { $passed++; return; }
    $failed++;
    echo "FAIL: {$what}\n";
    if ($detail !== '') { echo "      {$detail}\n"; }
}

$root = dirname(__DIR__);

/** Every tracked PHP file, comments stripped. */
function code_files(string $root): array
{
    $out = [];
    exec('cd ' . escapeshellarg($root) . ' && git ls-files "*.php" 2>/dev/null', $files);
    foreach ($files as $rel) {
        // inc/version.php is the in-app changelog: it quotes the very strings
        // these assertions forbid, while calling nothing. schema_columns_test.php
        // excludes it for the same reason.
        if (str_starts_with($rel, 'tests/') || $rel === 'inc/version.php') { continue; }
        $src = (string) @file_get_contents($root . '/' . $rel);
        if ($src === '') { continue; }
        $code = implode("\n", array_filter(explode("\n", $src), function (string $l): bool {
            $t = ltrim($l);
            return $t !== '' && !str_starts_with($t, '//') && !str_starts_with($t, '*')
                && !str_starts_with($t, '/*') && !str_starts_with($t, '#');
        }));
        $out[$rel] = $code;
    }
    return $out;
}

$files = code_files($root);
check('the tracked sources were listed', count($files) > 50, count($files) . ' files');

$offenders = [];
$adopters  = [];
foreach ($files as $rel => $code) {
    if (str_contains($code, 'StrictHostKeyChecking=no')) {
        $offenders[] = $rel;
    }
    if (str_contains($code, 'StrictHostKeyChecking=accept-new')) {
        $adopters[] = $rel;
    }
}

check('no caller disables host key checking', $offenders === [],
    implode(', ', $offenders));
check('callers use accept-new', count($adopters) >= 10,
    count($adopters) . ' file(s)');

// A shared known_hosts, or each caller trusts a different history.
$missingKh = [];
foreach ($files as $rel => $code) {
    if (str_contains($code, 'StrictHostKeyChecking=accept-new')
        && !str_contains($code, 'UserKnownHostsFile')) {
        $missingKh[] = $rel;
    }
}
check('every caller points at the managed known_hosts', $missingKh === [],
    implode(', ', $missingKh));

// The helper and the pinning tool.
$opts = (string) @file_get_contents($root . '/inc/ssh_options.php');
check('inc/ssh_options.php exists', $opts !== '');
check('it defines one known_hosts location', str_contains($opts, "define('OPNMGR_KNOWN_HOSTS'"));
check('it can report whether a host is pinned', str_contains($opts, 'function opnmgr_host_key_pinned'));

$pin = (string) @file_get_contents($root . '/scripts/pin_host_keys.php');
check('scripts/pin_host_keys.php exists', $pin !== '');
check('it verifies over the agent channel, not over SSH',
    str_contains($pin, 'agent_host_key_fingerprints') && str_contains($pin, 'firewall_commands'),
    'verifying SSH with SSH proves nothing');
check('it writes nothing the firewall does not report',
    (bool) preg_match('/in_array\(\$fp, \$verified, true\)/', $pin));
check('it reports by default and writes only with --apply',
    str_contains($pin, "\$apply = isset(\$opts['apply'])") && str_contains($pin, 'Re-run with --apply'));
check('it refuses placeholder addresses',
    str_contains($pin, "'0.0.0.0'"),
    'scanning 0.0.0.0 reaches the manager, not the firewall');
check('a mismatch is an error exit', str_contains($pin, 'exit(2)'));

// The reuse check that never matched.
$tun = (string) @file_get_contents($root . '/scripts/manage_ssh_tunnel.php');
check('the live-tunnel check matches the command actually run',
    str_contains($tun, 'tunnel_ssh_pids((int) $port)'),
    "it looked for '-L {port}:' while the tunnel binds '-L 127.0.0.1:{port}:'");
check('the loose ps pattern is gone',
    !str_contains($tun, "grep 'ssh.*-L {\$port}:'"));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
