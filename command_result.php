<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/logging.php';

// API endpoint for agents to report command execution results
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit;
}

$command_id = (int)($input['command_id'] ?? 0);
$exit_code = (int)($input['exit_code'] ?? -1);
$result = trim($input['result'] ?? '');
$timestamp = $input['timestamp'] ?? date('c');

if (!$command_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing command_id']);
    exit;
}

try {
    // Update the command with the result
    $stmt = $DB->prepare("UPDATE agent_commands SET status = ?, result = ?, completed_at = NOW(), exit_code = ? WHERE command_id = ?");
    $status = ($exit_code == 0) ? 'completed' : 'failed';
    $stmt->execute([$status, $result, $exit_code, $command_id]);
    
    if ($stmt->rowCount() > 0) {
        // Get firewall info for logging
        $stmt = $DB->prepare("SELECT f.hostname FROM agent_commands ac JOIN firewalls f ON ac.firewall_id = f.id WHERE ac.command_id = ?");
        $stmt->execute([$command_id]);
        $hostname = $stmt->fetchColumn() ?: 'unknown';
        
        log_action('Command Result', 'INFO', "Command $command_id completed with exit code $exit_code", $hostname, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        
        echo json_encode(['success' => true, 'message' => 'Result recorded']);
    } else {
        log_action('Command Result', 'WARNING', "Command $command_id not found or already completed", 'unknown', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        echo json_encode(['success' => false, 'message' => 'Command not found or already completed']);
    }
    
} catch (Exception $e) {
    log_action('Command Result', 'ERROR', 'Database error: ' . $e->getMessage(), 'unknown', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>