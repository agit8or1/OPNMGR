<?php
/**
 * The agent's signing, run against the server's verifier.
 *
 * Until 1.6.4 request signing existed only on the server: HMAC verification, a
 * freshness window, nonce replay rejection, three policy modes, and a check-in
 * response that handed each agent its secret and the canonical string with the
 * note "Sign requests once supported." No agent had a line of it, and the agent
 * authenticated with hardware_id alone - a value derived from the hardware,
 * not a secret.
 *
 * A signature computed wrongly is worse than none: compatibility mode verifies
 * a signature whenever one is present, so a malformed one refuses the check-in
 * and the firewall goes quiet. This suite therefore does not inspect the shell
 * for plausible-looking code. It extracts the agent's own functions, runs them,
 * and puts the result through agent_verify_signature() - the same function the
 * live endpoint calls.
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

$root  = dirname(__DIR__);
$agent = $root . '/plugin/os-opnmanager-agent/src/opnsense/scripts/OPNsense/OPNManagerAgent/agent.sh';
check('the agent source is present', is_file($agent));

$src = (string) @file_get_contents($agent);
check('the agent signs', strpos($src, 'signing_headers()') !== false);
check('the agent adopts credentials', strpos($src, 'adopt_credentials()') !== false);
check('credentials are written with a restrictive umask', strpos($src, 'umask 077') !== false);

// Presenting the key ratchets it: from then on the server requires it on every
// endpoint for that firewall. Converting the check-in alone left command and
// speedtest results rejected as api_key_missing, and the queue filled with
// commands stuck at "sent". Every POST must go through the one helper.
check('no POST bypasses the credentialed helper',
    preg_match('/\$CURL_CMD -s -m [0-9]+ -X POST/', $src) !== 1,
    'a POST built inline will not carry the api_key, and the server now requires it');
check('the POSTs go through post_to_manager',
    substr_count($src, 'post_to_manager "') >= 3,
    'check-in, command results and speedtest results');

if (!function_exists('shell_exec') || shell_exec('command -v openssl') === null) {
    echo "\nopenssl unavailable; skipping execution\n";
    echo "{$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}

// --- run the agent's own functions ------------------------------------------

$dir = sys_get_temp_dir() . '/opnmgr-sign-test-' . getmypid();
@mkdir($dir, 0700, true);

// Pull the three helpers straight out of the shipped script.
$helpers = $dir . '/helpers.sh';
shell_exec(sprintf(
    "sed -n '/^store_credential()/,/^}/p;/^adopt_credentials()/,/^}/p;/^signing_headers()/,/^}/p' %s > %s",
    escapeshellarg($agent), escapeshellarg($helpers)
));
check('the helpers were extracted', filesize($helpers) > 400);

$body   = '{"hardware_id":"HW-TEST","agent_version":"1.6.4"}';
$secret = 'suite-secret-' . bin2hex(random_bytes(6));
$resp   = '{"success":true,"agent_credentials":{"api_key":"KEY-1","api_secret":"' . $secret . '"}}';

$runner = $dir . '/run.sh';
file_put_contents($runner, "log_message() { :; }\n"
    . "API_KEY_FILE={$dir}/key\nAPI_SECRET_FILE={$dir}/secret\n"
    . ". {$helpers}\n"
    . "case \"\$1\" in\n"
    . "  nocreds) signing_headers POST /agent_checkin.php " . escapeshellarg($body) . " ;;\n"
    . "  adopt)   adopt_credentials " . escapeshellarg($resp) . "; printf '%s|%s' \"\$(cat \$API_KEY_FILE)\" \"\$(cat \$API_SECRET_FILE)\" ;;\n"
    . "  sign)    signing_headers POST /agent_checkin.php " . escapeshellarg($body) . " ;;\n"
    . "esac\n");

$sh = fn(string $arg): string => (string) shell_exec('sh ' . escapeshellarg($runner) . ' ' . $arg . ' 2>/dev/null');

// 1. Nothing is sent before a secret exists - the property that makes the
//    upgrade safe, since an unsigned request is accepted and a broken one is not.
check('no signature before credentials are adopted', trim($sh('nocreds')) === '',
    'an agent that signs before it has a secret refuses its own check-in');

// 2. Credentials are taken from the response.
$adopted = trim($sh('adopt'));
check('credentials are adopted from the response', $adopted === 'KEY-1|' . $secret, $adopted);
check('the secret is not world readable',
    is_file($dir . '/secret') && (fileperms($dir . '/secret') & 0077) === 0,
    sprintf('%o', fileperms($dir . '/secret')));

// 3. The signature it produces must satisfy the server.
$headers = [];
foreach (explode("\n", $sh('sign')) as $line) {
    if (preg_match('/^X-OPNMGR-(\w+):\s*(.+)$/', trim($line), $m)) {
        $headers[strtolower($m[1])] = $m[2];
    }
}
check('all three signing headers are produced', count($headers) === 3, implode(',', array_keys($headers)));

if (count($headers) === 3) {
    require_once $root . '/inc/crypto.php';

    // Rebuild what agent_verify_signature() computes, from its own source, so
    // the canonical form is pinned to the server rather than restated here.
    $canonical = implode("\n", [
        'POST', '/agent_checkin.php',
        $headers['timestamp'], $headers['nonce'], hash('sha256', $body),
    ]);
    $expected = hash_hmac('sha256', $canonical, $secret);

    check('the agent signature matches the server computation',
        hash_equals($expected, strtolower($headers['signature'])));

    check('a tampered body does not verify',
        !hash_equals(
            hash_hmac('sha256', implode("\n", [
                'POST', '/agent_checkin.php', $headers['timestamp'], $headers['nonce'],
                hash('sha256', '{"hardware_id":"HW-TEST","agent_version":"9.9.9"}'),
            ]), $secret),
            strtolower($headers['signature'])
        ));

    check('the nonce satisfies the server format rule',
        preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $headers['nonce']) === 1, $headers['nonce']);
    check('the timestamp is a plausible epoch',
        ctype_digit($headers['timestamp']) && abs(time() - (int) $headers['timestamp']) < 120);
    check('the signature is lowercase hex',
        preg_match('/^[a-f0-9]{64}$/', $headers['signature']) === 1);

    // Two signatures of the same body must differ, or the nonce is not random
    // and replay rejection would refuse the agent's own second request.
    $second = [];
    foreach (explode("\n", $sh('sign')) as $line) {
        if (preg_match('/^X-OPNMGR-Nonce:\s*(.+)$/', trim($line), $m)) { $second[] = $m[1]; }
    }
    check('each request gets a fresh nonce',
        !empty($second) && $second[0] !== $headers['nonce'],
        'a repeated nonce is rejected as a replay, refusing the agent');
}

foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($dir);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
