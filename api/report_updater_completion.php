<?php
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');
// Get parameters
$firewall_id = $_GET['firewall_id'] ?? null;
$status = $_GET['status'] ?? null;
$result = $_GET['result'] ?? null;

if (!$firewall_id || !$status) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
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