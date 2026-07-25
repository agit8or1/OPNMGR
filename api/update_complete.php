<?php
/**
 * Update Complete API
 * Update service reports completion
 */
require_once __DIR__ . '/../inc/bootstrap_agent.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$firewall_id = (int)($input['firewall_id'] ?? 0);
$hardware_id = trim($input['hardware_id'] ?? '');
$success = (bool)($input['success'] ?? false);

if (!$firewall_id || empty($hardware_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing firewall_id or hardware_id']);
    exit;
}

// Validate agent identity
$auth_stmt = db()->prepare('SELECT hardware_id FROM firewalls WHERE id = ?');
$auth_stmt->execute([$firewall_id]);
$auth_fw = $auth_stmt->fetch(PDO::FETCH_ASSOC);
if (!$auth_fw || (
    !empty($auth_fw['hardware_id']) && !hash_equals($auth_fw['hardware_id'], $hardware_id)
)) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication failed']);
    exit;
}

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
