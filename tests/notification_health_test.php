<?php
/**
 * A monitoring system that cannot deliver has to say so.
 *
 * The alerting pipeline worked and nobody knew it was useless: it detected
 * conditions, raised incidents, and recorded 911 notifications with status
 * 'failed' and not one 'sent', going back to the first row in the table. The
 * SMTP credential had been rejected the entire time. Nothing surfaced it - no
 * banner, no tile, no health signal - and the only description of why sat in a
 * log file, because send_smtp_email() replaced the server's response with
 * "Internal server error".
 *
 * That is worse than no alerting, because the operator believes they are
 * covered. This suite pins the parts that make the failure visible.
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
require_once $root . '/inc/notification_health.php';

/** Newest first, as the query returns them. */
function row(string $status, string $when, string $method = 'email', ?string $err = null): array
{
    return ['notification_method' => $method, 'status' => $status, 'sent_at' => $when, 'error_message' => $err];
}

// ---------------------------------------------------------------------------
// 1. A channel that has never delivered.
// ---------------------------------------------------------------------------

$never = notification_health_from_rows([
    row('failed', '2026-09-15 17:00:00', 'email', '535-5.7.8 Username and Password not accepted'),
    row('failed', '2026-09-15 16:00:00'),
    row('failed', '2026-09-15 15:00:00'),
    row('failed', '2026-09-15 14:00:00'),
]);

check('one channel is reported', count($never) === 1);
check('all four failures are counted', ($never[0]['consecutive_failures'] ?? 0) === 4);
check('it is not ok', ($never[0]['ok'] ?? true) === false);
check('it is flagged as never delivered', ($never[0]['never_delivered'] ?? false) === true);
check('the last error is carried', strpos((string) ($never[0]['last_error'] ?? ''), '535') !== false);

// ---------------------------------------------------------------------------
// 2. A channel that is working.
// ---------------------------------------------------------------------------

$healthy = notification_health_from_rows([
    row('sent', '2026-09-15 17:00:00'),
    row('sent', '2026-09-15 16:00:00'),
    row('failed', '2026-09-15 15:00:00'),
]);

check('a delivering channel is ok', ($healthy[0]['ok'] ?? false) === true);
check('failures before the last success are not counted',
    ($healthy[0]['consecutive_failures'] ?? -1) === 0,
    'got ' . var_export($healthy[0]['consecutive_failures'] ?? null, true));
check('it is not flagged as never delivered', ($healthy[0]['never_delivered'] ?? true) === false);
check('the last success is recorded', ($healthy[0]['last_success'] ?? '') === '2026-09-15 17:00:00');

// A failing run that follows a success is an outage, not a misconfiguration.
$outage = notification_health_from_rows([
    row('failed', '2026-09-15 17:00:00'),
    row('failed', '2026-09-15 16:00:00'),
    row('failed', '2026-09-15 15:00:00'),
    row('sent',   '2026-09-15 14:00:00'),
]);
check('an outage counts only failures since the last success',
    ($outage[0]['consecutive_failures'] ?? 0) === 3);
check('an outage is not "never delivered"', ($outage[0]['never_delivered'] ?? true) === false);
check('three consecutive failures is not ok', ($outage[0]['ok'] ?? true) === false);

// 'partial' counts as delivery: somebody was told.
$partial = notification_health_from_rows([row('partial', '2026-09-15 17:00:00')]);
check('partial delivery counts as delivered', ($partial[0]['ok'] ?? false) === true);

// Below the limit, a couple of failures is a blip rather than a broken channel.
$blip = notification_health_from_rows([
    row('failed', '2026-09-15 17:00:00'),
    row('sent',   '2026-09-15 16:00:00'),
]);
check('a single failure is not reported as broken', ($blip[0]['ok'] ?? false) === true);

// Channels are tracked separately.
$mixed = notification_health_from_rows([
    row('failed', '2026-09-15 17:00:00', 'email'),
    row('failed', '2026-09-15 16:55:00', 'email'),
    row('failed', '2026-09-15 16:50:00', 'email'),
    row('sent',   '2026-09-15 16:00:00', 'pushover'),
]);
check('channels are tracked independently', count($mixed) === 2);
$byName = [];
foreach ($mixed as $c) { $byName[$c['channel']] = $c; }
check('the failing channel is flagged', ($byName['email']['ok'] ?? true) === false);
check('the working channel is not', ($byName['pushover']['ok'] ?? false) === true);

// No history at all is not a failure - nothing has been attempted.
check('an empty history reports nothing', notification_health_from_rows([]) === []);
check('no problems when nothing has failed', notification_health_from_rows([row('sent', '2026-09-15 17:00:00')])[0]['ok'] === true);

// ---------------------------------------------------------------------------
// 3. The banner.
// ---------------------------------------------------------------------------

$header = (string) @file_get_contents($root . '/inc/header.php');
check('the banner is rendered from the shared header',
    strpos($header, 'notification_health_banner') !== false,
    'otherwise it only appears on whichever page remembered to ask');
check('the banner is limited to administrators',
    preg_match("/can\('settings\.manage'\).*notification_health/s", $header) === 1);
check('a failing banner cannot take the page down',
    preg_match('/notification_health_banner.*catch \(Throwable/s', $header) === 1);

$src = (string) file_get_contents($root . '/inc/notification_health.php');
check('the banner says it cannot reach you by the broken channel',
    stripos($src, 'cannot be emailed') !== false);
check('the banner is not dismissible',
    strpos($src, 'alert-dismissible') === false,
    'a banner an operator can wave away is how 911 undelivered alerts go unnoticed');

// ---------------------------------------------------------------------------
// 4. The SMTP error must be diagnosable, and must not carry a credential.
// ---------------------------------------------------------------------------

$mailer = (string) @file_get_contents($root . '/inc/smtp_mailer.php');
check('send_smtp_email no longer returns only "Internal server error"',
    strpos($mailer, "'error' => 'Internal server error'") === false,
    'that string is why a rejected credential was undiagnosable for 911 deliveries');
check('it returns a redacted error instead',
    strpos($mailer, 'smtp_safe_error(') !== false);

require_once $root . '/inc/smtp_mailer.php';
check('smtp_safe_error is defined', function_exists('smtp_safe_error'));

if (function_exists('smtp_safe_error')) {
    $kept = smtp_safe_error('Authentication failed: 535-5.7.8 Username and Password not accepted');
    check('the SMTP response code survives redaction', strpos($kept, '535-5.7.8') !== false);
    check('the human-readable reason survives', stripos($kept, 'not accepted') !== false);

    $secret = 'dXNlcm5hbWU6cGFzc3dvcmRzZWNyZXQxMjM0NQ==';
    $red = smtp_safe_error('AUTH failed: 334 ' . $secret);
    check('a base64 credential is redacted', strpos($red, 'dXNlcm5hbWU') === false, $red);
    check('the redaction is visible', strpos($red, '[redacted]') !== false);

    check('a connection error survives intact',
        strpos(smtp_safe_error('Failed to connect to SMTP server: Connection refused (111)'), 'Connection refused') !== false);
    check('the message is bounded', strlen(smtp_safe_error(str_repeat('x', 5000))) <= 500);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
