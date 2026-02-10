<?php
require_once __DIR__ . '/../inc/bootstrap.php';

requireLogin();

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$command_id = (int)($input['command_id'] ?? 0);

if (!$command_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid command ID']);
    exit;
}

try {
    // Verify the command exists and is cancellable (pending or sent status)
    $stmt = db()->prepare('SELECT id, firewall_id, status FROM firewall_commands WHERE id = ?');
    $stmt->execute([$command_id]);
    $command = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$command) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Command not found']);
        exit;
    }
    
    // Only allow cancelling pending or sent commands
    if (!in_array($command['status'], ['pending', 'sent'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Can only cancel pending or sent commands']);
        exit;
    }
    
    // Update command status to cancelled
    $stmt = db()->prepare('UPDATE firewall_commands SET status = ?, completed_at = NOW() WHERE id = ?');
    $result = $stmt->execute(['cancelled', $command_id]);
    
    if ($result) {
        error_log("User cancelled command $command_id for firewall {$command['firewall_id']}");
        echo json_encode(['success' => true, 'message' => 'Command cancelled successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to cancel command']);
    }
    
} catch (Exception $e) {
    error_log("cancel_command error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
