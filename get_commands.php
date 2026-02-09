<?php
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json');

$firewall_id = (int)($_GET['firewall_id'] ?? 0);

if (!$firewall_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing firewall_id']);
    exit;
}

try {
    // Get pending commands for this firewall
    $stmt = $DB->prepare('SELECT id, command FROM firewall_commands WHERE firewall_id = ? AND status = "pending" ORDER BY created_at ASC LIMIT 1');
    $stmt->execute([$firewall_id]);
    $command = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($command) {
        // Mark command as sent (no updated_at column)
        $update_stmt = $DB->prepare('UPDATE firewall_commands SET status = "sent" WHERE id = ?');
        $update_stmt->execute([$command['id']]);
        
        echo json_encode([
            'success' => true,
            'has_command' => true,
            'command_id' => $command['id'],
            'command' => $command['command']
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'has_command' => false,
            'message' => 'No pending commands'
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log("get_commands.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
?>