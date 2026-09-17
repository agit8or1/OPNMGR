<?php
/**
 * Changing a setting must leave a trace, and must not leak or lose a credential.
 *
 * An operator asked why the configured mail server was not the one they
 * remembered entering, and the system could not answer. `settings` has no
 * updated_at, `save_setting()` was defined twice - once in settings.php, once
 * in smtp_settings.php - and neither audited, so audit_log held 1,657 entries
 * without a single settings change among them.
 *
 * Two ways the credential itself could go wrong were in the same dialog:
 * settings.php rendered the decrypted SMTP password into the page as an input
 * value, and treated a blank field as "store the empty string" rather than
 * "unchanged" - so opening it to edit any other field and saving wiped the
 * password, untraceably.
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

// ---------------------------------------------------------------------------
// 1. No page echoes a stored credential back into its form.
// ---------------------------------------------------------------------------

foreach (['settings.php', 'smtp_settings.php', 'alerts.php'] as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) { continue; }
    $src = (string) file_get_contents($path);

    // A password input whose value attribute prints a PHP variable.
    $leaks = preg_match(
        '/<input[^>]*type=["\']password["\'][^>]*value=["\']\s*<\?php\s*echo/i',
        $src
    ) === 1;

    check("{$rel} does not print a stored credential into the page",
        !$leaks,
        'type="password" hides it on screen but the value is still in the page source');
}

// ---------------------------------------------------------------------------
// 2. A blank password field means "unchanged", never "wipe it".
// ---------------------------------------------------------------------------

foreach (['settings.php', 'smtp_settings.php'] as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) { continue; }
    $src = (string) file_get_contents($path);
    if (strpos($src, 'save_secret_setting') === false) { continue; }

    check("{$rel} treats a blank password as unchanged",
        preg_match('/\$smtp_password\s*===\s*[\'"]{2}/', $src) === 1
        && strpos($src, "get_secret_setting('smtp_password')") !== false,
        'otherwise editing any other SMTP field and saving clears the password');
}

// ---------------------------------------------------------------------------
// 3. save_setting() is shared and audited.
// ---------------------------------------------------------------------------

$secrets = (string) @file_get_contents($root . '/inc/secrets.php');
check('save_setting() is defined in inc/secrets.php',
    strpos($secrets, 'function save_setting') !== false);
check('save_setting() records the change',
    preg_match('/function save_setting.*?audit_log\(\s*[\'"]settings\.change/s', $secrets) === 1);
check('save_setting() records the previous value',
    preg_match('/function save_setting.*?\$previous/s', $secrets) === 1,
    'a change you cannot read back is only half a trail');
check('save_secret_setting() records credential changes',
    strpos($secrets, "settings.credential_change") !== false);

// The audit line for a credential must never carry the credential.
if (preg_match('/function save_secret_setting.*?\n    \}/s', $secrets, $m)) {
    $body = $m[0];
    // Comparing against $value to pick wording is fine; interpolating it into
    // the recorded message is not. Only the latter puts a credential in the log.
    check('the credential audit line does not contain the value',
        !preg_match('/\{\$value\}/', $body)
        && !preg_match('/[\'"]\s*\.\s*\$value/', $body),
        'an audit trail holding credentials is a second place to steal them from');
}

// Neither page may define its own copy again.
foreach (['settings.php', 'smtp_settings.php'] as $rel) {
    $src = (string) @file_get_contents($root . '/' . $rel);
    check("{$rel} does not redefine save_setting()",
        strpos($src, 'function save_setting') === false,
        'a private copy would bypass the audit trail');
}

// ---------------------------------------------------------------------------
// A CLI-initiated entry must say who acted
//
// CLI rows recorded actor_type 'system' with a NULL username, so the log said
// what had been done and to what, but never by whom. For a promotion that
// releases an agent to the whole fleet, "who" is most of the point.

$audit = (string) @file_get_contents($root . '/inc/audit.php');

check('a CLI operator resolver exists', str_contains($audit, 'function audit_cli_username'));
check('audit_log() falls back to it when there is no session',
    (bool) preg_match('/if \(\$username === null\) \{\s*\$username = audit_cli_username\(\);/', $audit),
    'every CLI call site benefits, not just the one that prompted this');
check('it applies only to CLI', str_contains($audit, "PHP_SAPI !== 'cli'"),
    'a web request must keep resolving its actor from the session');
check('actor_type is left alone',
    !preg_match("/actor_type.*=.*'operator'/", $audit),
    "actor_type is an ENUM; adding a value would need a migration");
check('the name is bounded to the column width',
    str_contains($audit, 'substr($name, 0, 64)'),
    'username is varchar(64)');

if (function_exists('shell_exec') && PHP_SAPI === 'cli') {
    require_once $root . '/inc/audit.php';
    $who = audit_cli_username();
    check('the resolver returns the invoking user', is_string($who) && $who !== '',
        'got ' . var_export($who, true));

    // Under sudo the effective user is root while the person is SUDO_USER, and
    // the entry should name both rather than recording the promotion as root.
    $sudoName = (string) shell_exec(
        'SUDO_USER=alice php -r ' . escapeshellarg(
            'require "' . $root . '/inc/audit.php"; echo audit_cli_username();'
        ) . ' 2>/dev/null'
    );
    check('a sudo invocation names the person, not just the effective user',
        str_starts_with($sudoName, 'alice'),
        'got ' . var_export($sudoName, true));
    check('...and still records what it ran as',
        str_contains($sudoName, 'sudo '),
        'got ' . var_export($sudoName, true));
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
