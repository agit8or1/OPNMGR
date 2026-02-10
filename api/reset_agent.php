<?php
/**
 * Reset Agent API
 * Forces agent to restart on firewall
 */
require_once __DIR__ . '/../inc/bootstrap.php';

requireLogin();
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!csrf_verify($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$firewall_id = (int)($_POST['firewall_id'] ?? 0);

if (!$firewall_id) {
    echo json_encode(['success' => false, 'error' => 'Missing firewall ID']);
    exit;
}

try {
    // Get firewall details
    $stmt = db()->prepare("SELECT id, hostname, ip_address FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch();
    
    if (!$firewall) {
        echo json_encode(['success' => false, 'error' => 'Firewall not found']);
        exit;
    }
    
    // Queue command to restart agent
    $restart_cmd = "#!/bin/sh
# Kill and restart agent
pkill -9 -f opnsense_agent.sh
sleep 2
/usr/local/bin/opnsense_agent.sh &
echo 'Agent restarted'";
    
    $stmt = db()->prepare("
        INSERT INTO firewall_commands (firewall_id, command, description, command_type, status)
        VALUES (?, ?, ?, 'shell', 'pending')
    ");
    $stmt->execute([
        $firewall_id,
        $restart_cmd,
        'Agent restart - manual reset'
    ]);
    
    // Also force immediate checkin by touching flag file (would need agent to check, but let's queue it)
    write_log('AGENT', "Queued agent restart for firewall #{$firewall_id} ({$firewall['hostname']})");
    
    echo json_encode([
        'success' => true,
        'message' => 'Agent restart command queued',
        'firewall' => $firewall['hostname']
    ]);
    
} catch (Exception $e) {
    error_log("reset_agent error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
?>
