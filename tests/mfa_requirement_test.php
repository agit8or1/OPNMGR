<?php
/**
 * require_mfa_for_admins has to do something.
 *
 * It sat in the settings table with no line of code reading it. An operator who
 * turned it on got a stored 1 and no change in behaviour - the same shape as
 * users.is_active before 3.37.0, and as two-factor enforcement itself before
 * 3.35.0, where login() never read totp_secret at all.
 *
 * The design constraint that matters: this setting applies to administrators,
 * so getting it wrong strands the person who turned it on. It therefore sends
 * an un-enrolled administrator to enrol rather than refusing them, and the
 * enrolment page and logout stay reachable.
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
check('inc/auth.php is readable', $auth !== '');

// ---------------------------------------------------------------------------
// 1. The setting is read at all.
// ---------------------------------------------------------------------------

check('the setting is consulted',
    strpos($auth, "'require_mfa_for_admins'") !== false,
    'it existed in the settings table with nothing reading it');

check('it is evaluated on every authenticated request',
    preg_match('/function requireLogin.*?mfa_enrolment_required\(\)/s', $auth) === 1,
    'checking only at login would leave existing sessions unaffected');

// ---------------------------------------------------------------------------
// 2. It applies to administrators, and only when they lack a second factor.
// ---------------------------------------------------------------------------

check('it applies to the admin role only',
    preg_match("/function mfa_enrolment_required.*?role.*?'admin'/s", $auth) === 1);

check('an already-enrolled administrator is unaffected',
    preg_match('/function mfa_enrolment_required.*?totp_secret/s', $auth) === 1);

// ---------------------------------------------------------------------------
// 3. It cannot strand the operator who enabled it.
// ---------------------------------------------------------------------------

foreach (['twofactor_setup.php', 'logout.php', 'login.php', 'verify2fa.php'] as $page) {
    check("{$page} stays reachable", strpos($auth, "'{$page}'") !== false,
        'an administrator sent to enrol needs somewhere to do it and a way out');
}

check('the response is a redirect to enrol, not a refusal',
    strpos($auth, '/twofactor_setup.php?required=1') !== false,
    'refusing access would lock out the person who turned this on');

check('a database error does not lock administrators out',
    preg_match('/function mfa_enrolment_required.*?catch \(Throwable.*?return false/s', $auth) === 1,
    'failing closed here strands everyone on a settings read error');

// ---------------------------------------------------------------------------
// 4. API callers get JSON rather than a redirect to an HTML page.
// ---------------------------------------------------------------------------

check('API requests are answered with JSON',
    preg_match("#mfa_enrolment_required.*?'/api/'.*?json_encode#s", $auth) === 1);

// ---------------------------------------------------------------------------
// 5. The enrolment page explains why the operator is there.
// ---------------------------------------------------------------------------

$setup = (string) @file_get_contents($root . '/twofactor_setup.php');
check('the enrolment page explains the requirement',
    strpos($setup, 'requires administrators') !== false);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
