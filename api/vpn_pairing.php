<?php
/**
 * Operator pairing, un-pairing and classification of VPN endpoints.
 * Changes only this application's interpretation; no tunnel is touched.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/vpn/vpn.php';

requireLogin();
require_permission('vpn.manage');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (!csrf_verify($in['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403); echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']); exit;
}
$action = (string)($in['action'] ?? '');
foreach (['a', 'b'] as $k) {
    $p = vpn_endpoint_key_parts((string)($in[$k] ?? ''));
    if ($p && !scope_can_firewall($p['firewall_id'])) {
        http_response_code(403); echo json_encode(['success' => false, 'message' => 'No access to that firewall']); exit;
    }
}
$live = vpn_topology_live(false);
$r = vpn_pairing_apply($action, $in, (string)($_SESSION['username'] ?? 'unknown'), $live['topology']['endpoints']);
if (!$r['ok']) { http_response_code(400); }
echo json_encode(['success' => $r['ok'], 'message' => $r['error']]);
