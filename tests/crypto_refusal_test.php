<?php
/**
 * No secret is stored in plaintext because the master key is unusable (3.80.3).
 *
 * Before 3.80.3 opnmgr_encrypt() returned its input unchanged when the key was
 * missing or malformed (or sodium absent), so every credential written in that
 * state - agent API keys first of all - went to the database as plaintext, and
 * a malformed key showed a banner saying it was "not set".
 *
 * Each case runs in a subprocess with OPNMGR_MASTER_KEY overridden. An empty
 * value counts as unset, and inc/env.php does not replace a variable that is
 * already in the environment, so .env cannot put the real key back.
 *
 * The missing-sodium branch is not exercised: sodium is compiled into PHP on
 * the hosts this runs on and cannot be unloaded.
 *
 *   tests/lib/make_test_db.sh && DB_NAME=opnsense_fw_test php tests/crypto_refusal_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/crypto.php';
require_once TEST_ROOT . '/inc/integrations/destinations.php';

$made = ['fw' => [], 'users' => [], 'dest' => []];
register_shutdown_function(function () use (&$made) {
    putenv('OPNMGR_MASTER_KEY');
    foreach ($made['fw'] as $id) {
        foreach (['firewall_commands', 'firewall_agents', 'agent_request_nonces', 'audit_log', 'system_logs', 'enrollment_tokens'] as $t) {
            try { db()->prepare("DELETE FROM {$t} WHERE firewall_id = ?")->execute([$id]); } catch (Throwable $e) {}
        }
        db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$id]);
    }
    foreach ($made['users'] as $id) { db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]); }
    db()->exec("DELETE FROM users WHERE username LIKE '\\_\\_cr\\_%'");
    foreach ($made['dest'] as $id) { db()->prepare('DELETE FROM integration_destinations WHERE id = ?')->execute([$id]); }
    db()->exec("DELETE FROM integration_destinations WHERE name LIKE '\\_\\_cr\\_dest\\_%'");
});

const BAD_KEY = 'not-a-valid-master-key';

/** Run PHP code with OPNMGR_MASTER_KEY set to $key ('' = unset); returns [rc, stdout+stderr]. */
function php_with_key(string $key, string $code): array {
    $env = 'OPNMGR_MASTER_KEY=' . escapeshellarg($key) . ' DB_NAME=' . escapeshellarg((string)getenv('DB_NAME'));
    $boot = 'require ' . var_export(TEST_ROOT . '/inc/bootstrap_agent.php', true) . '; require ' . var_export(TEST_ROOT . '/inc/crypto.php', true) . ';';
    exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($boot . $code) . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
}

// ===========================================================================
T::group('opnmgr_encrypt() refuses instead of returning plaintext');

$probe = 'try { $v = opnmgr_encrypt("hunter2-secret"); echo $v === "hunter2-secret" ? "PLAINTEXT" : "ENCRYPTED"; }'
       . ' catch (OpnmgrCryptoUnavailable $e) { echo "REFUSED ", opnmgr_crypto_status()["reason"]; }';
[, $out] = php_with_key('', $probe);
T::ok(str_contains($out, 'REFUSED missing_key'), "missing key: refused ({$out})");
[, $out] = php_with_key(BAD_KEY, $probe);
T::ok(str_contains($out, 'REFUSED malformed_key'), "malformed key: refused, and reported as malformed ({$out})");
[, $out] = php_with_key(base64_encode(random_bytes(32)), $probe);
T::ok(str_contains($out, 'ENCRYPTED'), "valid key: encrypted ({$out})");
[, $out] = php_with_key(BAD_KEY, 'echo var_export(opnmgr_encrypt(""), true), "|", opnmgr_decrypt("legacy-plain");');
T::ok(str_contains($out, "''|legacy-plain"), 'empty values and existing plaintext still read back');

// ===========================================================================
T::group('Agent first check-in stores no plaintext credential');

db()->prepare('INSERT INTO firewalls (hostname, ip_address, status, platform_family, platform_verified_at)
               VALUES ("__cr_fw__", "198.51.100.41", "offline", "opnsense", NOW())')->execute();
$fw = $made['fw'][] = (int)db()->lastInsertId();
db()->prepare("INSERT INTO enrollment_tokens (token, firewall_id, expires_at, used, used_at)
               VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY), 1, NOW())")->execute([bin2hex(random_bytes(32)), $fw]);
putenv('OPNMGR_MASTER_KEY=' . BAD_KEY);   // inherited by the endpoint subprocess
$r = call_endpoint('agent_checkin.php', ['body' => ['firewall_id' => $fw, 'hardware_id' => 'cr-' . bin2hex(random_bytes(4)), 'agent_version' => '1.8.2']]);
putenv('OPNMGR_MASTER_KEY');
$row = db()->query("SELECT COALESCE(agent_api_key,'') k, COALESCE(agent_api_secret,'') s FROM firewalls WHERE id = {$fw}")->fetch();
T::ok($row['k'] === '' && $row['s'] === '', 'malformed key: no api_key or signing secret written');
T::ok(!str_contains($r['body'], 'api_secret'), 'and none handed to the agent');
T::ok(in_array($r['status'], [200, 403], true), "the check-in itself is answered, not a 500 ({$r['status']})");

