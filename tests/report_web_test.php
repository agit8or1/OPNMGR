<?php
/**
 * Report web surface through real endpoint invocations: public share view,
 * authenticated download with customer scoping, CSRF, permissions.
 *   DB_NAME=opnsense_fw_test_reports php tests/report_web_test.php
 */
require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/reports/jobs.php';

$dbName = db()->query('SELECT DATABASE()')->fetchColumn();
if (strpos($dbName, 'opnsense_fw_test') !== 0) { fwrite(STDERR, "refusing to run against $dbName\n"); exit(2); }
$storage = sys_get_temp_dir() . '/opnmgr_report_web_' . bin2hex(random_bytes(4)); mkdir($storage, 0750, true);
register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($storage)));
function setting(string $n, string $v): void { db()->prepare('INSERT INTO settings (`name`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)')->execute([$n, $v]); }
setting('report_storage_path', $storage); setting('report_share_enabled', '0');
foreach (['report_deliveries', 'report_shares', 'reports', 'report_jobs', 'user_customer_scopes', 'firewall_latency'] as $t) { db()->exec("DELETE FROM `$t`"); }
db()->exec('DELETE FROM firewalls'); db()->exec('DELETE FROM customers');
$ids = [];
foreach (['WebA', 'WebB'] as $n) {
    db()->prepare('INSERT INTO customers (name, timezone) VALUES (?, "UTC")')->execute([$n]); $c = (int)db()->lastInsertId();
    db()->prepare("INSERT INTO firewalls (hostname, ip_address, hardware_id, status, customer_id, enrolled_at) VALUES (?,?,?,?,?,'2026-01-01')")->execute(["fw-$n", '198.51.100.7', bin2hex(random_bytes(8)), 'online', $c]);
    $q = report_enqueue(['report_type' => 'customer', 'scope_id' => $c, 'period' => ['preset' => 'custom', 'from' => '2026-03-01', 'to' => '2026-03-02'], 'pdf' => false], ['queued_at' => 1]);
    $run = report_run_job($q['job_id'], time());
    $ids[$n] = ['cust' => $c, 'report' => $run['report_id']];
}
$mkUsers = [];
register_shutdown_function(function () use (&$mkUsers) { foreach ($mkUsers as $u) { db()->prepare('DELETE FROM user_customer_scopes WHERE user_id = ?')->execute([$u]); db()->prepare('DELETE FROM users WHERE id = ?')->execute([$u]); } });
/** A real users row (the session is re-validated against it on every request). $scope: customer ids, or [] for unrestricted. */
// Fixture accounts count as enrolled in two-factor: clean installs require it for
// administrators (require_mfa_for_admins = 1), and this suite is not about MFA.
function user_session(string $role, array $scope = []): array {
    global $mkUsers;
    db()->prepare('INSERT INTO users (username, password, role, is_active, totp_secret) VALUES (?,?,?,1,"enc:v1:test-fixture-enrolled")')->execute(['__web_' . $role . bin2hex(random_bytes(3)), 'x', $role]);
    $id = (int)db()->lastInsertId(); $mkUsers[] = $id;
    foreach ($scope as $c) { db()->prepare('INSERT INTO user_customer_scopes (user_id, customer_id) VALUES (?, ?)')->execute([$id, $c]); }
    return ['user_id' => $id, 'username' => 'u' . $id, 'role' => $role, 'session_version' => 0, 'login_time' => time(), 'last_activity' => time(), 'last_regenerated' => time(), 'csrf_token' => 'tok123'];
}
$S = ['open_ro' => user_session('readonly'), 'scoped_ro' => user_session('readonly', [$ids['WebA']['cust']]),
      'scoped_tech' => user_session('technician', [$ids['WebA']['cust']]), 'admin' => user_session('admin')];

T::group('Public share endpoint');
$r = call_endpoint('report_share.php', ['method' => 'GET', 'get' => ['t' => 'rs_' . str_repeat('0', 64)]]);
T::eq(404, $r['status'], 'unknown token: 404 (and sharing is off)');
setting('report_share_enabled', '1');
$sh = report_share_create((int)$ids['WebA']['report'], 1, 'web');
$r = call_endpoint('report_share.php', ['method' => 'GET', 'get' => ['t' => $sh['token']]]);
T::eq(200, $r['status'], 'valid token serves the report without a login');
T::ok(str_contains($r['body'], 'fw-WebA') && !str_contains($r['body'], 'fw-WebB'), 'it is the shared customer\'s report only');
$r = call_endpoint('report_share.php', ['method' => 'GET', 'get' => ['t' => $sh['token'], 'fmt' => 'csv']]);
T::ok($r['status'] === 200 && str_starts_with($r['body'], 'table,firewall'), 'CSV via share link');
$r = call_endpoint('report_share.php', ['method' => 'GET', 'get' => ['t' => $sh['token'], 'fmt' => 'pdf']]);
T::eq(404, $r['status'], 'a format that was not produced is 404');
report_share_revoke($sh['share_id'], 'web');
$r = call_endpoint('report_share.php', ['method' => 'GET', 'get' => ['t' => $sh['token']]]);
T::eq(410, $r['status'], 'revoked link: 410');
$sh2 = report_share_create((int)$ids['WebA']['report'], 1, 'web');
db()->exec('UPDATE report_shares SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE id = ' . (int)$sh2['share_id']);
T::eq(410, call_endpoint('report_share.php', ['method' => 'GET', 'get' => ['t' => $sh2['token']]])['status'], 'expired link: 410');
$sh3 = report_share_create((int)$ids['WebA']['report'], 1, 'web');
$p = $storage . '/' . db()->query('SELECT html_path FROM reports WHERE id = ' . (int)$ids['WebA']['report'])->fetchColumn();
chmod($p, 0640); file_put_contents($p, 'x', FILE_APPEND);
$r = call_endpoint('report_share.php', ['method' => 'GET', 'get' => ['t' => $sh3['token']]]);
T::eq(500, $r['status'], 'a tampered file is refused even through a valid link');
T::ok(!str_contains($r['body'], 'fw-WebA'), '... and not served');

