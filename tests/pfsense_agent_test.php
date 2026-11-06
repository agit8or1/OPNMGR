<?php
/**
 * The pfSense agent, run rather than read.
 *
 * Same principle as agent_request_signing_test.php: the agent's own shell
 * functions are extracted and executed, and their output goes through the
 * server's rules. No pfSense device is involved, so this proves the protocol
 * and the shell, not behaviour on pfSense itself (see docs/PLATFORMS.md for
 * the live-validation gap).
 *
 * No database is used.
 */

$passed = 0;
$failed = 0;
function check(string $what, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; return; }
    $failed++;
    echo "FAIL: {$what}\n";
    if ($detail !== '') { echo "      {$detail}\n"; }
}

$root  = dirname(__DIR__);
$agent = $root . '/agent/pfsense/opnmgr_agent.sh';
$src   = (string)file_get_contents($agent);
$php   = PHP_BINARY;

check('the agent never uses configctl', strpos($src, 'configctl') === false);
check('the agent never runs python', preg_match('/python3?\s|\/python/', $src) !== 1);
check('the agent never edits config.xml', !preg_match('/sed[^\n]*config\.xml/', $src));
check('every POST goes through post_to_manager', substr_count($src, '-X POST') === 2,
    'one in post_to_manager, one in enrollment (which has no credentials yet)');
check('the agent shares the OPNsense agent credential file names',
    strpos($src, '/usr/local/etc/opnmanager_api_key') !== false
    && strpos($src, '/usr/local/etc/opnmanager_hardware_id') !== false);

$dir = sys_get_temp_dir() . '/opnmgr-pfagent-test-' . getmypid();
@mkdir($dir . '/bin', 0700, true);
$helpers = $dir . '/helpers.sh';
shell_exec(sprintf(
    "sed -n '/^store_credential()/,/^}/p;/^signing_headers()/,/^}/p;/^post_to_manager()/,/^}/p;/^json_get()/,/^}/p;/^run_queued_commands()/,/^}/p' %s > %s",
    escapeshellarg($agent), escapeshellarg($helpers)));
check('helpers extracted', filesize($helpers) > 1000);

// curl stub: record the body and headers it was given, answer with $RESP.
file_put_contents($dir . '/bin/curl', "#!/bin/sh\nprintf '%s\\n' \"\$@\" > {$dir}/curl.args\n"
    . "while [ \$# -gt 0 ]; do [ \"\$1\" = \"-d\" ] && { shift; printf '%s' \"\$1\" >> {$dir}/curl.bodies; printf '\\n' >> {$dir}/curl.bodies; }; shift; done\n"
    . "printf '%s' '{\"success\":true}'\n");
chmod($dir . '/bin/curl', 0700);

$secret = 'pf-suite-' . bin2hex(random_bytes(6));
$prelude = "log_message() { :; }\nPHP=" . escapeshellarg($php) . "\n"
    . "API_KEY_FILE={$dir}/key\nAPI_SECRET_FILE={$dir}/secret\nHARDWARE_ID=hw-pf-test\n"
    . "SERVER_URL=https://manager.test\nCURL=\"{$dir}/bin/curl -s\"\n. {$helpers}\n";
$run = function (string $body) use ($dir, $prelude): string {
    file_put_contents($dir . '/run.sh', $prelude . $body);
    return (string)shell_exec('PATH=/usr/bin:/bin sh ' . escapeshellarg($dir . '/run.sh') . ' 2>&1');
};

// --- credential adoption via json_get --------------------------------------
$resp = '{"success":true,"agent_credentials":{"api_key":"KEY-PF","api_secret":"' . $secret . '"}}';
// The exact adoption lines from the shipped agent, run against a real
// response shape (agent_credentials_payload() nests the values).
preg_match_all('/^\s*(store_credential "\$API_(?:KEY|SECRET)_FILE".*)$/m', $src, $adopt);
check('the agent has both adoption lines', count($adopt[1]) === 2);
$out = $run('resp=' . escapeshellarg($resp) . "\n" . implode("\n", $adopt[1]) . "\n"
    . 'printf "%s|%s" "$(cat $API_KEY_FILE)" "$(cat $API_SECRET_FILE)"');
check('credentials adopted from the check-in response', trim($out) === 'KEY-PF|' . $secret, $out);
check('the secret file is not group/world readable', (fileperms($dir . '/secret') & 0077) === 0);
check('json_get reads nested keys', trim($run('json_get ' . escapeshellarg('{"a":{"b":"c"}}') . ' a.b')) === 'c');
check('json_get renders booleans', trim($run('json_get ' . escapeshellarg('{"x":true}') . ' x')) === 'true');

