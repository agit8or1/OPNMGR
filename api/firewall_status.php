<?php
/**
 * Firewall Status API
 * Quick status check for connection page
 */

require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json');

$firewall_id = (int)($_GET['id'] ?? 0);

if (!$firewall_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing firewall ID']);
    exit;
}

try {
    $stmt = $DB->prepare('
        SELECT f.status, f.last_checkin, 
               TIMESTAMPDIFF(SECOND, f.last_checkin, NOW()) as seconds_since_checkin,
               fa.agent_version, fa.agent_type
        FROM firewalls f
        LEFT JOIN firewall_agents fa ON f.id = fa.firewall_id AND fa.agent_type = "primary"
        WHERE f.id = ?
    ');
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$firewall) {
        http_response_code(404);
        echo json_encode(['error' => 'Firewall not found']);
        exit;
    }
    
    // Agent is online if checked in within last 5 minutes (300 seconds)
    $is_online = $firewall['seconds_since_checkin'] < 300;
    
    echo json_encode([
        'status' => $is_online ? 'online' : 'offline',
        'agent_version' => $firewall['agent_version'],
        'last_checkin' => $firewall['last_checkin'],
        'seconds_ago' => (int)$firewall['seconds_since_checkin']
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
