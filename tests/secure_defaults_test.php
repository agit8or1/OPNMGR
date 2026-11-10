<?php
/**
 * Secure defaults and the enrolment-bound first claim (3.80.1).
 *
 *  - An unbound firewall cannot be claimed by an arbitrary check-in; it can be
 *    within the window after an enrolment token was redeemed for it.
 *  - An issued-but-unadopted credential stops being re-sent after the window.
 *  - With no settings row, raw shell is off and admin MFA is required.
 *  - A clean install (schema.sql + migrate.php) stores those secure values,
 *    and the migrations that seed them never overwrite a stored value (3.80.3).
 *
 *   tests/lib/make_test_db.sh && DB_NAME=opnsense_fw_test php tests/secure_defaults_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/crypto.php';
require_once TEST_ROOT . '/inc/agent_commands.php';
require_once TEST_ROOT . '/inc/auth.php';

$made = [];
$savedSettings = db()->query("SELECT name, value FROM settings WHERE name IN
    ('raw_command_enabled','require_mfa_for_admins','agent_claim_window_hours','agent_auth_mode')")
    ->fetchAll(PDO::FETCH_KEY_PAIR);

register_shutdown_function(function () use (&$made, $savedSettings) {
    foreach ($made as $id) {
        foreach (['firewall_commands', 'firewall_agents', 'agent_request_nonces', 'audit_log', 'system_logs', 'enrollment_tokens'] as $t) {
            try { db()->prepare("DELETE FROM {$t} WHERE firewall_id = ?")->execute([$id]); } catch (Throwable $e) {}
        }
        db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$id]);
    }
    db()->exec("DELETE FROM settings WHERE name IN ('raw_command_enabled','require_mfa_for_admins','agent_claim_window_hours','agent_auth_mode')");
    foreach ($savedSettings as $k => $v) {
        db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$k, $v]);
    }
});

function unbound_fw(string $tag): int {
    global $made;
    db()->prepare('INSERT INTO firewalls (hostname, ip_address, status, platform_family, platform_verified_at)
                   VALUES (?, "198.51.100.20", "offline", "opnsense", NOW())')->execute(['__sd_' . $tag . '__']);
    $id = (int)db()->lastInsertId();
    $made[] = $id;
    return $id;
}
function redeemed_token(int $fwId, string $usedAt): void {
    db()->prepare("INSERT INTO enrollment_tokens (token, firewall_id, expires_at, used, used_at)
                   VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY), 1, {$usedAt})")
        ->execute([bin2hex(random_bytes(16)), $fwId]);
}
function first_checkin(int $fwId, string $hw): array {
    return call_endpoint('agent_checkin.php', ['body' => ['firewall_id' => $fwId, 'hardware_id' => $hw, 'agent_version' => '1.8.2']]);
}
$bound = fn(int $id) => (string)db()->query("SELECT COALESCE(hardware_id,'') FROM firewalls WHERE id = $id")->fetchColumn();

// ===========================================================================
// Before anything below changes a setting: the test database is a clean
// install (schema.sql, then migrate.php), so what it stores is what a new
// installation gets. Deleting the rows first, as this suite used to, only
// tested the code default and missed migrations re-seeding insecure values.
T::group('A clean install stores secure values');

T::eq('0', $savedSettings['raw_command_enabled'] ?? null, 'schema.sql + migrate.php: raw shell stored off');
T::eq('1', $savedSettings['require_mfa_for_admins'] ?? null, 'schema.sql + migrate.php: admin MFA stored required');
T::eq('prefer_signed', $savedSettings['agent_auth_mode'] ?? null, 'schema.sql + migrate.php: agents prefer_signed');

$pending = shell_exec('DB_NAME=' . escapeshellarg((string)getenv('DB_NAME')) . ' php '
    . escapeshellarg(TEST_ROOT . '/scripts/migrate.php') . ' --status 2>&1');
T::ok(is_string($pending) && preg_match('/\b0 pending\b/', $pending) && !str_contains($pending, 'WARNING'),
    'every migration is recorded as applied, with its current checksum');

// The migrations that seed these rows, run again where the rows are absent
// (an install whose first migrate.php predates the schema baseline).
db()->exec("DELETE FROM settings WHERE name IN ('raw_command_enabled','require_mfa_for_admins','agent_auth_mode')");
db()->exec("DELETE FROM schema_migrations WHERE migration IN ('0001_agent_auth_and_audit.sql','0003_structured_commands.sql')");
exec('DB_NAME=' . escapeshellarg((string)getenv('DB_NAME')) . ' php '
    . escapeshellarg(TEST_ROOT . '/scripts/migrate.php') . ' 2>&1', $out, $rc);
T::eq(0, $rc, 'migrations 0001 and 0003 re-apply cleanly');
$seeded = db()->query("SELECT name, value FROM settings WHERE name IN
    ('raw_command_enabled','require_mfa_for_admins','agent_auth_mode')")->fetchAll(PDO::FETCH_KEY_PAIR);
T::eq('0', $seeded['raw_command_enabled'] ?? null, 'migrations seed raw shell off');
T::eq('1', $seeded['require_mfa_for_admins'] ?? null, 'migrations seed admin MFA required');
T::eq('prefer_signed', $seeded['agent_auth_mode'] ?? null, 'migrations seed prefer_signed');

// And never overwrite a value an installation stored.
db()->exec("UPDATE settings SET value = '1' WHERE name = 'raw_command_enabled'");
db()->exec("DELETE FROM schema_migrations WHERE migration IN ('0001_agent_auth_and_audit.sql','0003_structured_commands.sql')");
exec('DB_NAME=' . escapeshellarg((string)getenv('DB_NAME')) . ' php '
    . escapeshellarg(TEST_ROOT . '/scripts/migrate.php') . ' 2>&1');
T::eq('1', (string)db()->query("SELECT value FROM settings WHERE name = 'raw_command_enabled'")->fetchColumn(),
    'a stored raw_command_enabled=1 survives the migrations re-running');

db()->exec("UPDATE settings SET value = 'compatibility' WHERE name = 'agent_auth_mode'");
db()->exec("DELETE FROM settings WHERE name = 'agent_claim_window_hours'");

// ===========================================================================
T::group('First check-in claim is bound to an enrolment token');

$a = unbound_fw('noToken');
$r = first_checkin($a, 'attacker-' . bin2hex(random_bytes(4)));
T::ok($r['status'] === 403 && $bound($a) === '', "no token: refused, nothing bound ({$r['status']})");
T::ok(!str_contains($r['body'], 'api_secret'), 'and no secret is handed out');

$b = unbound_fw('freshToken');
redeemed_token($b, 'DATE_SUB(NOW(), INTERVAL 5 MINUTE)');
$hw = 'agent-' . bin2hex(random_bytes(4));
$r = first_checkin($b, $hw);
T::ok($r['status'] === 200 && $bound($b) === $hw, "token redeemed 5 min ago: claim accepted ({$r['status']})");

$c = unbound_fw('staleToken');
redeemed_token($c, 'DATE_SUB(NOW(), INTERVAL 30 HOUR)');
$r = first_checkin($c, 'late-' . bin2hex(random_bytes(4)));
T::ok($r['status'] === 403 && $bound($c) === '', "token redeemed 30 h ago: refused ({$r['status']})");

$d = unbound_fw('otherFw');
redeemed_token($b, 'NOW()');   // a token for a different firewall does not help
$r = first_checkin($d, 'other-' . bin2hex(random_bytes(4)));
T::ok($r['status'] === 403 && $bound($d) === '', "a token for another firewall does not open the claim ({$r['status']})");

db()->exec("INSERT INTO settings (name, value) VALUES ('agent_claim_window_hours', '0')");
$e = unbound_fw('legacy');
$r = first_checkin($e, 'legacy-' . bin2hex(random_bytes(4)));
T::ok($r['status'] === 200 && $bound($e) !== '', "window 0: legacy trust-on-first-use still available ({$r['status']})");
db()->exec("DELETE FROM settings WHERE name = 'agent_claim_window_hours'");

// ===========================================================================
T::group('Unadopted credentials stop being re-sent after the window');

// $b was provisioned above and never presented its key.
$r = first_checkin($b, $hw);
T::ok($r['status'] === 200 && str_contains($r['body'], 'api_secret'), 'within the window: re-sent so a lost config can recover');
db()->exec("UPDATE firewalls SET api_key_issued_at = DATE_SUB(NOW(), INTERVAL 30 HOUR) WHERE id = $b");
$r = first_checkin($b, $hw);
T::ok($r['status'] === 200, "past the window: an old agent still authenticates ({$r['status']})");
T::ok(!str_contains($r['body'], 'api_secret'), 'but is no longer handed the secret');

// ===========================================================================
T::group('Defaults when the settings row is missing');

db()->exec("DELETE FROM settings WHERE name = 'raw_command_enabled'");
T::eq(false, raw_commands_enabled(), 'raw shell is off with no row');
db()->exec("INSERT INTO settings (name, value) VALUES ('raw_command_enabled', '1')");
T::eq(true, raw_commands_enabled(), 'and on when explicitly enabled');

db()->prepare('INSERT INTO users (username, password, role, is_active) VALUES (?,?,?,1)')
    ->execute(['__sd_admin_' . bin2hex(random_bytes(3)), 'x', 'admin']);
$uid = (int)db()->lastInsertId();
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = $uid;
db()->exec("DELETE FROM settings WHERE name = 'require_mfa_for_admins'");
T::eq(true, mfa_enrolment_required(), 'admin MFA is required with no row');
db()->exec("INSERT INTO settings (name, value) VALUES ('require_mfa_for_admins', '0')");
T::eq(false, mfa_enrolment_required(), 'an install that stored 0 keeps it');
db()->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);

exit(T::summary());
