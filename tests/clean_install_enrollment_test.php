<?php
/**
 * Clean manager to first firewall, following the README Quick Start.
 *
 * Before 3.81.0 a fresh clone had no agent package (downloads/ is gitignored)
 * but still printed the install command, so the first enrollment on every new
 * installation failed on the firewall with a 404. This walks the documented
 * path on a copy of the working tree with nothing but tracked files:
 *
 *   1. copy the tree as a clone would have it (git ls-files: no downloads/*.tar.gz)
 *   2. .env, master key, schema.sql into a brand-new database (README step 1)
 *   3. build and sign the agent exactly as .github/workflows/release.yml does,
 *      and serve those two files as the "GitHub release"
 *   4. Add Firewall before staging: no command, the reason, and what to run
 *   5. php scripts/stage_agent_package.php downloads and verifies the release
 *   6. Add Firewall shows the command; create the firewall and its key
 *   7. run that exact command as the firewall would (fetch from the manager over
 *      HTTP), in OPNMGR_VERIFY_ONLY mode because this host is not OPNsense
 *   8. Quick Enrollment (what the plugin posts) and the first check-in
 *
 * What is NOT real here: there is no OPNsense. Step 7 stops after the installer
 * has downloaded, checksum-verified, extracted and version-checked the package;
 * the file copies, configd and rc.d steps need a firewall. Step 8 posts the
 * same JSON the plugin's SettingsController and agent.sh send.
 *
 *   DB_NAME=opnsense_fw_test_clean php tests/clean_install_enrollment_test.php
 *
 * Needs sudo mysql (or OPNMGR_TEST_MYSQL, as in CI), git, rsync, curl and a free local port.
 */

require_once __DIR__ . '/bootstrap.php';

$dbName = getenv('DB_NAME');
if ($dbName !== 'opnsense_fw_test_clean') {
    fwrite(STDERR, "Run with DB_NAME=opnsense_fw_test_clean (this test drops and recreates that database).\n");
    exit(2);
}

$work = sys_get_temp_dir() . '/opnmgr-clean-' . bin2hex(random_bytes(4));
$mgr  = $work . '/manager';
$servers = [];
register_shutdown_function(function () use (&$servers, $work, $dbName) {
    foreach ($servers as $p) {
        @proc_terminate($p);
    }
    if (getenv('KEEP_CLEAN_INSTALL') !== '1') {
        exec('rm -rf ' . escapeshellarg($work));
        $mysql = getenv('OPNMGR_TEST_MYSQL') ?: 'sudo mysql';
        exec($mysql . ' -u root -e ' . escapeshellarg("DROP DATABASE IF EXISTS `{$dbName}`") . ' 2>/dev/null');
    }
});

/**
 * A clean environment for the copied manager's processes. This test process
 * loads this tree's .env (inc/env.php putenv()s it), and inheriting that would
 * hand the "clean" manager this installation's APP_URL and keys.
 */
function base_env(): array {
    return ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => getenv('HOME') ?: '/tmp', 'LANG' => 'C.UTF-8',
            'TMPDIR' => sys_get_temp_dir()];
}

function sh(string $cmd, ?string $cwd = null, array $env = []): array {
    $prefix = 'env -i ';
    foreach (base_env() as $k => $v) {
        if (!isset($env[$k])) { $prefix .= $k . '=' . escapeshellarg($v) . ' '; }
    }
    foreach ($env as $k => $v) {
        $prefix .= $k . '=' . escapeshellarg($v) . ' ';
    }
    $full = ($cwd ? "cd " . escapeshellarg($cwd) . " && " : "") . $prefix . "sh -c " . escapeshellarg($cmd) . " 2>&1";
    exec($full, $out, $rc);
    return ['rc' => $rc, 'out' => implode("\n", $out)];
}

function free_port(): int {
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
    fclose($s);
    return $port;
}

function serve(string $docroot, int $port, array $env): mixed {
    $p = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes, $docroot, array_merge(base_env(), $env));
    for ($i = 0; $i < 50; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $e, $es, 0.1);
        if ($c) { fclose($c); break; }
        usleep(100000);
    }
    return $p;
}

