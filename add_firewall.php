<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/csrf.php';
requireLogin();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        die('CSRF verification failed.');
    }
    
    $hostname = trim($_POST['hostname'] ?? '');
    $ip_address = trim($_POST['ip_address'] ?? '');
    $wan_ip = trim($_POST['wan_ip'] ?? '');
    $customer_name = trim($_POST['customer_name'] ?? '');
    $tags = json_decode($_POST['tags'] ?? '[]', true);
    
    // Validate inputs
    if (empty($hostname) || empty($ip_address)) {
        die('Hostname and IP address are required.');
    }
    
    if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
        die('Invalid IP address format.');
    }
    
    try {
        $stmt = $DB->prepare('INSERT INTO firewalls (hostname, ip_address, wan_ip, customer_name, status) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$hostname, $ip_address, $wan_ip, $customer_name, 'unknown']);
        $firewall_id = $DB->lastInsertId();
        
        // Insert tags
        if (!empty($tags)) {
            foreach ($tags as $tag_id) {
                $stmt = $DB->prepare('INSERT INTO firewall_tags (firewall_id, tag_id) VALUES (?, ?)');
                $stmt->execute([$firewall_id, $tag_id]);
            }
        }
        
        echo 'Firewall added successfully.';
    } catch (Exception $e) {
        error_log("add_firewall.php error: " . $e->getMessage());
        echo 'Internal server error';
    }
} else {
    http_response_code(405);
    echo 'Method not allowed.';
}
?>
