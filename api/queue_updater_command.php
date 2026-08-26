<?php
require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/agent_auth.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$firewall_id = $input['firewall_id'] ?? null;
$hardware_id = trim($input['hardware_id'] ?? '');
$command_type = $input['command_type'] ?? null;
$command = $input['command'] ?? null;
$description = $input['description'] ?? null;

if (!$command_type || !$command || !$description) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

// Centralised agent authentication: identity resolution, hardware_id pinning,
// API key verification and HMAC signature checking all live in inc/agent_auth.php.
$authenticated_firewall = authenticateAgentRequest(
    array_merge(is_array($input) ? $input : [], ['firewall_id' => (int)$firewall_id])
);
$firewall_id = (int)$authenticated_firewall['id'];

// Validate command type.
//
// 'COMMAND' (free-form shell) was removed from the agent-facing path: an agent
// could previously queue arbitrary shell for itself, which turned any leaked
// agent credential into a persistent command-injection foothold and bypassed
// the operator audit trail entirely. Raw shell is queued by administrators
// through api/queue_command.php, which records who asked for it.
$valid_types = ['AGENT_UPDATE', 'SYSTEM_UPDATE'];
if (!in_array($command_type, $valid_types, true)) {
    audit_log_agent('command.queue.rejected', $firewall_id, [
        'success'  => false,
        'message'  => 'Agent attempted to queue a disallowed command type',
        'metadata' => ['command_type' => substr((string)$command_type, 0, 32)],
    ]);
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid command type']);
    exit;
}

try {
    // Verify firewall exists
    $stmt = db()->prepare("SELECT hostname FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$firewall) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Firewall not found']);
        exit;
    }
    
    // Insert updater command
    $stmt = db()->prepare("
        INSERT INTO updater_commands (firewall_id, command_type, command, description)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$firewall_id, $command_type, $command, $description]);
    
    $command_id = db()->lastInsertId();
    
    // Log the action
    $log_stmt = db()->prepare("
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