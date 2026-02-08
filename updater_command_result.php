<?php
/**
 * Updater Command Result API
 * Receives command execution results from updater services
 */

require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $firewall_id = $input['firewall_id'] ?? null;
    $command_id = $input['command_id'] ?? null;
    $status = $input['status'] ?? null; // 'success' or 'failed'
    $result = $input['result'] ?? '';
    
    if (!$firewall_id || !$command_id || !$status) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: firewall_id, command_id, status']);
        exit;
    }
    
    // Validate firewall exists
    $stmt = $DB->prepare("SELECT id FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Firewall not found']);
        exit;
    }
    
    // Validate command exists and belongs to this firewall
    $stmt = $DB->prepare("SELECT id, command_type, description FROM updater_commands WHERE id = ? AND firewall_id = ?");
    $stmt->execute([$command_id, $firewall_id]);
    $command = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$command) {
        http_response_code(404);
        echo json_encode(['error' => 'Command not found']);
        exit;
    }
    
    // Update command status
    $stmt = $DB->prepare("
        UPDATE updater_commands 
        SET status = ?, completed_at = NOW(), result = ? 
        WHERE id = ?
    ");
    $stmt->execute([$status, $result, $command_id]);
    
    // Log the command completion
    $log_message = "Updater command {$command['description']} " . ($status === 'success' ? 'completed successfully' : 'failed');
    if ($result) {
        $log_message .= ": " . substr($result, 0, 200);
    }
    
    $stmt = $DB->prepare("
        INSERT INTO system_logs (firewall_id, category, message, level, timestamp) 
        VALUES (?, 'updater', ?, ?, NOW())
    ");
    $level = ($status === 'success') ? 'INFO' : 'ERROR';
    $stmt->execute([$firewall_id, $log_message, $level]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Command result recorded',
        'command_id' => $command_id,
        'status' => $status
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to record command result: ' . $e->getMessage()]);
}
?>