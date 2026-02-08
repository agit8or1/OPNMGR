<?php
require_once __DIR__ . '/inc/db.php';

try {
    // Clear old data for home.agit8or.net
    $DB->prepare("DELETE FROM firewalls WHERE hostname='home.agit8or.net'")->execute();
    $DB->prepare("DELETE FROM firewall_agents WHERE firewall_id NOT IN (SELECT id FROM firewalls)")->execute();
    
    // Clear old tokens (no site_name column exists)
    $DB->prepare("DELETE FROM enrollment_tokens WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)")->execute();
    
    // Create new token
    $token = 'enroll_' . time() . '_' . bin2hex(random_bytes(4));
    $stmt = $DB->prepare("INSERT INTO enrollment_tokens (token, expires_at) VALUES (?, DATE_ADD(NOW(), INTERVAL 24 HOUR))");
    $stmt->execute([$token]);
    
    echo "Database cleared and new token created: $token\n";
    echo "\nRun this on your OPNsense firewall:\n";
    echo "curl -k 'https://opn.agit8or.net/agent_script.php?token=$token' -o install_agent.sh && chmod +x install_agent.sh && ./install_agent.sh\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