/** call_endpoint() against the copied manager rather than this tree. */
function mgr_call(string $mgr, string $script, array $opt, array $env): array {
    $payload = json_encode([
        'script'  => $mgr . '/' . $script,
        'server'  => ['REQUEST_METHOD' => $opt['method'] ?? 'POST', 'REQUEST_URI' => '/' . $script,
                      'SCRIPT_NAME' => '/' . $script, 'REMOTE_ADDR' => '192.0.2.10',
                      'HTTP_HOST' => 'manager.test', 'SERVER_NAME' => 'manager.test', 'HTTPS' => 'on'],
        'get' => [], 'post' => $opt['post'] ?? [], 'files' => [],
        'body' => isset($opt['body']) ? json_encode($opt['body']) : '',
        'session' => $opt['session'] ?? null,
    ]);
    $p = proc_open([PHP_BINARY, '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', $mgr . '/tests/runner.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $mgr, array_merge(base_env(), $env));
    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($p);
    $status = 200;
    if (preg_match('/^__STATUS__:(\d+)\n/', $out, $m)) {
        $status = (int)$m[1];
        $out = substr($out, strlen($m[0]));
    }
    return ['status' => $status, 'body' => $out, 'json' => json_decode($out, true), 'stderr' => $err];
}

function http_json(string $url, array $body): array {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 30,
        'header' => "Content-Type: application/json\r\n", 'content' => json_encode($body)]]);
    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int)$m[1]; }
    }
    return ['status' => $status, 'json' => json_decode((string)$raw, true), 'raw' => (string)$raw];
}

// ===========================================================================
T::group('1. A clone has nothing to enroll with');

mkdir($mgr, 0700, true);
$r = sh('git ls-files -co --exclude-standard -z | rsync -a --from0 --files-from=- ./ ' . escapeshellarg($mgr . '/'), TEST_ROOT);
T::eq(0, $r['rc'], 'tracked files copied as a clone would have them');
T::eq([], glob($mgr . '/downloads/plugins/*.tar.gz'), 'the clone contains no agent package');
T::ok(is_file($mgr . '/downloads/plugins/install_opnmanager_agent.sh'), 'but it does contain the installer');
// Stands in for `composer install --no-dev`, which would fetch the same tree.
symlink(TEST_ROOT . '/vendor', $mgr . '/vendor');

// ===========================================================================
T::group('2. README step 1: .env, keys, schema');

$dev = [];
foreach (preg_split('/\R/', (string)file_get_contents(TEST_ROOT . '/.env')) as $line) {
    if (preg_match('/^(DB_HOST|DB_USER|DB_PASS)=(.*)$/', $line, $m)) { $dev[$m[1]] = trim($m[2], "\"'"); }
}
$releaseKey = sodium_crypto_sign_keypair();
$releasePub = base64_encode(sodium_crypto_sign_publickey($releaseKey));
file_put_contents($mgr . '/.env', implode("\n", [
    'DB_HOST=' . ($dev['DB_HOST'] ?? 'localhost'), 'DB_USER=' . ($dev['DB_USER'] ?? 'opnsense_user'),
    'DB_PASS=' . ($dev['DB_PASS'] ?? ''), 'DB_NAME=' . $dbName, 'APP_URL=https://manager.test', 'APP_ENV=testing',
    // A release key made for this test, standing in for the project key pinned
    // in config/agent_release_keys.pub (whose secret is not on test hosts).
    'OPNMGR_AGENT_RELEASE_PUBKEYS=' . $releasePub, '',
]));
chmod($mgr . '/.env', 0640);
$env = ['DB_NAME' => $dbName];
T::eq(0, sh('php scripts/generate_master_key.php', $mgr, $env)['rc'], 'generate_master_key.php');
$r = sh('sh tests/lib/make_test_db.sh ' . escapeshellarg($dbName), $mgr,
        getenv('OPNMGR_TEST_MYSQL') ? ['OPNMGR_TEST_MYSQL' => getenv('OPNMGR_TEST_MYSQL')] : []);
T::eq(0, $r['rc'], 'schema.sql imported into a new database (and migrate is a no-op): ' . substr($r['out'], -80));

require_once TEST_ROOT . '/inc/bootstrap_agent.php';
T::eq($dbName, db()->query('SELECT DATABASE()')->fetchColumn(), 'assertions read the clean database');
T::eq(0, (int)db()->query('SELECT COUNT(*) FROM firewalls')->fetchColumn(), 'it has no firewalls');

db()->prepare('INSERT INTO users (username, password, role, is_active, totp_secret) VALUES (?,?,?,1,"enc:v1:test-fixture-enrolled")')
    ->execute(['__clean_admin__', password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT), 'admin']);
$csrf = str_repeat('c', 64);
$admin = ['user_id' => (int)db()->lastInsertId(), 'username' => '__clean_admin__', 'role' => 'admin', 'session_version' => 0,
          'login_time' => time(), 'last_activity' => time(), 'last_regenerated' => time(), 'csrf_token' => $csrf];

// ===========================================================================
T::group('3. The release workflow\'s output');

