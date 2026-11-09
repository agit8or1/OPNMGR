<?php
/**
 * A readonly account is refused, and nothing happens, on the endpoints that
 * accepted it before 3.80.1. A technician can still do what the matrix allows.
 *
 *   tests/lib/make_test_db.sh && DB_NAME=opnsense_fw_test php tests/role_gate_behaviour_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';

$users = [];
db()->prepare('INSERT INTO firewalls (hostname, ip_address, hardware_id, status, last_checkin, platform_family, platform_verified_at)
               VALUES ("__rolegate_test__", "10.0.0.8", ?, "online", NOW(), "opnsense", NOW())')
    ->execute([md5(random_bytes(8))]);
$fwId = (int)db()->lastInsertId();

register_shutdown_function(function () use (&$users, $fwId) {
    foreach ($users as $u) { db()->prepare('DELETE FROM users WHERE id = ?')->execute([$u]); }
    db()->exec("DELETE FROM users WHERE username LIKE '__rolegate_created_%'");
    foreach (['firewall_commands', 'audit_log'] as $t) {
        try { db()->prepare("DELETE FROM {$t} WHERE firewall_id = ?")->execute([$fwId]); } catch (Throwable $e) {}
    }
    db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$fwId]);
});

function session_as(string $role): array {
    global $users;
    db()->prepare('INSERT INTO users (username, password, role, is_active) VALUES (?,?,?,1)')
        ->execute(['__rolegate_' . $role . bin2hex(random_bytes(3)), 'x', $role]);
    $id = (int)db()->lastInsertId();
    $users[] = $id;
    return ['user_id' => $id, 'username' => 'u' . $id, 'role' => $role, 'session_version' => 0,
            'login_time' => time(), 'last_activity' => time(), 'last_regenerated' => time(),
            'csrf_token' => str_repeat('c', 64)];
}

$csrf     = str_repeat('c', 64);
$cmdCount = fn() => (int)db()->query("SELECT COUNT(*) FROM firewall_commands WHERE firewall_id = $fwId")->fetchColumn();
$updReq   = fn() => (int)db()->query("SELECT COALESCE(update_requested,0) FROM firewalls WHERE id = $fwId")->fetchColumn();

// ===========================================================================
T::group('api/queue_action.php');

$body = fn(string $action, array $p = []) => json_encode(['firewall_id' => $fwId, 'action' => $action, 'parameters' => $p, 'csrf_token' => $csrf]);

$r = call_endpoint('api/queue_action.php', ['session' => session_as('readonly'),
    'body' => $body('service_restart', ['service' => 'unbound'])]);
T::ok($r['status'] === 403 && $cmdCount() === 0, "readonly: MEDIUM service_restart refused ({$r['status']})");
$r = call_endpoint('api/queue_action.php', ['session' => session_as('readonly'), 'body' => $body('agent_restart')]);
T::ok($r['status'] === 403 && $cmdCount() === 0, "readonly: agent_restart refused ({$r['status']})");
$r = call_endpoint('api/queue_action.php', ['session' => session_as('readonly'), 'body' => $body('agent_status')]);
T::ok($r['status'] === 403 && $cmdCount() === 0, "readonly: even LOW agent_status refused ({$r['status']})");

$r = call_endpoint('api/queue_action.php', ['session' => session_as('technician'), 'body' => $body('agent_status')]);
T::ok($r['status'] === 200 && $cmdCount() === 1, "technician: LOW agent_status queued ({$r['status']})");
$r = call_endpoint('api/queue_action.php', ['session' => session_as('technician'), 'body' => $body('reboot')]);
T::ok($r['status'] === 403 && $cmdCount() === 1, "technician: HIGH reboot still refused ({$r['status']})");
db()->exec("DELETE FROM firewall_commands WHERE firewall_id = $fwId");

// ===========================================================================
T::group('api/trigger_update.php');

$r = call_endpoint('api/trigger_update.php', ['session' => session_as('readonly'),
    'body' => json_encode(['firewall_id' => $fwId, 'csrf' => $csrf])]);
T::ok($r['status'] === 403 && $updReq() === 0, "readonly: refused, no update requested ({$r['status']})");

$r = call_endpoint('api/trigger_update.php', ['session' => session_as('technician'),
    'body' => json_encode(['firewall_id' => $fwId, 'csrf' => $csrf])]);
T::ok($r['status'] === 200 && $updReq() === 1, "technician: update requested ({$r['status']})");
$audited = (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE firewall_id = $fwId AND action = 'update.trigger'")->fetchColumn();
T::eq(1, $audited, 'and it is in the audit log');
db()->exec("UPDATE firewalls SET update_requested = 0 WHERE id = $fwId");

// ===========================================================================
T::group('api/network_diagnostic.php');

$diag = fn(array $s, string $target) => call_endpoint('api/network_diagnostic.php', ['method' => 'POST', 'session' => $s,
    'post' => ['firewall_id' => $fwId, 'command_type' => 'ping', 'target' => $target, 'csrf_token' => $csrf]]);

$r = $diag(session_as('readonly'), '1.1.1.1');
T::ok($r['status'] === 403 && $cmdCount() === 0, "readonly: refused ({$r['status']})");
$r = $diag(session_as('technician'), 'a";reboot;"');
T::ok($cmdCount() === 0, 'technician: shell metacharacters in target rejected');
$r = $diag(session_as('technician'), '$(id)');
T::ok($cmdCount() === 0, 'technician: $(...) in target rejected');
$r = $diag(session_as('technician'), 'example.com');
$cmd = (string)db()->query("SELECT command FROM firewall_commands WHERE firewall_id = $fwId")->fetchColumn();
T::ok($cmdCount() === 1 && str_contains($cmd, "'example.com'"), 'technician: a real hostname is queued, escaped');
db()->exec("DELETE FROM firewall_commands WHERE firewall_id = $fwId");

// ===========================================================================
T::group('add_user.php');

$name = '__rolegate_created_' . bin2hex(random_bytes(3));
$r = call_endpoint('add_user.php', ['method' => 'POST', 'session' => session_as('readonly'),
    'post' => ['username' => $name, 'password' => 'Password123!x', 'role' => 'admin', 'csrf' => $csrf]]);
$made = (int)db()->query("SELECT COUNT(*) FROM users WHERE username = " . db()->quote($name))->fetchColumn();
T::ok($made === 0, "readonly: cannot create an admin ({$r['status']})");
$r = call_endpoint('add_user.php', ['method' => 'POST', 'session' => session_as('technician'),
    'post' => ['username' => $name, 'password' => 'Password123!x', 'role' => 'admin', 'csrf' => $csrf]]);
$made = (int)db()->query("SELECT COUNT(*) FROM users WHERE username = " . db()->quote($name))->fetchColumn();
T::ok($made === 0, "technician: cannot create an admin either ({$r['status']})");

exit(T::summary());
