<?php
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');
try {
    // Get all firewalls with their update status
    $stmt = db()->query("
        SELECT 
            f.id,
            f.hostname,
            f.ip_address,
            f.status,
            f.last_checkin,
            f.agent_version,
            f.current_version,
            f.available_version,
            f.update_requested,
            f.uptime,
            f.wan_ip,
            f.lan_ip,
            COUNT(uc.id) as pending_updater_commands
        FROM firewalls f
        LEFT JOIN updater_commands uc ON f.id = uc.firewall_id AND uc.status = 'pending'
        GROUP BY f.id
        ORDER BY f.hostname
    ");
    $firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent updater command history
    $history_stmt = db()->query("
        SELECT 
            uc.id,
            uc.firewall_id,
            f.hostname,
            uc.command_type,
            uc.description,
            uc.status,
            uc.created_at,
            uc.completed_at,
            uc.result
        FROM updater_commands uc
        JOIN firewalls f ON uc.firewall_id = f.id
        ORDER BY uc.created_at DESC
        LIMIT 50
    ");
    $command_history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'firewalls' => $firewalls,
        'command_history' => $command_history
    ]);
    
} catch (Exception $e) {
    error_log("Get update status error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error getting update status']);
}
?>