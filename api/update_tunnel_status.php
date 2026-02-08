<?php
/**
 * Update Tunnel Status API
 * Called by agents to update the status of proxy tunnel requests
 */

require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

$request_id = (int)($input['request_id'] ?? 0);
$status = trim($input['status'] ?? '');
$tunnel_pid = (int)($input['tunnel_pid'] ?? 0);

if (!$request_id || !$status) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

// Validate status
$allowed_statuses = ['processing', 'failed', 'timeout', 'completed'];
if (!in_array($status, $allowed_statuses)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status']);
    exit;
}

try {
    // Update request status
    $stmt = $DB->prepare('UPDATE request_queue SET status = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$status, $request_id]);
    
    // Log the update
    if ($tunnel_pid > 0) {
        $stmt = $DB->prepare("
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
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
