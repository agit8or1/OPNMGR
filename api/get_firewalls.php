<?php
require_once __DIR__ . '/../inc/auth.php';
requireLogin();
require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json');

try {
    // Get all firewalls
    $stmt = $DB->prepare('
        SELECT 
            id, 
            hostname, 
            ip_address, 
            status, 
            last_checkin, 
            agent_version, 
            current_version, 
            available_version, 
            updates_available,
            uptime,
            wan_ip,
            lan_ip
        FROM firewalls 
        ORDER BY hostname ASC
    ');
    $stmt->execute();
    $firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the data
    foreach ($firewalls as &$firewall) {
        // Ensure boolean fields are properly formatted
        $firewall['updates_available'] = (int)$firewall['updates_available'];
        
        // Format last_checkin as ISO string if it exists
        if ($firewall['last_checkin']) {
            $firewall['last_checkin'] = date('c', strtotime($firewall['last_checkin']));
        }
    }
    
    echo json_encode([
        'success' => true,
        'firewalls' => $firewalls
    ]);
    
} catch (Exception $e) {
    error_log("get_firewalls.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);
}
?>