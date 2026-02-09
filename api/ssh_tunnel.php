<?php
require_once __DIR__ . '/../inc/auth.php';
requireLogin();

/**
 * SSH Tunnel API Endpoint
 * Manages SSH tunnels for firewall access
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../scripts/manage_ssh_tunnel.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
$firewall_id = $_GET['firewall_id'] ?? $_POST['firewall_id'] ?? null;

if (!$firewall_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Firewall ID required']);
    exit;
}

$firewall = get_firewall_by_id($firewall_id);
if (!$firewall) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Firewall not found']);
    exit;
}

try {
    switch ($action) {
        case 'start':
            $result = start_tunnel($firewall);
            echo json_encode($result);
            break;
            
        case 'stop':
            $port = $firewall['ssh_tunnel_port'] ?: 9443;
            $result = stop_tunnel($firewall_id, $port);
            echo json_encode($result);
            break;
            
        case 'status':
        default:
            $result = get_tunnel_status($firewall);
            echo json_encode($result);
            break;
    }
} catch (Exception $e) {
    error_log("ssh_tunnel.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Tunnel operation failed']);
}