T::group('Authenticated download and scoping');
$r = call_endpoint('api/report_download.php', ['method' => 'GET', 'get' => ['id' => $ids['WebB']['report'], 'fmt' => 'csv']]);
T::ok($r['status'] !== 200 || !str_contains($r['body'], 'fw-WebB'), 'anonymous download is refused');
$r = call_endpoint('api/report_download.php', ['method' => 'GET', 'get' => ['id' => $ids['WebB']['report'], 'fmt' => 'csv'], 'session' => $S['open_ro']]);
T::eq(200, $r['status'], 'unrestricted read-only staff can download');
$r = call_endpoint('api/report_download.php', ['method' => 'GET', 'get' => ['id' => $ids['WebB']['report'], 'fmt' => 'csv'], 'session' => $S['scoped_ro']]);
T::eq(404, $r['status'], 'scoped staff get 404 for another customer\'s report');
T::ok(!str_contains($r['body'], 'fw-WebB'), '... with no content');
$r = call_endpoint('api/report_download.php', ['method' => 'GET', 'get' => ['id' => $ids['WebA']['report'], 'fmt' => 'csv'], 'session' => $S['scoped_ro']]);
T::eq(200, $r['status'], 'scoped staff can download their own customer\'s report');
T::ok(1 <= (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'report.download' AND object_id = '" . (int)$ids['WebA']['report'] . "'")->fetchColumn(), 'download audited');

T::group('Generate / manage endpoints');
$body = ['report_type' => 'customer', 'scope_id' => $ids['WebA']['cust'], 'preset' => 'last_week', 'pdf' => 0];
$r = call_endpoint('api/report_generate.php', ['post' => $body, 'session' => $S['scoped_tech']]);
T::eq(403, $r['status'], 'missing CSRF token is refused');
$r = call_endpoint('api/report_generate.php', ['post' => $body + ['csrf' => 'tok123'], 'session' => $S['scoped_ro']]);
T::ok($r['status'] === 400 && ($r['json']['success'] ?? true) === false, 'read-only role cannot generate');
$r = call_endpoint('api/report_generate.php', ['post' => $body + ['csrf' => 'tok123'], 'session' => $S['scoped_tech']]);
T::ok($r['status'] === 200 && !empty($r['json']['report_id']), 'technician generates their customer\'s report: ' . ($r['json']['message'] ?? $r['body']));
$r = call_endpoint('api/report_generate.php', ['post' => ['scope_id' => $ids['WebB']['cust']] + $body + ['csrf' => 'tok123'], 'session' => $S['scoped_tech']]);
T::eq(400, $r['status'], 'scoped technician cannot generate another customer\'s report');
$r = call_endpoint('api/report_manage.php', ['post' => ['action' => 'save_settings', 'report_share_enabled' => 1, 'csrf' => 'tok123'], 'session' => $S['scoped_tech']]);
T::eq(400, $r['status'], 'technician cannot flip the global switches (report.deliver is admin only)');
$r = call_endpoint('api/report_manage.php', ['post' => ['action' => 'toggle_schedule', 'id' => 1, 'enabled' => 1, 'csrf' => 'tok123'], 'session' => $S['scoped_tech']]);
T::eq(400, $r['status'], 'technician cannot enable a schedule');
$r = call_endpoint('api/report_manage.php', ['post' => ['action' => 'create_share', 'report_id' => $ids['WebB']['report'], 'hours' => 5, 'csrf' => 'tok123'], 'session' => $S['admin']]);
T::ok($r['status'] === 200, 'unrestricted admin can create a share link');
$r = call_endpoint('customer_reports.php', ['method' => 'GET', 'session' => $S['scoped_ro']]);
T::ok($r['status'] === 200 && str_contains($r['body'], 'Customer Reports') && !str_contains($r['body'], 'WebB<'), 'reports page renders and omits other customers for scoped staff');

exit(T::summary());
