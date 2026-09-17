<?php
/**
 * Alerts that are raised must actually be delivered.
 *
 * Run with: php tests/smtp_delivery_test.php
 *
 * 8,497 alert notifications failed, every one of them, with
 * `535 Username and Password not accepted`. The credentials were correct. They
 * are stored encrypted as `enc:v1:...` and nothing decrypted them before AUTH,
 * so the ciphertext was being offered as the password.
 *
 * The one decrypt attempt in the mail path called `decrypt_setting_value()`, a
 * function that does not exist in this codebase and never has, wrapped in
 * `function_exists()` - so the guard was permanently false and quietly did
 * nothing.
 *
 * It went undiagnosed for as long as it did because the configuration page's
 * test button opened a TCP socket, closed it, and reported success. It never
 * authenticated, so it passed with the wrong password, with no password, with
 * no username at all. The single failure it could not detect was the only thing
 * wrong.
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

$root   = dirname(__DIR__);
$mailer = (string) @file_get_contents($root . '/inc/smtp_mailer.php');
$page   = (string) @file_get_contents($root . '/smtp_settings.php');

check('inc/smtp_mailer.php is readable', $mailer !== '');

/** Code only: the fix documents the bug it replaced, naming the dead function. */
$code = static function (string $src): string {
    return implode("\n", array_filter(explode("\n", $src), static function (string $l): bool {
        $t = ltrim($l);
        return $t !== '' && !str_starts_with($t, '//') && !str_starts_with($t, '*')
            && !str_starts_with($t, '/*') && !str_starts_with($t, '#');
    }));
};
$mailerCode = $code($mailer);
$pageCode   = $code($page);

// --- credentials must be decrypted before they are used ----------------------

check('the mailer pulls in the crypto helpers',
    (bool) preg_match("/require_once __DIR__ \. '\/crypto\.php'/", $mailer),
    'without opnmgr_decrypt() every stored secret reaches the server as ciphertext');

check('a single helper turns a stored secret into a usable one',
    str_contains($mailerCode, 'function smtp_plain_secret'));

check('the password is decrypted before AUTH',
    (bool) preg_match('/\$password = smtp_plain_secret\(/', $mailerCode),
    'this is the line whose absence caused 8,497 failures');
check('the username is too', (bool) preg_match('/\$username = smtp_plain_secret\(/', $mailerCode));

check('the raw setting is no longer taken as the password',
    !preg_match('/\$password = \$smtp_settings\[.smtp_password.\];/', $mailerCode));

check('a function that does not exist is not called',
    !str_contains($mailerCode, 'decrypt_setting_value'),
    'function_exists() around a nonexistent name is a no-op that reads as a safeguard');

check('a failed decryption sends nothing rather than the ciphertext',
    (bool) preg_match("/if \(\\\$plain === null\) \{[\s\S]{0,220}return '';/", $mailerCode),
    'offering an encrypted blob as a password produces a 535 that reads like a wrong password');

// --- the test button must be able to fail ------------------------------------

check('a credential verifier exists', str_contains($mailerCode, 'function smtp_verify_credentials'));
check('it authenticates', str_contains($mailerCode, 'AUTH LOGIN') && str_contains($mailerCode, '235'));
check('it sends no mail',
    !preg_match('/smtp_verify_credentials[\s\S]{0,4000}?\bDATA\b/', $mailerCode),
    'a connectivity test must not deliver anything');
check('it reports an authentication failure distinctly',
    str_contains($mailerCode, 'Authentication failed: '));
check('its errors are passed through redaction',
    (bool) preg_match('/return smtp_safe_error\(/', $mailerCode));

check('the settings page test authenticates rather than opening a socket',
    str_contains($pageCode, 'smtp_verify_credentials('),
    'fsockopen+fclose passes with no credentials at all');
check('the socket-only test is gone',
    !preg_match('/fsockopen\([^)]*\);[\s\S]{0,200}fclose\(\$socket\);[\s\S]{0,60}return true;/', $pageCode),
    'it could not detect the one failure it was used to rule out');

// --- the redaction helper itself ---------------------------------------------

check('redaction decrypts before comparing',
    (bool) preg_match("/function_exists\('opnmgr_decrypt'\)/", $mailerCode),
    'comparing the ciphertext against the message redacts nothing');

// --- an incident whose object disappears must close ---------------------------
//
// Restoring delivery released a backlog: twelve open incidents mailed at once.
// Ten of them were stale. Each condition resolves by iterating what the agent
// currently reports, so an object that stops being reported is never visited and
// its incident stays open forever - still counting toward the repeat limit.
// health_ingest_services() deletes rows the agent stops reporting, so eight
// `service.stopped` incidents outlived the rows that justified them.

$eval = (string) @file_get_contents($root . '/cron/evaluate_alerts.php');

check('cron/evaluate_alerts.php is readable', $eval !== '');
check('a sweep for vanished objects exists', str_contains($eval, 'function resolve_vanished'));
check('services are swept', str_contains($eval, "resolve_vanished('service.stopped'"));
check('vpn tunnels are swept', str_contains($eval, "resolve_vanished('vpn.down'"));
check('the sweep compares against what was reported this run',
    str_contains($eval, '$seenServices[] =') && str_contains($eval, '$seenTunnels[] ='),
    'sweeping without a seen-set would close incidents that are still valid');
check('the sweep is skipped when the agent is stale',
    (bool) preg_match('/if \(!\$stale\) \{\s*resolve_vanished/', $eval),
    'a firewall that has stopped checking in reports nothing, which is not the same as nothing being wrong');

check('incidents against deleted firewalls are closed',
    str_contains($eval, 'firewall no longer exists'),
    'nothing iterates a deleted firewall, so its incidents can never resolve');
check('that sweep respects dry-run',
    (bool) preg_match('/if \(!\$dryRun\) \{[\s\S]{0,900}firewall no longer exists/', $eval),
    'the guard and the update are separated by the query that finds the orphans');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
