<?php
/** Offline session/role regression test, no DB or HTTP server needed.
 * php tests/account_authorization_test.php demoted|active|disabled|missing|outage
 */
define('OPNMGR_BOOTSTRAPPED', true);
$scenario = $argv[1] ?? 'demoted';
class FakeStatement {
    public function execute($args = []) { return true; }
    public function fetchAll($mode = null) { return []; }
    public function fetch($mode = null) {
        global $scenario;
        if ($scenario === 'missing') return false;
        return ['is_active' => $scenario === 'disabled' ? 0 : 1,
                'role' => $scenario === 'demoted' ? 'readonly' : 'admin'];
    }
}
class FakeDatabase {
    public function query($sql) { return new FakeStatement(); }
    public function prepare($sql) {
        global $scenario;
        if ($scenario === 'outage') throw new RuntimeException('synthetic outage');
        return new FakeStatement();
    }
}
function db() { return new FakeDatabase(); }
$_SESSION = ['user_id' => 1, 'role' => 'admin', 'login_time' => time(),
             'last_activity' => time(), 'last_regenerated' => time()];
require dirname(__DIR__) . '/inc/auth.php';
require dirname(__DIR__) . '/inc/permissions.php';
$authenticated = isLoggedIn();
$expected = in_array($scenario, ['demoted', 'active'], true);
if ($authenticated !== $expected) throw new RuntimeException('Unexpected authentication result');
if ($scenario === 'demoted' && can('user.manage')) throw new RuntimeException('Demoted session retained admin access');
if ($scenario === 'active' && !can('user.manage')) throw new RuntimeException('Admin access regressed');
if ($scenario === 'demoted') {
    foreach (['add_user.php', 'edit_user.php'] as $page) {
        $source = file_get_contents(dirname(__DIR__) . '/' . $page);
        $guard = strpos($source, "require_permission('user.manage')");
        $mutation = strpos($source, "if (\$_SERVER['REQUEST_METHOD']");
        if ($guard === false || $guard > $mutation) throw new RuntimeException("Missing early user.manage gate: $page");
    }
}
echo "PASS $scenario\n";
