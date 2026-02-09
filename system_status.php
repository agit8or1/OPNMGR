<?php
header('Content-Type: application/json');

require_once __DIR__ . '/inc/db.php';

try {
    // Get firewall agent status
    $stmt = $DB->prepare('SELECT id, hostname, last_checkin, agent_version, status FROM firewalls WHERE hostname = ?');
    $stmt->execute(['home.agit8or.net']);
    $agent_status = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get updater status
    $stmt = $DB->prepare('SELECT u.*, f.hostname FROM firewall_updaters u JOIN firewalls f ON u.firewall_id = f.id WHERE f.hostname = ?');
    $stmt->execute(['home.agit8or.net']);
    $updater_status = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get recent commands
    $stmt = $DB->prepare('SELECT id, command, status, sent_at, completed_at, result FROM firewall_commands WHERE firewall_id = ? ORDER BY created_at DESC LIMIT 5');
    $stmt->execute([$agent_status['id']]);
    $recent_commands = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate time differences
    $now = new DateTime();
    $agent_last_checkin = new DateTime($agent_status['last_checkin']);
    $agent_minutes_ago = $now->diff($agent_last_checkin)->i + ($now->diff($agent_last_checkin)->h * 60);
    
    $updater_last_checkin = new DateTime($updater_status['last_checkin']);
    $updater_hours_ago = $now->diff($updater_last_checkin)->h + ($now->diff($updater_last_checkin)->days * 24);
    
    $response = [
        'timestamp' => $now->format('Y-m-d H:i:s'),
        'agent' => [
            'version' => $agent_status['agent_version'],
            'last_checkin' => $agent_status['last_checkin'],
            'minutes_ago' => $agent_minutes_ago,
            'status' => $agent_status['status'],
            'is_online' => $agent_minutes_ago <= 3, // Consider online if checked in within 3 minutes
        ],
        'updater' => [
            'version' => $updater_status['updater_version'],
            'last_checkin' => $updater_status['last_checkin'],
            'hours_ago' => $updater_hours_ago,
            'status' => $updater_status['status'],
            'is_online' => $updater_hours_ago <= 1, // Consider online if checked in within 1 hour
        ],
        'recent_commands' => $recent_commands,
        'analysis' => [
            'agent_working' => $agent_minutes_ago <= 3,
            'updater_working' => $updater_hours_ago <= 1,
            'system_health' => ($agent_minutes_ago <= 3 && $updater_hours_ago <= 1) ? 'good' : 'poor'
        ]
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("system_status.php error: " . $e->getMessage());
    echo json_encode(['error' => 'Internal server error']);
}
?>