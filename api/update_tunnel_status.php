<?php
/**
 * Update Tunnel Status API
 * Called by agents to update the status of proxy tunnel requests
 */
require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/agent_auth.php';

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

$request_id = (int)($input['request_id'] ?? 0);
$firewall_id = (int)($input['firewall_id'] ?? 0);
$hardware_id = trim($input['hardware_id'] ?? '');
$status = trim($input['status'] ?? '');
$tunnel_pid = (int)($input['tunnel_pid'] ?? 0);

if (!$request_id || !$status || !$firewall_id || empty($hardware_id)) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

// Validate agent identity
// Centralised agent authentication: identity resolution, hardware_id
// pinning, API key verification and HMAC signature checking all live in
// inc/agent_auth.php. It emits a generic error and exits on failure.
$authenticated_firewall = authenticateAgentRequest(
    array_merge(is_array($input) ? $input : [], ['firewall_id' => $firewall_id])
);
$firewall_id = (int)$authenticated_firewall['id'];

// Validate status
$allowed_statuses = ['processing', 'failed', 'timeout', 'completed'];
if (!in_array($status, $allowed_statuses)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status']);
    exit;
}

try {
    // Update request status
    $stmt = db()->prepare('UPDATE request_queue SET status = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$status, $request_id]);
    
    // Log the update
    if ($tunnel_pid > 0) {
        $stmt = db()->prepare("
            INSERT INTO system_logs (level, category, message, additional_data, timestamp)
            VALUES ('INFO', 'tunnel', ?, ?, NOW())
        ");
        $stmt->execute([
            "Tunnel status updated: request_id=$request_id, status=$status",
            json_encode(['request_id' => $request_id, 'tunnel_pid' => $tunnel_pid, 'status' => $status])
        ]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Tunnel status updated',
        'request_id' => $request_id,
        'status' => $status
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    error_log("update_tunnel_status.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
