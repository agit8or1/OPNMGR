<?php
/**
 * No installation-specific host, URL or address may be compiled into the code.
 *
 * More than thirty tracked files carried the maintainer's own hostname and
 * public IP as literals: the agent download URL, the enrolment script's SSH
 * key, the nginx certificate paths, the "allow SSH from the manager" firewall
 * rule. Every one of those made a self-hosted deployment point its customers'
 * firewalls at a server its operator does not control, and several were load
 * bearing rather than cosmetic. They are all resolved through
 * inc/server_identity.php now.
 *
 * This test fails if any literal host-shaped value reappears, and checks that
 * the resolver itself behaves: order of precedence, bare hosts, junk, and the
 * "nothing configured" case that callers must handle.
 */

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL: {$what}\n";
    if ($detail !== '') {
        echo "      {$detail}\n";
    }
}

$root = dirname(__DIR__);

// ---------------------------------------------------------------------------
// 1. No installation-specific literals anywhere in tracked files.
// ---------------------------------------------------------------------------

// The values this repository actually carried. Anchored to the specific host
// and addresses rather than a general pattern, so ordinary example hosts and
// the RFC 5737 documentation ranges used in docs and fixtures still pass.
$banned = [
    'agit8or.net'       => "the maintainer's own domain",
    '184.175.206.229'   => "the maintainer's manager IP",
    '184.175.230.179'   => "a real firewall's WAN IP",
    '204.1.21.52'       => "a real firewall's WAN IP",
];

exec('cd ' . escapeshellarg($root) . ' && git ls-files 2>/dev/null', $files, $status);
check('git ls-files works', $status === 0 && count($files) > 100, 'got ' . count($files) . ' files');

$selfPath = 'tests/' . basename(__FILE__);
$hits = [];
foreach ($files as $file) {
    if ($file === $selfPath) {
        continue; // this file names the banned values on purpose
    }
    $path = $root . '/' . $file;
    if (!is_file($path) || filesize($path) > 2_000_000) {
        continue;
    }
    $content = @file_get_contents($path);
    if ($content === false || strpos($content, "\0") !== false) {
        continue; // binary
    }
    foreach ($banned as $needle => $why) {
        if (strpos($content, $needle) !== false) {
            $hits[] = "{$file} contains {$needle} ({$why})";
        }
    }
}
check('no installation-specific hosts or IPs in tracked files', $hits === [], implode("\n      ", $hits));

// Guard against the guard being vacuous: it must actually be reading content.
$sentinel = $root . '/inc/server_identity.php';
check('the scan reads real file content', is_file($sentinel)
    && strpos((string) file_get_contents($sentinel), 'opnmgr_server_url') !== false);

// ---------------------------------------------------------------------------
// 2. The resolver behaves.
// ---------------------------------------------------------------------------

require_once $root . '/inc/server_identity.php';

check('opnmgr_normalise_server_url adds https to a bare host',
    opnmgr_normalise_server_url('manager.example') === 'https://manager.example');
check('opnmgr_normalise_server_url keeps an explicit scheme',
    opnmgr_normalise_server_url('http://manager.example') === 'http://manager.example');
check('opnmgr_normalise_server_url strips a trailing slash',
    opnmgr_normalise_server_url('https://manager.example/') === 'https://manager.example');
check('opnmgr_normalise_server_url keeps a port',
    opnmgr_normalise_server_url('manager.example:8443') === 'https://manager.example:8443');
check('opnmgr_normalise_server_url rejects empty', opnmgr_normalise_server_url('') === '');
check('opnmgr_normalise_server_url rejects whitespace', opnmgr_normalise_server_url('   ') === '');
check('opnmgr_normalise_server_url rejects a scheme with no host',
    opnmgr_normalise_server_url('https://') === '');

// instance.json must not ship a real host as a fallback.
$instance = $root . '/config/instance.json';
if (is_file($instance)) {
    $decoded = json_decode((string) file_get_contents($instance), true);
    check('config/instance.json is valid JSON', is_array($decoded));
    check('config/instance.json ships no main_server',
        ($decoded['main_server'] ?? '') === '',
        'main_server = ' . var_export($decoded['main_server'] ?? null, true));
}

// ---------------------------------------------------------------------------
// 3. The enrolment script carries a placeholder, never a key.
// ---------------------------------------------------------------------------

$enroll = $root . '/simple_enroll.sh';
if (is_file($enroll)) {
    $body = (string) file_get_contents($enroll);
    check('simple_enroll.sh uses an SSH key placeholder',
        strpos($body, '__SERVER_SSH_KEY__') !== false);
    check('simple_enroll.sh contains no literal SSH public key',
        !preg_match('/ssh-(ed25519|rsa|ecdsa)\s+AAAA[A-Za-z0-9+\/=]{20,}/', $body));
    check('simple_enroll.sh uses a manager IP placeholder',
        strpos($body, '__MGMT_SERVER_IP__') !== false);
}

// And the endpoint that serves it substitutes both.
$serve = $root . '/api/get_enroll_script.php';
if (is_file($serve)) {
    $body = (string) file_get_contents($serve);
    check('get_enroll_script.php substitutes the SSH key',
        strpos($body, '__SERVER_SSH_KEY__') !== false);
    check('get_enroll_script.php substitutes the manager IP',
        strpos($body, '__MGMT_SERVER_IP__') !== false);
}

// ---------------------------------------------------------------------------
// 4. The agent installer takes its base URL from the caller.
// ---------------------------------------------------------------------------

$installer = $root . '/downloads/plugins/install_opnmanager_agent.sh';
if (is_file($installer)) {
    $body = (string) file_get_contents($installer);
    check('the agent installer requires OPNMGR_BASE_URL',
        strpos($body, 'OPNMGR_BASE_URL') !== false);
    check('the agent installer builds PLUGIN_URL from that base',
        strpos($body, '${OPNMGR_BASE_URL}/downloads/plugins/') !== false);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
