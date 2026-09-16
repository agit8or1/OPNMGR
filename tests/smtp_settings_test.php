<?php
/**
 * One editor for the mail settings, and it must save all of them.
 *
 * There were two. settings.php carried an SMTP modal that nothing ever opened -
 * no code referenced #smtpModal - behind a handler that saved host, port,
 * username, password and encryption but *not* the From address or From name,
 * which are fields the alert sender reads. smtp_settings.php saved all seven.
 *
 * Having two copies is how they drifted: settings.php ended up printing the
 * decrypted password into the page and wiping it on a blank save, while
 * smtp_settings.php had always handled both correctly. One editor, one set of
 * rules.
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

$root     = dirname(__DIR__);
$editor   = (string) @file_get_contents($root . '/smtp_settings.php');
$settings = (string) @file_get_contents($root . '/settings.php');

check('smtp_settings.php is readable', $editor !== '');
check('settings.php is readable', $settings !== '');

// ---------------------------------------------------------------------------
// 1. The editor saves every field the sender reads.
// ---------------------------------------------------------------------------

// inc/alerts.php and api/test_email.php read these from the settings table.
$fields = [
    'smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption',
    'smtp_from_email', 'smtp_from_name',
];
foreach ($fields as $f) {
    check("the editor persists {$f}",
        preg_match("/save_setting\(\s*'" . preg_quote($f, '/') . "'/", $editor) === 1);
}
check('the editor stores the password encrypted',
    strpos($editor, "save_secret_setting('smtp_password'") !== false);

// ---------------------------------------------------------------------------
// 2. There is only one editor.
// ---------------------------------------------------------------------------

check('settings.php no longer saves SMTP',
    !preg_match("/save_setting\(\s*'smtp_host'/", $settings)
    && !preg_match("/save_secret_setting\(\s*'smtp_password'/", $settings),
    'two editors for one set of settings is how they drifted apart');

check('the dead SMTP modal is gone from settings.php',
    strpos($settings, 'id="smtpModal"') === false,
    'nothing ever opened it, and its handler saved an incomplete set');

check('settings.php still links to the editor',
    strpos($settings, 'smtp_settings.php') !== false,
    'it has to be reachable from Settings');

// ---------------------------------------------------------------------------
// 3. Settings shows what is configured, not just that something is.
// ---------------------------------------------------------------------------

check('the SMTP card shows the configured host',
    preg_match('/SMTP Settings.*?\$smtp_host/s', $settings) === 1,
    'the page gave no hint which server it was pointed at');

check('the SMTP card shows delivery state',
    strpos($settings, 'notification_health_problems') !== false,
    'a server that is configured but failing every send should say so here');

// ---------------------------------------------------------------------------
// 4. No placeholder names a particular provider.
// ---------------------------------------------------------------------------
//
// Every field hinted at Gmail, which reads as a recommendation and made a
// wrongly-configured install look intentional.
foreach (['smtp_settings.php' => $editor, 'settings.php' => $settings] as $name => $src) {
    check("{$name} placeholders do not name a mail provider",
        !preg_match('/placeholder="[^"]*gmail[^"]*"/i', $src));
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
