<?php
/**
 * Deactivating an account has to actually stop the account.
 *
 * `users.is_active` sat in the schema with nothing reading it. Setting it to 0
 * looked like disabling a user and did nothing whatsoever - they kept logging
 * in. There was no control for it in the interface either, so the only way to
 * stop someone was to delete them, which also destroys the record of what they
 * did.
 *
 * Two properties matter and both are easy to get half-right: a disabled account
 * must be refused at login, *and* the sessions it already holds must end -
 * otherwise "disable this user" quietly means "disable them at their next
 * login", which is not what anyone reaching for it needs.
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

$root = dirname(__DIR__);
$auth = (string) @file_get_contents($root . '/inc/auth.php');
$users = (string) @file_get_contents($root . '/users.php');

check('inc/auth.php is readable', $auth !== '');
check('users.php is readable', $users !== '');

// ---------------------------------------------------------------------------
// 1. Login refuses a disabled account.
// ---------------------------------------------------------------------------

check('login() reads is_active',
    preg_match('/function login\b.*?is_active/s', $auth) === 1,
    'the column existed for a long time with nothing consulting it');

check('the check runs after password verification',
    preg_match('/password_verify\([^)]*\).*?is_active/s', $auth) === 1,
    'a wrong password and a disabled account should look the same from outside');

check('a refused login is audited',
    strpos($auth, 'Login refused: account is deactivated') !== false);

// ---------------------------------------------------------------------------
// 2. Sessions already open end too.
// ---------------------------------------------------------------------------

check('isLoggedIn() re-checks account status',
    preg_match('/function isLoggedIn\b.*?is_active/s', $auth) === 1,
    'otherwise disabling a user only takes effect at their next login');

check('the re-check is rate limited rather than per request',
    strpos($auth, 'active_checked_at') !== false,
    'one query per request per session is a real cost');

check('a deleted account also ends the session',
    preg_match('/\$row === false/', $auth) === 1);

check('a database failure does not log everyone out',
    preg_match('/is_active.*?catch \(Throwable/s', $auth) === 1,
    'a blip must not become a mass logout');

// ---------------------------------------------------------------------------
// 3. The control exists, and cannot lock the installation out.
// ---------------------------------------------------------------------------

check('users.php offers activate/deactivate',
    strpos($users, "set_active") !== false);

check('you cannot deactivate your own account',
    preg_match('/set_active.*?cannot deactivate your own account/s', $users) === 1);

check('the last active administrator is protected',
    preg_match("/role = 'admin' AND is_active = 1 AND id <> \\?/", $users) === 1,
    'deactivating it would leave nobody able to administer the installation');

check('the change is audited',
    preg_match('/user\.(de)?activate/', $users) === 1);

// ---------------------------------------------------------------------------
// 4. The listing shows what it claims to.
// ---------------------------------------------------------------------------

check('the user listing selects is_active',
    preg_match('/SELECT[^"]*is_active[^"]*FROM users/i', $users) === 1,
    'without it every account renders as Active regardless of the column');

check('the user listing selects last_login',
    preg_match('/SELECT[^"]*last_login[^"]*FROM users/i', $users) === 1,
    'an account that has never signed in is worth seeing');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
