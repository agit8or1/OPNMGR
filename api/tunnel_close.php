<?php
// Tunnel Close - Server calls this to terminate tunnel
require_once __DIR__ . '/../inc/bootstrap.php';

require_once __DIR__ . '/../inc/logging.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['request_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing request_id']);
    exit;
}

$request_id = (int)$input['request_id'];

try {
    // Get tunnel info
    $stmt = db()->prepare("SELECT firewall_id, tunnel_pid, tunnel_port, status FROM request_queue WHERE id = ?");
    $stmt->execute([$request_id]);
    $tunnel = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tunnel) {
        http_response_code(404);
        echo json_encode(['error' => 'Tunnel not found']);
        exit;
    }
    
    // Mark as closed
    db()->prepare("UPDATE request_queue SET status = 'completed', completed_at = NOW() WHERE id = ?")
       ->execute([$request_id]);
    
    // Send kill command to agent if tunnel_pid exists
    if ($tunnel['tunnel_pid']) {
        $kill_cmd = "kill -9 {$tunnel['tunnel_pid']} 2>/dev/null";
        db()->prepare("INSERT INTO firewall_commands (firewall_id, command, description, status) VALUES (?, ?, 'Close tunnel', 'pending')")
           ->execute([$tunnel['firewall_id'], $kill_cmd]);
        
        log_info('tunnel', "Tunnel close command sent: request_id=$request_id, pid={$tunnel['tunnel_pid']}", null, $tunnel['firewall_id']);
    }
    
    echo json_encode(['success' => true, 'message' => 'Tunnel closed']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