// ===========================================================================
T::group('Integration destinations refuse to save their secrets');

[, $out] = php_with_key(BAD_KEY, 'require ' . var_export(TEST_ROOT . '/inc/integrations/destinations.php', true) . ';'
    . '$r = integration_destination_save(["name" => "__cr_dest_" . bin2hex(random_bytes(3)), "type" => "webhook",'
    . ' "secrets" => ["url" => "https://hooks.example.test/x", "signing_secret" => "abcdefghijklmnopqrst"]], null, "t");'
    . 'echo $r["ok"] ? "SAVED" : "REFUSED: " . $r["error"];');
T::ok(str_contains($out, 'REFUSED') && str_contains($out, 'OPNMGR_MASTER_KEY'), 'refused with a message naming the key');
T::eq(0, (int)db()->query("SELECT COUNT(*) FROM integration_destinations WHERE name LIKE '\\_\\_cr\\_dest\\_%'")->fetchColumn(), 'and nothing was stored');

// ===========================================================================
T::group('create_admin.php fails without a usable key');

$u = '__cr_admin_' . bin2hex(random_bytes(3));
exec('OPNMGR_MASTER_KEY=' . escapeshellarg(BAD_KEY) . ' DB_NAME=' . escapeshellarg((string)getenv('DB_NAME')) . ' '
    . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(TEST_ROOT . '/scripts/create_admin.php') . ' '
    . escapeshellarg($u) . ' cr@example.test ' . escapeshellarg('correct-horse-battery-1') . ' 2>&1', $out, $rc);
$exists = (int)db()->query("SELECT COUNT(*) FROM users WHERE username = " . db()->quote($u))->fetchColumn();
T::ok($rc !== 0 && $exists === 0, "malformed key: exits non-zero and creates no administrator (rc={$rc})");
T::ok(str_contains(implode("\n", $out), 'not a valid'), 'and says why');

// ===========================================================================
T::group('Administrator banner names each cause');

db()->prepare('INSERT INTO users (username, password, role, is_active) VALUES (?,?,?,1)')
    ->execute(['__cr_banner_' . bin2hex(random_bytes(3)), 'x', 'admin']);
$admin = $made['users'][] = (int)db()->lastInsertId();
$session = ['user_id' => $admin, 'username' => 'u' . $admin, 'role' => 'admin', 'session_version' => 0,
            'login_time' => time(), 'last_activity' => time(), 'last_regenerated' => time(), 'csrf_token' => str_repeat('c', 64)];
foreach (['' => 'No master key is configured', BAD_KEY => 'The master key is invalid'] as $key => $expect) {
    putenv('OPNMGR_MASTER_KEY=' . $key);
    $r = call_endpoint('twofactor_setup.php', ['method' => 'GET', 'session' => $session]);
    putenv('OPNMGR_MASTER_KEY');
    T::ok(str_contains($r['body'], $expect), ($key === '' ? 'missing' : 'malformed') . " key: banner shows \"{$expect}\" ({$r['status']})");
}
$r = call_endpoint('twofactor_setup.php', ['method' => 'GET', 'session' => $session]);
T::ok(!str_contains($r['body'], 'master key'), 'valid key: no banner');

// ===========================================================================
T::group('encrypt_secrets.php backfills plaintext rows, including integration secrets');

$d = integration_destination_save(['name' => '__cr_dest_ok_' . bin2hex(random_bytes(3)), 'type' => 'webhook', 'allow_internal' => 1,
    'secrets' => ['url' => 'https://hooks.example.test/y', 'signing_secret' => 'abcdefghijklmnopqrst']], null, 't');
$made['dest'][] = $d['id'];
db()->prepare('UPDATE integration_destinations SET secrets_enc = ? WHERE id = ?')
    ->execute([json_encode(['url' => 'https://hooks.example.test/y', 'signing_secret' => 'abcdefghijklmnopqrst']), $d['id']]);
exec('DB_NAME=' . escapeshellarg((string)getenv('DB_NAME')) . ' ' . escapeshellarg(PHP_BINARY) . ' '
    . escapeshellarg(TEST_ROOT . '/scripts/encrypt_secrets.php') . ' 2>&1', $out, $rc);
$v = (string)db()->query("SELECT secrets_enc FROM integration_destinations WHERE id = " . (int)$d['id'])->fetchColumn();
T::ok($rc === 0 && opnmgr_is_encrypted($v), 'a plaintext integration secret is encrypted');
T::ok(str_contains((string)opnmgr_decrypt($v), 'abcdefghijklmnopqrst'), 'and still decrypts to the original');

exit(T::summary());
