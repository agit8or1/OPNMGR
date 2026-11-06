<?php
/**
 * POST: queue (and, for plain reports, build) a customer report.
 * Needs report.generate, a valid CSRF token and customer scope over the target.
 * @since 3.79.0
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/scope.php';
require_once __DIR__ . '/../inc/reports/actions.php';

header('Content-Type: application/json');
requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'POST required']); exit; }
$in = json_decode((string)file_get_contents('php://input'), true);
$in = is_array($in) ? $in : $_POST;
if (!csrf_verify((string)($in['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']); exit; }
$r = report_action('generate', $in);
http_response_code($r['ok'] ? 200 : 400);
echo json_encode(['success' => $r['ok'], 'message' => $r['message']] + $r['data']);
