<?php
/**
 * Send one clearly labelled TEST message to an integration destination.
 *
 * POST destination_id, csrf. Admin only (integration.manage). This is the only
 * code path that sends a message because a person asked; nothing calls it on a
 * timer or on page load.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/alerting.php';
require_once __DIR__ . '/../inc/integrations/delivery.php';

header('Content-Type: application/json');
require_permission('integration.manage');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}
if (!csrf_verify($_POST['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}
$id = (int)($_POST['destination_id'] ?? 0);
$d = integration_destination_load($id);
if (!$d || !integration_can_see_customer($d['customer_id'] !== null ? (int)$d['customer_id'] : null)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Unknown destination']);
    exit;
}
$r = integration_send_test($id, $_SESSION['username'] ?? 'unknown');
echo json_encode(['success' => $r['ok'], 'status' => $r['status'], 'message' => $r['ok'] ? 'Test message delivered' : $r['error']]);
