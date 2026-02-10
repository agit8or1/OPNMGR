<?php
require_once __DIR__ . '/../inc/bootstrap.php';

requireLogin();

// Tunnel status API
header('Content-Type: application/json');

try {
    $tunnels = db()->query("
        SELECT 
            id,
            hostname,
            tunnel_active,
            tunnel_client_ip,
            tunnel_port,
            tunnel_established,
            TIMESTAMPDIFF(SECOND, tunnel_established, NOW()) as seconds_since_update
        FROM firewalls 
        WHERE tunnel_active = 1 
        ORDER BY tunnel_established DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'active_tunnels' => count($tunnels),
        'tunnels' => $tunnels
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
?>