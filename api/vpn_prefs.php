<?php
/** Per-user map layout and filter preferences, stored server-side. */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/vpn/vpn.php';

requireLogin();
require_permission('vpn.view');
header('Content-Type: application/json');
$uid = (int)($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['success' => true, 'prefs' => vpn_prefs_get($uid)]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (!csrf_verify($in['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403); echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']); exit;
}
$r = vpn_prefs_save($uid, (array)($in['prefs'] ?? []));
echo json_encode(['success' => $r['ok'], 'message' => $r['error'], 'prefs' => $r['prefs'] ?? null]);