// --- signature accepted by the server's canonical form ---------------------
$body = '{"hardware_id":"hw-pf-test","agent_version":"1.0.0","platform":{"family":"pfsense"}}';
$sig = $run('signing_headers POST /agent_checkin.php ' . escapeshellarg($body));
$h = [];
foreach (explode("\n", $sig) as $line) {
    if (preg_match('/^X-OPNMGR-(\w+):\s*(.+)$/', trim($line), $m)) { $h[strtolower($m[1])] = $m[2]; }
}
check('three signing headers', count($h) === 3, $sig);
if (count($h) === 3) {
    $expected = hash_hmac('sha256', implode("\n", ['POST', '/agent_checkin.php', $h['timestamp'], $h['nonce'], hash('sha256', $body)]), $secret);
    check('pfSense agent signature matches the server computation', hash_equals($expected, $h['signature']));
    check('nonce satisfies the server format', preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $h['nonce']) === 1);
}

// --- post_to_manager carries key and signature -----------------------------
@unlink($dir . '/curl.args');
$run('post_to_manager /agent_checkin.php ' . escapeshellarg($body) . ' >/dev/null');
$args = (string)@file_get_contents($dir . '/curl.args');
check('post_to_manager sends the API key header', strpos($args, 'X-OPNMGR-API-Key: KEY-PF') !== false);
check('post_to_manager sends the signature header', strpos($args, 'X-OPNMGR-Signature:') !== false);
check('post_to_manager targets the configured server path', strpos($args, 'https://manager.test/agent_checkin.php') !== false);

// --- queued command round trip ---------------------------------------------
@unlink($dir . '/curl.bodies');
file_put_contents($dir . '/bin/timeout', "#!/bin/sh\nshift\nexec \"\$@\"\n");
chmod($dir . '/bin/timeout', 0700);
$cmdText = "echo hello-from-pfsense; echo second line";
$queued = json_encode(['success' => true, 'queued_commands' => [['id' => 77, 'command' => $cmdText]]]);
file_put_contents($dir . '/run.sh', $prelude . 'run_queued_commands ' . escapeshellarg($queued));
shell_exec('PATH=' . escapeshellarg($dir . '/bin') . ':/usr/bin:/bin sh ' . escapeshellarg($dir . '/run.sh') . ' 2>&1');
$bodies = array_values(array_filter(explode("\n", (string)@file_get_contents($dir . '/curl.bodies'))));
$report = $bodies ? json_decode(end($bodies), true) : null;
check('a command result was reported', is_array($report) && ($report['command_id'] ?? 0) === 77, implode("\n", $bodies));
if (is_array($report)) {
    $decoded = base64_decode((string)$report['result'], true);
    check('the result is base64 the server can decode', $decoded !== false
        && strpos($decoded, 'hello-from-pfsense') !== false && strpos($decoded, 'second line') !== false, (string)$decoded);
    check('status completed', ($report['status'] ?? '') === 'completed');
}

// --- installer refuses anything that is not pfSense -------------------------
file_put_contents($dir . '/bin/id', "#!/bin/sh\necho 0\n");
chmod($dir . '/bin/id', 0700);
$inst = (string)shell_exec('PATH=' . escapeshellarg($dir . '/bin') . ':/usr/bin:/bin OPNMGR_BASE_URL=https://manager.test sh '
    . escapeshellarg($root . '/agent/pfsense/install_opnmgr_agent_pfsense.sh') . ' 2>&1; echo "rc=$?"');
check('installer refuses a host without pfSense globals', strpos($inst, 'does not look like pfSense') !== false
    && strpos($inst, 'rc=1') !== false, $inst);
$inst = (string)shell_exec('PATH=' . escapeshellarg($dir . '/bin') . ':/usr/bin:/bin OPNMGR_BASE_URL=ftp://x sh '
    . escapeshellarg($root . '/agent/pfsense/install_opnmgr_agent_pfsense.sh') . ' 2>&1');
check('installer refuses a non-http manager URL', strpos($inst, 'must start with https://') !== false, $inst);

// --- collector on a host that is not pfSense --------------------------------
$json = (string)shell_exec(escapeshellarg($php) . ' -f ' . escapeshellarg($root . '/agent/pfsense/opnmgr_collect.php') . ' -- --platform-only 2>/dev/null');
$c = json_decode($json, true);
check('collector emits JSON with a platform block', is_array($c['platform'] ?? null), substr($json, 0, 200));
check('collector reports the pfSense marker false off-pfSense', ($c['platform']['markers']['pfsense_globals'] ?? null) === false);

require_once $root . '/inc/platform/platform.php';
$d = platform_detect(['agent_platform' => $c['platform'] ?? []]);
check('the server does not accept a self-declared pfSense with no markers as high confidence',
    $d['confidence'] !== 'high', json_encode($d));

foreach (glob($dir . '/bin/*') ?: [] as $f) { @unlink($f); }
@rmdir($dir . '/bin');
foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($dir);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