$rel = $work . '/release';
$r = sh('OUT_DIR=' . escapeshellarg($rel) . ' sh scripts/build_agent_package.sh', $mgr);
T::eq(0, $r['rc'], 'build_agent_package.sh builds the OPNsense agent from tracked source');
$pkgName = 'os-opnmanager-agent-' . AGENT_VERSION . '.tar.gz';
$r = sh('php scripts/sign_agent_release.php --sign ' . escapeshellarg("{$rel}/{$pkgName}"), $mgr,
        ['OPNMGR_AGENT_RELEASE_KEYS_FILE' => '/dev/null', 'OPNMGR_AGENT_RELEASE_PUBKEYS' => $releasePub,
         'OPNMGR_AGENT_RELEASE_KEY' => base64_encode(sodium_crypto_sign_secretkey($releaseKey))]);
T::eq(0, $r['rc'], 'sign_agent_release.php --sign: ' . substr($r['out'], 0, 60));
T::ok(is_file("{$rel}/os-opnmanager-agent-" . AGENT_VERSION . '.release.json'), 'a signed release record was written');

// ===========================================================================
T::group('4. Add Firewall before the package is staged');

$page = mgr_call($mgr, 'add_firewall_page.php', ['method' => 'GET', 'session' => $admin], $env);
T::eq(200, $page['status'], 'the page renders');
T::ok(str_contains($page['body'], 'No install command yet'), 'it says there is no install command yet');
T::ok(str_contains($page['body'], 'is not staged on this manager'), 'and why');
T::ok(str_contains($page['body'], 'php scripts/stage_agent_package.php'), 'and what to run');
T::ok(!str_contains($page['body'], 'OPNMGR_AGENT_SHA256='), 'and shows no command that would fail on the firewall');

// ===========================================================================
T::group('5. stage_agent_package.php');

$bad = $work . '/bad';
mkdir($bad);
copy("{$rel}/{$pkgName}", "{$bad}/{$pkgName}");
copy("{$rel}/os-opnmanager-agent-" . AGENT_VERSION . '.release.json', "{$bad}/os-opnmanager-agent-" . AGENT_VERSION . '.release.json');
$fh = fopen("{$bad}/{$pkgName}", 'r+'); fseek($fh, 400); fwrite($fh, 'X'); fclose($fh);
$r = sh('php scripts/stage_agent_package.php --import ' . escapeshellarg($bad), $mgr, $env);
T::ok($r['rc'] === 1 && str_contains($r['out'], 'package_corrupted'), 'a corrupted package is refused with its reason');
T::ok(str_contains($r['out'], 'What to do'), 'and the steps to fix it');
T::eq([], glob($mgr . '/downloads/plugins/*.tar.gz'), 'and nothing was staged');

$relPort = free_port();
$servers[] = serve($rel, $relPort, []);
$r = sh('php scripts/stage_agent_package.php', $mgr, $env + ['OPNMGR_AGENT_RELEASE_URL' => "http://127.0.0.1:{$relPort}"]);
T::eq(0, $r['rc'], 'the release is downloaded, verified and staged');
T::ok(str_contains($r['out'], 'READY: agent ' . AGENT_VERSION . ' staged (signed-release)'), 'it reports what it staged');
$stagedSha = hash_file('sha256', $mgr . '/downloads/plugins/' . $pkgName);
T::eq(hash_file('sha256', "{$rel}/{$pkgName}"), $stagedSha, 'the staged bytes are the release bytes');
$again = sh('php scripts/stage_agent_package.php', $mgr, $env);
T::ok($again['rc'] === 0 && str_contains($again['out'], 'already staged'), 'running it again changes nothing');

// ===========================================================================
T::group('6. Add Firewall: the command, the firewall and its key');

$page = mgr_call($mgr, 'add_firewall_page.php', ['method' => 'GET', 'session' => $admin], $env);
T::ok(!str_contains($page['body'], 'No install command yet'), 'the warning is gone');
$cmd = '';
if (preg_match('#<code class="d-block p-2 my-1 border rounded">(fetch -o - [^<]+)</code>#', $page['body'], $m)) {
    $cmd = html_entity_decode($m[1], ENT_QUOTES);
}
T::ok(str_contains($cmd, 'OPNMGR_AGENT_SHA256=' . $stagedSha), 'the command carries the staged checksum');
T::ok(str_starts_with($cmd, 'fetch -o - https://manager.test/downloads/plugins/install_opnmanager_agent.sh | env OPNMGR_BASE_URL=https://manager.test'),
      'and points at this manager: ' . substr($cmd, 0, 60));

$created = mgr_call($mgr, 'api/create_firewall_for_enrollment.php',
    ['session' => $admin, 'body' => ['hostname' => 'first-fw', 'customer_group' => 'First Customer', 'csrf' => $csrf]], $env);
