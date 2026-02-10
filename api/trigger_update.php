<?php
require_once __DIR__ . '/../inc/bootstrap.php';

requireLogin();
require_once __DIR__ . '/../inc/logging.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['firewall_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing firewall_id']);
        exit;
    }
    
    $firewall_id = (int)$input['firewall_id'];
    
    // Verify firewall exists
    $stmt = db()->prepare('SELECT id, hostname, status FROM firewalls WHERE id = ?');
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$firewall) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Firewall not found']);
        exit;
    }
    
    // Check if firewall is already updating
    if ($firewall['status'] === 'updating') {
        echo json_encode(['success' => false, 'message' => 'Firewall is already updating']);
        exit;
    }
    
    // Trigger update by setting the update_requested flag
    $stmt = db()->prepare('UPDATE firewalls SET update_requested = 1, update_requested_at = NOW() WHERE id = ?');
    $result = $stmt->execute([$firewall_id]);
    
    if ($result && $stmt->rowCount() > 0) {
        // Log the update trigger
        log_info('dashboard', "Update triggered via dashboard for firewall: {$firewall['hostname']}", null, $firewall_id, [
            'action' => 'update_triggered',
            'source' => 'dashboard'
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Update triggered successfully',
            'firewall_id' => $firewall_id,
            'hostname' => $firewall['hostname']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to trigger update']);
    }
    
} catch (Exception $e) {
    error_log("trigger_update.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);
}
?>