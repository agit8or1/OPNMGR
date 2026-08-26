<?php
/**
 * Firewall status summary.
 *
 * Was unauthenticated and hardcoded to a single hostname, exposing that
 * firewall's recent command history and results to anyone who asked.
 */
require_once __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/json');

requireLogin();

// Target firewall comes from the request; there is no default.
$requested_id       = (int)($_GET['firewall_id'] ?? 0);
$requested_hostname = trim($_GET['hostname'] ?? '');

if ($requested_id <= 0 && $requested_hostname === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Provide firewall_id or hostname']);
    exit;
}

try {
    // Get firewall agent status
    if ($requested_id > 0) {
        $stmt = db()->prepare('SELECT id, hostname, last_checkin, agent_version, status FROM firewalls WHERE id = ?');
        $stmt->execute([$requested_id]);
    } else {
        $stmt = db()->prepare('SELECT id, hostname, last_checkin, agent_version, status FROM firewalls WHERE hostname = ?');
        $stmt->execute([$requested_hostname]);
    }
    $agent_status = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$agent_status) {
        http_response_code(404);
        echo json_encode(['error' => 'Firewall not found']);
        exit;
    }
    
    // Get updater status
    $stmt = db()->prepare('SELECT u.*, f.hostname FROM firewall_updaters u JOIN firewalls f ON u.firewall_id = f.id WHERE f.id = ?');
    $stmt->execute([$agent_status['id']]);
    $updater_status = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get recent commands
    $stmt = db()->prepare('SELECT id, command, status, sent_at, completed_at, result FROM firewall_commands WHERE firewall_id = ? ORDER BY created_at DESC LIMIT 5');
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