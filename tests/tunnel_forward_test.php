<?php
/**
 * A tunnel that forwards nothing must not be reported as established.
 *
 * Run with: php tests/tunnel_forward_test.php
 *
 * Reported as "Proxy Error: Unable to connect to tunnel ... Failed to connect to
 * 127.0.0.1 port 8101". The ssh process was running, authenticated, and
 * forwarding nothing.
 *
 * ssh treats a failed port bind - the port still held by a previous tunnel,
 * most often - as a warning. Without ExitOnForwardFailure it stays connected and
 * backgrounds itself under -f, so exec() sees return code 0, the session is
 * recorded active, and the proxy meets a closed port. Every layer above believed
 * the tunnel existed because the only thing anyone checked was ssh's exit status.
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
$src  = (string) @file_get_contents($root . '/scripts/manage_ssh_tunnel.php');

check('scripts/manage_ssh_tunnel.php is readable', $src !== '');

check('a failed forward fails the ssh command',
    str_contains($src, 'ExitOnForwardFailure=yes'),
    'without it a bound-port collision is a warning and ssh exits 0');

// The option is only meaningful on the command that creates the forward.
if (preg_match('/"timeout \d+ ssh[^"]*-L 127\.0\.0\.1:%s[^"]*"/', $src, $m)) {
    check('it is set on the tunnel command itself',
        str_contains($m[0], 'ExitOnForwardFailure=yes'));
    check('the forward still binds to loopback only',
        str_contains($m[0], '-L 127.0.0.1:'),
        'a tunnel reachable off-box would bypass the proxy entirely');
    check('the command still backgrounds itself', str_contains($m[0], '-N -f'));
} else {
    check('the tunnel command was found', false);
}

// Exit status is now load-bearing, so it must still be what is tested.
check('the return code decides success',
    (bool) preg_match('/exec\(\$ssh_cmd, \$output, \$return_code\);\s*\n\s*if \(\$return_code === 0\)/', $src));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
