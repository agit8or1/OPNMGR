<?php
/**
 * Public, token-authenticated report view (no login). Only works while
 * settings.report_share_enabled = 1 and the link is neither expired nor revoked.
 * Responses never distinguish "wrong" from "unknown" tokens beyond a generic 404/410.
 * @since 3.79.0
 */
require_once __DIR__ . '/inc/bootstrap_agent.php';
require_once __DIR__ . '/inc/reports/actions.php';

header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
$token = (string)($_GET['t'] ?? '');
$fmt = (string)($_GET['fmt'] ?? 'html');
$res = report_share_resolve($token);
if (!$res['ok'] || !in_array($fmt, ['html', 'csv', 'pdf'], true)) {
    $gone = in_array($res['reason'] ?? '', ['expired', 'revoked'], true);
    http_response_code($gone ? 410 : 404);
    header('Content-Type: text/plain; charset=utf-8');
    echo $gone ? 'This link has expired or been revoked.' : 'Not found';
    exit;
}
report_audit('report.share.access', ['actor_type' => 'anonymous', 'object_type' => 'report', 'object_id' => $res['report']['id'],
             'customer_id' => $res['report']['customer_id'] === null ? null : (int)$res['report']['customer_id'], 'message' => 'Share link used (' . $fmt . ')', 'metadata' => ['share_id' => $res['share_id']]]);
report_serve($res['report'], $fmt, $fmt === 'html');
