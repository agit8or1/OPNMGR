<?php
/**
 * Reboot Firewall API
 * Sends a reboot command to the specified firewall
 */

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/logging.php';
require_once __DIR__ . '/../inc/csrf.php';
requireLogin();

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$firewall_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($firewall_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid firewall ID']);
    exit;
}

try {
    // Verify firewall exists
    $stmt = $DB->prepare("SELECT hostname FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$firewall) {
        echo json_encode(['success' => false, 'error' => 'Firewall not found']);
        exit;
    }
    
    // Queue reboot command (shutdown -r in 1 minute to allow response)
    $reboot_command = '/sbin/shutdown -r +1 "Reboot initiated from OPNManager"';
    
    $stmt = $DB->prepare("
        INSERT INTO firewall_commands (firewall_id, command, description, status, created_at)
        VALUES (?, ?, 'Reboot firewall', 'pending', NOW())
    ");
    $stmt->execute([$firewall_id, $reboot_command]);
    
    $command_id = $DB->lastInsertId();
    
    // Log the reboot request
    log_info('system', 'Reboot command queued for ' . $firewall['hostname'], null, $firewall_id);
    
    // Clear reboot_required flag since we're rebooting
    $stmt = $DB->prepare("UPDATE firewalls SET reboot_required = 0 WHERE id = ?");
    $stmt->execute([$firewall_id]);
    
    echo json_encode([
        'success' => true,
        'command_id' => $command_id,
        'message' => 'Reboot command queued successfully'
    ]);
    
} catch (Exception $e) {
    error_log("reboot_firewall error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
