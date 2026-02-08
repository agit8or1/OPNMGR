<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../inc/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$firewall_id = $input['firewall_id'] ?? null;
$command_type = $input['command_type'] ?? null;
$command = $input['command'] ?? null;
$description = $input['description'] ?? null;

if (!$firewall_id || !$command_type || !$command || !$description) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

// Validate command type
$valid_types = ['AGENT_UPDATE', 'SYSTEM_UPDATE', 'COMMAND'];
if (!in_array($command_type, $valid_types)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid command type']);
    exit;
}

try {
    // Verify firewall exists
    $stmt = $DB->prepare("SELECT hostname FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$firewall) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Firewall not found']);
        exit;
    }
    
    // Insert updater command
    $stmt = $DB->prepare("
        INSERT INTO updater_commands (firewall_id, command_type, command, description)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$firewall_id, $command_type, $command, $description]);
    
    $command_id = $DB->lastInsertId();
    
    // Log the action
    $log_stmt = $DB->prepare("
        INSERT INTO system_logs (firewall_id, category, message, timestamp)
        VALUES (?, 'updater', ?, NOW())
    ");
    $log_stmt->execute([$firewall_id, "Updater command queued: $command_type - $description"]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Updater command queued successfully',
        'command_id' => $command_id,
        'firewall' => $firewall['hostname']
    ]);
    
} catch (Exception $e) {
    error_log("Queue updater command error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error queuing command']);
}
?>