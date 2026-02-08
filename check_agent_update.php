<?php
/**
 * Monitor agent update status
 */
require_once __DIR__ . '/inc/db.php';

echo "Monitoring agent update status...\n\n";

while (true) {
    // Check agent version
    $stmt = $DB->query('SELECT agent_version, last_checkin, TIMESTAMPDIFF(SECOND, last_checkin, NOW()) as secs_ago FROM firewall_agents WHERE firewall_id=21');
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check command status
    $stmt = $DB->query('SELECT id, status FROM firewall_commands WHERE firewall_id=21 AND description LIKE "%v2.4%" ORDER BY created_at DESC LIMIT 1');
    $cmd = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $timestamp = date('H:i:s');
    echo "[$timestamp] Agent: v{$agent['agent_version']}, Last checkin: {$agent['secs_ago']}s ago, Command: {$cmd['status']}\n";
    
    // Break if agent updated
    if ($agent['agent_version'] === '2.4.0') {
        echo "\n✓ Agent successfully updated to v2.4.0!\n";
        break;
    }
    
    // Break if command executed
    if ($cmd['status'] === 'completed' || $cmd['status'] === 'failed') {
        echo "\n✓ Command executed with status: {$cmd['status']}\n";
        break;
    }
    
    sleep(10);
}
