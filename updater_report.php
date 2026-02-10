<?php
require_once __DIR__ . '/inc/bootstrap_agent.php';

// Endpoint for updater service to report update results
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

$firewall_id = (int)($input['firewall_id'] ?? 0);
$update_status = trim($input['update_status'] ?? '');
$message = trim($input['message'] ?? '');
$timestamp = trim($input['timestamp'] ?? '');

// Validate inputs
if (!$firewall_id || empty($update_status)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Verify firewall exists
$stmt = db()->prepare('SELECT id, hostname FROM firewalls WHERE id = ?');
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$firewall) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Firewall not found']);
    exit;
}

try {
    // Update firewall status based on update result
    $new_status = 'online';
    switch ($update_status) {
        case 'success':
            $new_status = 'online';
            break;
        case 'partial':
            $new_status = 'online';
            break;
        case 'reboot_required':
            $new_status = 'rebooting';
            break;
        case 'failed':
            $new_status = 'update_failed';
            break;
        default:
            $new_status = 'online';
    }
    
    // Update firewall status and completion time
    $stmt = db()->prepare('UPDATE firewalls SET status = ?, update_completed_at = NOW() WHERE id = ?');
    $stmt->execute([$new_status, $firewall_id]);
    
    // Update updater status
    $stmt = db()->prepare('UPDATE firewall_updaters SET last_update_result = ?, last_update_message = ?, last_update_time = NOW() WHERE firewall_id = ?');
    $stmt->execute([$update_status, $message, $firewall_id]);
    
    // Log the update result
    $log_level = ($update_status === 'failed') ? 'ERROR' : 'INFO';
    $stmt = db()->prepare('INSERT INTO system_logs (timestamp, level, category, message, user_id, ip_address, firewall_id, additional_data) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $log_level,
        'updater',
        "Update completed for firewall {$firewall['hostname']}: $message",
        null,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $firewall_id,
        json_encode([
            'action' => 'update_completed',
            'status' => $update_status,
            'message' => $message,
            'timestamp' => $timestamp
        ])
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Update result recorded successfully'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    error_log("updater_report.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
?>