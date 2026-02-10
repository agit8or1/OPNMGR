<?php
require_once __DIR__ . '/inc/bootstrap_agent.php';

// Endpoint for updater service check-ins
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
$hardware_id = trim($input['hardware_id'] ?? '');
$updater_version = trim($input['updater_version'] ?? '');

// Validate agent identity
if (!$firewall_id || empty($hardware_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing authentication']);
    exit;
}

$auth_stmt = db()->prepare('SELECT hardware_id FROM firewalls WHERE id = ?');
$auth_stmt->execute([$firewall_id]);
$auth_fw = $auth_stmt->fetch(PDO::FETCH_ASSOC);
if (!$auth_fw || (
    !empty($auth_fw['hardware_id']) && !hash_equals($auth_fw['hardware_id'], $hardware_id)
)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Authentication failed']);
    exit;
}

// Validate inputs
if (empty($updater_version)) {
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
    // Update or insert updater status
    $stmt = db()->prepare('INSERT INTO firewall_updaters (firewall_id, updater_version, last_checkin, status) VALUES (?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE updater_version = VALUES(updater_version), last_checkin = NOW(), status = VALUES(status)');
    $stmt->execute([$firewall_id, $updater_version, 'active']);

    // Check for pending update requests
    $stmt = db()->prepare('SELECT update_requested, update_type FROM firewalls WHERE id = ?');
    $stmt->execute([$firewall_id]);
    $update_info = $stmt->fetch();
    
    // Check for pending updater commands
    $stmt = db()->prepare('SELECT id, command_type, command, description FROM updater_commands WHERE firewall_id = ? AND status = "pending" ORDER BY created_at ASC LIMIT 5');
    $stmt->execute([$firewall_id]);
    $pending_commands = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Mark commands as sent when delivered
    if (!empty($pending_commands)) {
        $command_ids = array_column($pending_commands, 'id');
        $placeholders = str_repeat('?,', count($command_ids) - 1) . '?';
        $stmt = db()->prepare("UPDATE updater_commands SET status = 'sent', sent_at = NOW() WHERE id IN ($placeholders)");
        $stmt->execute($command_ids);
    }
    
    $response = [
        'success' => true,
        'message' => 'Updater check-in successful',
        'server_time' => date('c'),
        'updater_version' => $updater_version
    ];
    
    // Include pending commands if any
    if (!empty($pending_commands)) {
        $response['pending_commands'] = $pending_commands;
    }
    
    // Check if update is requested
    if ($update_info && $update_info['update_requested']) {
        $response['update_requested'] = true;
        $response['update_type'] = $update_info['update_type'] ?: 'full';
        
        // Clear the update request flag and set status to updating
        $stmt = db()->prepare('UPDATE firewalls SET update_requested = 0, status = \'updating\', update_started_at = NOW() WHERE id = ?');
        $stmt->execute([$firewall_id]);
        
        // Log the update initiation
        $stmt = db()->prepare('INSERT INTO system_logs (timestamp, level, category, message, user_id, ip_address, firewall_id, additional_data) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            'INFO',
            'updater',
            "Standalone updater initiated system update for firewall {$firewall['hostname']}",
            null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $firewall_id,
            json_encode(['action' => 'update_initiated', 'update_type' => $response['update_type'], 'updater_version' => $updater_version])
        ]);
    } else {
        $response['update_requested'] = false;
    }
    
    echo json_encode($response);

} catch (Exception $e) {
    error_log("updater_checkin.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
?>