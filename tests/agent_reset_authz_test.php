<?php
/**
 * Operator-initiated agent resets: who may ask, and that the reset works.
 *
 * admin/reset_agent.php required only a login and no CSRF token before running
 * scripts/auto_reset_stale_agents.php. The CLI itself could not complete a
 * reset: queue_emergency_reset() and ssh_reset_agent() used $reinstall_host,
 * a script-level variable, as if it were in scope, so the typed command
 * builder received null and threw.
 *
 *   tests/lib/make_test_db.sh && DB_NAME=opnsense_fw_test php tests/agent_reset_authz_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';

$tmp = sys_get_temp_dir() . '/opnmgr-reset-test-' . getmypid();
@mkdir($tmp, 0700, true);
putenv('HOME=' . $tmp);   // no SSH key can be found by the CLI subprocess

$prevUrl = db()->query("SELECT `value` FROM settings WHERE `name` = 'server_url'")->fetchColumn();
db()->prepare("INSERT INTO settings (`name`,`value`) VALUES ('server_url', 'https://127.0.0.1')
               ON DUPLICATE KEY UPDATE `value` = 'https://127.0.0.1'")->execute();

$users = [];
db()->prepare('INSERT INTO firewalls (hostname, ip_address, hardware_id, status, last_checkin, platform_family, platform_verified_at)
               VALUES ("__reset_test__", "10.0.0.9", ?, "online", DATE_SUB(NOW(), INTERVAL 3 HOUR), "opnsense", NOW())')
    ->execute([md5(random_bytes(8))]);
$fwId = (int)db()->lastInsertId();

register_shutdown_function(function () use (&$users, $fwId, $tmp, $prevUrl) {
    foreach ($users as $u) { db()->prepare('DELETE FROM users WHERE id = ?')->execute([$u]); }
    foreach (['firewall_commands', 'system_logs', 'audit_log'] as $t) {
        try { db()->prepare("DELETE FROM {$t} WHERE firewall_id = ?")->execute([$fwId]); } catch (Throwable $e) {}
    }
    db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$fwId]);
    if ($prevUrl === false) { db()->exec("DELETE FROM settings WHERE `name` = 'server_url'"); }
    else { db()->prepare("UPDATE settings SET `value` = ? WHERE `name` = 'server_url'")->execute([$prevUrl]); }
    exec('rm -rf ' . escapeshellarg($tmp));
});

// Fixture accounts count as enrolled in two-factor: clean installs require it for
// administrators (require_mfa_for_admins = 1), and this suite is not about MFA.
function session_as(string $role): array {
    global $users;
    db()->prepare('INSERT INTO users (username, password, role, is_active, totp_secret) VALUES (?,?,?,1,"enc:v1:test-fixture-enrolled")')
        ->execute(['__reset_' . $role . bin2hex(random_bytes(3)), 'x', $role]);
    $id = (int)db()->lastInsertId();
    $users[] = $id;
    return ['user_id' => $id, 'username' => 'u' . $id, 'role' => $role, 'session_version' => 0,
            'login_time' => time(), 'last_activity' => time(), 'last_regenerated' => time(),
            'csrf_token' => str_repeat('c', 64)];
}

$cmdCount = fn() => (int)db()->query("SELECT COUNT(*) FROM firewall_commands WHERE firewall_id = $fwId")->fetchColumn();
$ranCli   = fn(string $body) => str_contains($body, 'Reset Output') || str_contains($body, 'Starting Stale Agent');

// ===========================================================================
T::group('Legacy page: admin/reset_agent.php');

foreach (['readonly', 'technician'] as $role) {
    $r = call_endpoint('admin/reset_agent.php', ['method' => 'POST', 'session' => session_as($role),
        'post' => ['firewall_id' => $fwId, 'csrf' => str_repeat('c', 64)]]);
    T::ok(!$ranCli($r['body']) && $cmdCount() === 0, "{$role}: refused, no reset run ({$r['status']})");
}
$r = call_endpoint('admin/reset_agent.php', ['method' => 'POST', 'session' => session_as('admin'),
    'post' => ['firewall_id' => $fwId]]);
T::ok(!$ranCli($r['body']) && $cmdCount() === 0, 'admin without a CSRF token: refused, no reset run');
T::eq(403, $r['status'], 'with 403');
$r = call_endpoint('admin/reset_agent.php', ['method' => 'POST', 'session' => session_as('admin'),
    'post' => ['firewall_id' => $fwId, 'csrf' => str_repeat('d', 64)]]);
T::ok(!$ranCli($r['body']) && $cmdCount() === 0, 'admin with a wrong CSRF token: refused');
$r = call_endpoint('admin/reset_agent.php', ['method' => 'POST', 'post' => ['firewall_id' => $fwId]]);
T::ok(!$ranCli($r['body']) && $cmdCount() === 0, 'anonymous: refused');

// ===========================================================================
T::group('Supported API: api/reset_agent.php');

foreach (['readonly', 'technician'] as $role) {
    $r = call_endpoint('api/reset_agent.php', ['session' => session_as($role),
        'post' => ['firewall_id' => $fwId, 'csrf_token' => str_repeat('c', 64)]]);
    T::ok($cmdCount() === 0, "{$role}: refused, nothing queued ({$r['status']})");
}
$r = call_endpoint('api/reset_agent.php', ['session' => session_as('admin'), 'post' => ['firewall_id' => $fwId]]);
T::ok($r['status'] === 403 && $cmdCount() === 0, 'admin without CSRF: 403, nothing queued');

// ===========================================================================
T::group('The reset CLI completes (scope defect fixed)');

$out = []; $rc = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(TEST_ROOT . '/scripts/auto_reset_stale_agents.php')
     . ' --force-firewall-id=' . $fwId . ' --verbose 2>&1', $out, $rc);
$text = implode("\n", $out);
T::ok(!str_contains($text, 'TypeError') && !str_contains($text, 'Fatal'), 'no TypeError from the command builder');
T::eq(0, $rc, 'the CLI exits 0');
$queued = db()->query("SELECT command FROM firewall_commands WHERE firewall_id = $fwId AND command_type = 'emergency_reset'")->fetchColumn();
T::ok(is_string($queued) && str_contains($queued, 'https://127.0.0.1/reinstall_agent.php'), 'an emergency reset is queued against the configured manager');
T::ok(str_contains($text, 'Cannot attempt SSH reset') || str_contains($text, 'Skipping SSH target'), 'and no SSH was attempted in the test');

// ===========================================================================
T::group('The hourly cron path does not reset unless enabled');
db()->exec("DELETE FROM firewall_commands WHERE firewall_id = $fwId");
db()->exec("DELETE FROM settings WHERE `name` = 'auto_reset_stale_agents_enabled'");
$cron = function () {
    $out = []; $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(TEST_ROOT . '/scripts/auto_reset_stale_agents.php') . ' --verbose 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
};
[$rc, $text] = $cron();
T::eq(0, $rc, 'the unforced (cron) run exits 0');
T::ok(str_contains($text, 'Stale agent detected: __reset_test__'), 'it still reports the stale agent');
T::ok(str_contains($text, 'Automatic reset is disabled'), 'and says automatic reset is disabled');
T::eq(0, $cmdCount(), 'with no setting, nothing is queued for the firewall');
db()->exec("INSERT INTO settings (`name`,`value`) VALUES ('auto_reset_stale_agents_enabled','0')");
$cron();
T::eq(0, $cmdCount(), 'with the setting at 0, nothing is queued');
db()->exec("UPDATE settings SET `value` = '1' WHERE `name` = 'auto_reset_stale_agents_enabled'");
$cron();
T::eq(1, $cmdCount(), 'with the setting at 1, the cron run queues a reset');
db()->exec("DELETE FROM settings WHERE `name` = 'auto_reset_stale_agents_enabled'");

// ===========================================================================
T::group('Admin with a valid token can run it');
db()->exec("DELETE FROM firewall_commands WHERE firewall_id = $fwId");
$r = call_endpoint('admin/reset_agent.php', ['method' => 'POST', 'session' => session_as('admin'),
    'post' => ['firewall_id' => $fwId, 'csrf' => str_repeat('c', 64)]]);
T::ok($ranCli($r['body']), 'the reset runs for an administrator with a valid CSRF token');
T::ok($cmdCount() === 1, 'and queues exactly one reset');

exit(T::summary());
