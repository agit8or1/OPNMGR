<?php
/**
 * Update Complete API
 * Update service reports completion
 */
require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/agent_auth.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$firewall_id = (int)($input['firewall_id'] ?? 0);
$hardware_id = trim($input['hardware_id'] ?? '');
$success = (bool)($input['success'] ?? false);


// Validate agent identity
// Centralised agent authentication: identity resolution, hardware_id
// pinning, API key verification and HMAC signature checking all live in
// inc/agent_auth.php. It emits a generic error and exits on failure.
$authenticated_firewall = authenticateAgentRequest(
    array_merge(is_array($input) ? $input : [], ['firewall_id' => $firewall_id])
);
$firewall_id = (int)$authenticated_firewall['id'];

try {
    // Clear update request flag
    $stmt = db()->prepare('UPDATE firewalls SET update_requested = 0, status = ? WHERE id = ?');
    $stmt->execute([$success ? 'online' : 'update_failed', $firewall_id]);
    
    // Log the update
    $stmt = db()->prepare("INSERT INTO system_logs (firewall_id, category, message, level, timestamp) VALUES (?, 'system_update', ?, ?, NOW())");
    $message = $success ? 'System update completed successfully' : 'System update failed';
    $stmt->execute([$firewall_id, $message, $success ? 'INFO' : 'ERROR']);
    
    echo json_encode(['success' => true, 'message' => 'Update status recorded']);
} catch (Exception $e) {
    http_response_code(500);
    error_log("update_complete.php error: " . $e->getMessage());
    echo json_encode(['error' => 'Internal server error']);
}
?>