T::ok(!empty($created['json']['success']), 'Create & Get Key creates the firewall: ' . substr($created['body'], 0, 80));
$fwId = (int)($created['json']['firewall_id'] ?? 0);
$key = mgr_call($mgr, 'api/enrollment_key.php', ['session' => $admin, 'body' => ['firewall_id' => $fwId, 'csrf' => $csrf]], $env);
T::ok(!empty($key['json']['enrollment_key']), 'and an enrollment key');
$keyData = json_decode((string)base64_decode((string)($key['json']['enrollment_key'] ?? ''), true), true) ?: [];
T::eq('https://manager.test', rtrim((string)($keyData['server_url'] ?? ''), '/'), 'the key names this manager');

// ===========================================================================
T::group('7. The firewall runs the command (verify-only: this host is not OPNsense)');

$mgrPort = free_port();
$servers[] = serve($mgr, $mgrPort, $env);
$bin = $work . '/bin';
mkdir($bin);
// FreeBSD fetch(1), as far as the installer uses it, over the local server.
file_put_contents($bin . '/fetch', "#!/bin/sh\nout=-\nwhile [ \$# -gt 0 ]; do case \"\$1\" in -o) out=\"\$2\"; shift 2;; -q) shift;; *) url=\"\$1\"; shift;; esac; done\n"
    . "url=\$(echo \"\$url\" | sed 's#^https://manager.test#http://127.0.0.1:{$mgrPort}#')\n"
    . "if [ \"\$out\" = - ]; then exec curl -fsS \"\$url\"; else exec curl -fsS -o \"\$out\" \"\$url\"; fi\n");
chmod($bin . '/fetch', 0755);
$fwEnv = ['PATH' => $bin . ':' . getenv('PATH'), 'OPNMGR_VERIFY_ONLY' => '1', 'TMPDIR' => $work];

$r = sh($cmd, $work, $fwEnv);
T::eq(0, $r["rc"], "the displayed command succeeds");
if ($r["rc"] !== 0) { echo $r["out"], "\n"; }
T::ok(str_contains($r['out'], 'Package checksum verified: ' . $stagedSha), 'the installer verified the checksum the manager showed');
T::ok(str_contains($r['out'], 'Verified agent ' . AGENT_VERSION), 'and the agent version inside the package');
T::eq([], glob($work . '/opnmanager-agent.*'), 'and cleaned up after itself');

$tampered = preg_replace('/OPNMGR_AGENT_SHA256=[0-9a-f]{64}/', 'OPNMGR_AGENT_SHA256=' . str_repeat('0', 64), $cmd);
$r = sh($tampered, $work, $fwEnv);
T::ok($r['rc'] !== 0 && str_contains($r['out'], 'does not match the checksum'), 'a package that does not match is refused');
T::ok(str_contains($r['out'], 'Nothing was installed'), 'before anything is installed');

// ===========================================================================
T::group('8. Quick Enrollment and the first check-in');

$hw = bin2hex(random_bytes(16));
$base = "http://127.0.0.1:{$mgrPort}";
$en = http_json($base . '/agent_enroll.php', ['token' => (string)($keyData['token'] ?? ''), 'hardware_id' => $hw,
                                              'hostname' => 'first-fw.customer.lan', 'agent_version' => AGENT_VERSION]);
T::ok($en['status'] === 200 && !empty($en['json']['success']), 'the plugin\'s enrollment call succeeds: ' . substr($en['raw'], 0, 80));
T::eq($fwId, (int)($en['json']['firewall_id'] ?? 0), 'and binds to the firewall created in step 6');

$ci = http_json($base . '/agent_checkin.php', ['hardware_id' => $hw, 'agent_version' => AGENT_VERSION,
    'hostname' => 'first-fw.customer.lan', 'opnsense_version' => '26.7.8', 'wan_ip' => '198.51.100.40', 'uptime' => '1 min']);
T::eq(200, $ci['status'], 'the first check-in is accepted: ' . substr($ci['raw'], 0, 100));
T::ok(!empty($ci['json']['agent_api_key'] ?? $ci['json']['credentials']['api_key'] ?? null) || !empty($ci['json']['success']),
      'and answered');

$row = db()->query("SELECT status, hardware_id, agent_version FROM firewalls WHERE id = {$fwId}")->fetch(PDO::FETCH_ASSOC);
T::eq('online', $row['status'] ?? null, 'the firewall is online');
T::eq($hw, $row['hardware_id'] ?? null, 'with the hardware id the agent presented');
T::eq(AGENT_VERSION, $row['agent_version'] ?? null, 'running the staged agent version');

$r = sh('php scripts/stage_agent_package.php --status', $mgr, $env);
T::ok($r['rc'] === 0 && hash_file('sha256', $mgr . '/downloads/plugins/' . $pkgName) === $stagedSha,
      'the staged package is still the same bytes afterwards');

exit(T::summary());
