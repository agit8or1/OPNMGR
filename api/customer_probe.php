<?php
/**
 * Customer probe endpoint.
 *
 *   GET   the checks this probe should run
 *   POST  {"probe_version": "...", "results": [{"check_id": 1, "ok": true, "latency_ms": 12, "detail": "..."}]}
 *
 * Authenticated by the probe's own token (Authorization: Bearer opp_...), not
 * a session: a probe has no user and no role. A token reads only its own
 * probe's checks and writes only results for them; anything else is dropped.
 *
 * @since 3.81.0
 */
require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/customer_checks.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$token = preg_match('/^Bearer\s+(\S+)$/', $auth, $m) ? $m[1] : (string)($_SERVER['HTTP_X_OPNMGR_PROBE_TOKEN'] ?? '');

$probe = cc_probe_by_token($token);
if ($probe === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unknown, disabled or malformed probe token']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['success' => true] + cc_probe_config($probe), JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// One report per ~10 s is ample for a 30 s minimum interval; more is a loop.
if ($probe['last_seen_at'] !== null && time() - strtotime((string)$probe['last_seen_at']) < 10) {
    http_response_code(429);
    header('Retry-After: 10');
    echo json_encode(['success' => false, 'message' => 'Reporting too often']);
    exit;
}

$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 32768) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'Report too large']);
    exit;
}
$in = json_decode($raw, true);
if (!is_array($in) || !is_array($in['results'] ?? null)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Expected {"results": [...]}']);
    exit;
}

$r = cc_record_results($probe, $in['results'], isset($in['probe_version']) ? (string)$in['probe_version'] : null,
                       (string)($_SERVER['REMOTE_ADDR'] ?? ''));
echo json_encode(['success' => true, 'accepted' => $r['accepted'], 'rejected' => $r['rejected'],
                  'interval_seconds' => max(30, (int)$probe['interval_seconds'])]);
