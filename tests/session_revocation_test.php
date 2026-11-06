<?php
/**
 * Privileges follow the database, not the session.
 *
 * An already signed-in administrator who is demoted, reset or deactivated by
 * another administrator must lose that access on the next request. Each
 * "request" here is either a fresh endpoint subprocess carrying the old
 * session, or, in-process, auth_sync_session(true) to forget the per-request
 * cache - exactly what a new HTTP request does.
 *
 *   tests/lib/make_test_db.sh && DB_NAME=opnsense_fw_test php tests/session_revocation_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap.php';

$made = [];
register_shutdown_function(function () use (&$made) {
    foreach ($made as $id) {
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
});

function mk_user(string $role): array {
    global $made;
    $name = '__sess_' . $role . '_' . bin2hex(random_bytes(3));
    db()->prepare('INSERT INTO users (username, password, role, is_active) VALUES (?,?,?,1)')
        ->execute([$name, password_hash('correct horse', PASSWORD_DEFAULT), $role]);
    $id = (int)db()->lastInsertId();
    $made[] = $id;
    return ['id' => $id, 'username' => $name, 'role' => $role];
}

/** The session login() would have created for this user, as it is now. */
function session_for(array $u): array {
    $v = (int)db()->query('SELECT session_version FROM users WHERE id = ' . (int)$u['id'])->fetchColumn();
    return ['user_id' => $u['id'], 'username' => $u['username'], 'role' => $u['role'],
            'session_version' => $v, 'login_time' => time(), 'last_activity' => time(),
            'last_regenerated' => time()];
}

/** Become this session for one in-process "request". */
function act_as(array $session): void {
    $_SESSION = $session;
    auth_sync_session(true);
}

// ===========================================================================
T::group('Demotion takes effect on the next request (in-process)');

$alice = mk_user('admin');
$bob   = mk_user('admin');
$aliceSession = session_for($alice);

act_as($aliceSession);
T::ok(isLoggedIn() && can('user.manage') && isAdmin(), 'alice starts as an admin');

// Bob demotes alice. Alice's session still says admin.
db()->prepare("UPDATE users SET role = 'readonly' WHERE id = ?")->execute([$alice['id']]);
act_as($aliceSession);
T::ok(isLoggedIn(), 'alice is still signed in');
T::ok(!can('user.manage'), 'but no longer holds user.manage');
T::ok(!isAdmin(), 'and isAdmin() is false');
T::eq('readonly', current_role(), 'current_role() reflects the database');
act_as($aliceSession);
T::ok(!isAdmin(), 'isAdmin() alone, without requireLogin(), also sees the demotion');

// ===========================================================================
T::group('Password reset by another admin revokes existing sessions');

$carol = mk_user('admin');
$carolSession = session_for($carol);
act_as($carolSession);
T::ok(isLoggedIn(), 'carol is signed in');

act_as(session_for($bob));
auth_revoke_sessions((int)$carol['id'], 'password reset by an administrator', true);
T::ok(isLoggedIn(), "bob's own session survives resetting someone else");

act_as($carolSession);
T::ok(!isLoggedIn(), "carol's old session is rejected on its next request");

// Own password change: this session continues, the others end.
$dave = mk_user('technician');
$daveLaptop = session_for($dave);
$davePhone  = session_for($dave);
act_as($daveLaptop);
auth_revoke_sessions((int)$dave['id'], 'password changed', true);
$daveLaptop = $_SESSION;
act_as($daveLaptop);
T::ok(isLoggedIn(), 'changing your own password keeps the session you did it from');
act_as($davePhone);
T::ok(!isLoggedIn(), 'but ends your other sessions');

// ===========================================================================
T::group('Deactivation and deletion still end sessions');

$erin = mk_user('technician');
$erinSession = session_for($erin);
db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$erin['id']]);
act_as($erinSession);
T::ok(!isLoggedIn(), 'a deactivated account is signed out immediately (no 60 s grace)');

$frank = mk_user('technician');
$frankSession = session_for($frank);
db()->prepare('DELETE FROM users WHERE id = ?')->execute([$frank['id']]);
act_as($frankSession);
T::ok(!isLoggedIn(), 'a deleted account is signed out');

act_as(['user_id' => $bob['id'], 'username' => $bob['username'], 'role' => 'admin', 'login_time' => time(), 'last_activity' => time(), 'last_regenerated' => time()]);
T::ok(isLoggedIn(), 'a session from before migration 0030 (no version key) survives while the version is 0');
db()->prepare('UPDATE users SET session_version = session_version + 1 WHERE id = ?')->execute([$bob['id']]);
act_as(['user_id' => $bob['id'], 'username' => $bob['username'], 'role' => 'admin', 'login_time' => time(), 'last_activity' => time(), 'last_regenerated' => time()]);
T::ok(!isLoggedIn(), 'and is revoked once the version moves');

// ===========================================================================
T::group('Across real requests (endpoint subprocesses)');

$gina = mk_user('admin');
$ginaSession = session_for($gina);
$r = call_endpoint('users.php', ['method' => 'GET', 'session' => $ginaSession]);
T::eq(200, $r['status'], 'an admin session can open users.php');

db()->prepare("UPDATE users SET role = 'technician' WHERE id = ?")->execute([$gina['id']]);
$r = call_endpoint('users.php', ['method' => 'GET', 'session' => $ginaSession]);
T::ok($r['status'] !== 200 || !str_contains($r['body'], 'Add User'), 'the demoted admin is refused users.php on the next request (' . $r['status'] . ')');

$hank = mk_user('admin');
$hankSession = session_for($hank);
auth_revoke_sessions((int)$hank['id'], 'test reset');
$r = call_endpoint('users.php', ['method' => 'GET', 'session' => $hankSession]);
T::ok($r['status'] !== 200 || str_contains($r['body'], 'login'), 'a reset admin is sent back to login (' . $r['status'] . ')');

// ===========================================================================
T::group('edit_user.php requires user.manage');

$ro = mk_user('readonly');
$target = mk_user('readonly');
$r = call_endpoint('edit_user.php', [
    'method' => 'POST', 'session' => session_for($ro) + ['csrf_token' => 'x'],
    'get' => ['id' => $ro['id']], 'post' => ['role' => 'admin', 'csrf' => 'x'],
]);
$role = db()->query('SELECT role FROM users WHERE id = ' . (int)$ro['id'])->fetchColumn();
T::eq('readonly', $role, 'a read-only user cannot promote themselves to admin');
T::ok(in_array($r['status'], [302, 403], true), 'and is refused (' . $r['status'] . ')');

exit(T::summary());
