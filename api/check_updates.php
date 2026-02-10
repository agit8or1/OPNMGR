<?php
require_once __DIR__ . '/../inc/bootstrap.php';

require_once __DIR__ . '/../inc/logging.php';
requireLogin();
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$firewall_id = (int)($input['firewall_id'] ?? 0);

if (!$firewall_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid firewall ID']);
    exit;
}

try {
    // Get firewall details
    $stmt = db()->prepare("SELECT hostname, ip_address FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch();
    
    if (!$firewall) {
        echo json_encode(['success' => false, 'message' => 'Firewall not found']);
        exit;
    }
    
    // Send update command to firewall agent
    $update_data = [
        'action' => 'check_updates',
        'timestamp' => time()
    ];
    
    // You can extend this to actually communicate with the firewall
    // For now, we'll simulate checking for updates
    
    // Simulate update check results (in a real implementation, this would query the firewall)
    $current_version = "24.7.1";
    $available_version = "24.7.2";
    $updates_available = rand(0, 1) == 1; // Random for demo
    
    // Update the database
    $stmt = db()->prepare("
        UPDATE firewalls 
        SET updates_available = ?, 
            last_update_check = NOW(), 
            current_version = ?, 
            available_version = ? 
        WHERE id = ?
    ");
    $stmt->execute([$updates_available, $current_version, $available_version, $firewall_id]);
    
    // Log the action
    log_info('firewall', "Manual update check performed on firewall {$firewall['hostname']}", 
        $_SESSION['user_id'] ?? null, $firewall_id, [
            'current_version' => $current_version,
            'available_version' => $available_version,
            'updates_available' => $updates_available
        ]);
    
    echo json_encode([
        'success' => true,
        'updates_available' => $updates_available,
        'current_version' => $current_version,
        'available_version' => $available_version,
        'message' => $updates_available ? 'Updates are available' : 'No updates available'
    ]);
    
} catch (Exception $e) {
    log_error('firewall', "Failed to check updates for firewall ID $firewall_id: " . $e->getMessage(), 
        $_SESSION['user_id'] ?? null, $firewall_id);
    
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}
?>