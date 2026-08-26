<?php
require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/agent_auth.php';

header('Content-Type: application/json');
// Get parameters
$firewall_id = (int)($_GET['firewall_id'] ?? 0);
$hardware_id = trim($_GET['hardware_id'] ?? '');
$status = $_GET['status'] ?? null;
$result = $_GET['result'] ?? null;

if (!$firewall_id || !$status || empty($hardware_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

// Validate agent identity
try {
    // Centralised agent authentication: identity resolution, hardware_id
    // pinning, API key verification and HMAC signature checking all live in
    // inc/agent_auth.php. It emits a generic error and exits on failure.
    $authenticated_firewall = authenticateAgentRequest(
        array_merge(is_array($_GET) ? $_GET : [], ['firewall_id' => $firewall_id])
    );
    $firewall_id = (int)$authenticated_firewall['id'];
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Authentication error']);
    exit;
}

try {
    // Update the most recent sent command for this firewall
    $stmt = db()->prepare("
        UPDATE updater_commands 
        SET status = ?, completed_at = NOW(), result = ?
        WHERE firewall_id = ? AND status = 'sent'
        ORDER BY sent_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$status, $result, $firewall_id]);
    
    // Log the completion
    $log_stmt = db()->prepare("
        INSERT INTO system_logs (firewall_id, category, message, timestamp)
        VALUES (?, 'updater', ?, NOW())
    ");
    $log_stmt->execute([$firewall_id, "Updater command completed: $status - $result"]);
    
    echo json_encode(['success' => true, 'message' => 'Completion reported']);
    
} catch (Exception $e) {
    error_log("Report updater completion error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error reporting completion']);
}
?>