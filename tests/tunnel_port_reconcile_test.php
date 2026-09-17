<?php
/**
 * The session table and the machine must agree about which ports are in use.
 *
 * Run with: php tests/tunnel_port_reconcile_test.php
 *
 * The allocator logged "Port pair 8100/8101 shows as free in DB but one is in
 * use on system" and skipped to the next pair. Skipping is right, but it only
 * treats the symptom: nothing reconciled the two views, so the range leaked a
 * pair at a time until it would be exhausted.
 *
 * Drift ran in both directions. A session past expires_at stayed 'active'
 * forever - the status enum has an 'expired' value that no code ever set - so an
 * abandoned session reserved its port permanently. A session whose ssh process
 * had died stayed 'active' too, reserving a port nothing was listening on. And
 * an ssh process outliving its session held a port the table called free, which
 * is the case that produced the warning.
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
$src  = (string) @file_get_contents($root . '/scripts/manage_ssh_access.php');

// Code only: the fix documents what it replaced, and a "must not contain"
// assertion would otherwise fail on the explanation rather than on the code.
$code = implode("\n", array_filter(explode("\n", $src), function (string $line): bool {
    $t = ltrim($line);
    return $t !== '' && !str_starts_with($t, '//') && !str_starts_with($t, '*')
        && !str_starts_with($t, '/*') && !str_starts_with($t, '#');
}));

check('scripts/manage_ssh_access.php is readable', $src !== '');

check('a reconciler exists', str_contains($src, 'function reconcile_tunnel_sessions'));
check('allocation reconciles first',
    (bool) preg_match('/function find_available_tunnel_port\(\)\s*\{[\s\S]{0,400}?reconcile_tunnel_sessions\(\);/', $src),
    'otherwise every stale row removes a port pair from circulation for good');

check('a session past its expiry is expired',
    (bool) preg_match("/SET status = 'expired'/", $src),
    "the enum value existed and nothing ever set it");
check('a session whose tunnel is gone is closed',
    str_contains($src, "'tunnel process gone'"));
check('a tunnel outliving its session is killed',
    (bool) preg_match('/foreach \(tunnel_ssh_pids\(\$port\) as \$pid\)[\s\S]{0,120}posix_kill/', $src));

// The process match decides what gets killed, so it has to be exact.
check('process matching does not use pgrep -f',
    !str_contains($code, 'pgrep -f'),
    'its pattern appears in the command line of the shell running it, so a port '
    . 'with no tunnel reports a match');
check('it requires the executable to be ssh',
    str_contains($src, "\$comm !== 'ssh'"));
check('it excludes its own process', str_contains($src, 'getmypid()'));

// Killing is bounded to the tunnel range.
check('orphan killing is bounded to the tunnel port range',
    (bool) preg_match('/for \(\$port = TUNNEL_PORT_MIN; \$port <= TUNNEL_PORT_MAX/', $src));
check('a port claimed by a live session is left alone',
    (bool) preg_match('/if \(isset\(\$liveSessionPorts\[\$port\]\)\) \{\s*continue;/', $src));

// A database failure must not take the allocator down with it.
check('a failed read degrades instead of throwing',
    (bool) preg_match('/catch \(Throwable \$e\)[\s\S]{0,220}return \[\'expired\' => 0/', $src));

// --- the reconciler must work where it actually runs -------------------------
//
// It kills processes with posix_kill(). SIGTERM is a pcntl constant and pcntl is
// not loaded under PHP-FPM, so the first web request that started a tunnel hit
// "Uncaught Error: Undefined constant SIGTERM" and returned 500. It had only
// been exercised from the CLI, where pcntl is present and the constant resolves.
// manage_ssh_tunnel.php already used the numeric signal for this reason.

check('no bare SIGTERM constant in a web-reachable path',
    !preg_match('/posix_kill\([^,]+,\s*SIGTERM\s*\)/', $src),
    'pcntl is CLI-only here; the constant is a fatal under PHP-FPM');
check('the numeric signal is used instead',
    (bool) preg_match('/posix_kill\([^,]+,\s*15\)/', $src));

$tunnel = (string) @file_get_contents($root . '/scripts/manage_ssh_tunnel.php');
check('the existing convention is unchanged',
    (bool) preg_match('/posix_kill\(intval\(\$pid\), 15\)/', $tunnel),
    'this file already knew, and is where the convention came from');

$proxy = (string) @file_get_contents($root . '/firewall_proxy_ondemand.php');
check('the tunnel request declares it wants JSON',
    str_contains($proxy, "'Accept': 'application/json'"),
    'without it requireLogin() answers an expired session with a 302 to login');
check('a 401 is reported as an expired session',
    str_contains($proxy, 'session has expired'),
    '"Network error" was shown for an expired session and a server fatal alike');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
