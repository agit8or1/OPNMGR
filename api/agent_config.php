<?php
/**
 * Agent Configuration API
 * Agents query this endpoint to get their scheduled tasks
 * 
 * Example usage:
 * curl https://manager.local/api/agent_config.php?firewall_id=21
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/agent_scheduler.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $firewall_id = (int)($_GET['firewall_id'] ?? 0);
    $hardware_id = trim($_GET['hardware_id'] ?? '');
    $config_type = $_GET['type'] ?? 'full';  // 'full', 'ping', 'speedtest'

    if (!$firewall_id || empty($hardware_id)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing authentication']);
        exit;
    }

    // Validate agent identity
    $auth_stmt = $DB->prepare('SELECT hardware_id FROM firewalls WHERE id = ?');
    $auth_stmt->execute([$firewall_id]);
    $auth_fw = $auth_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$auth_fw || (
        !empty($auth_fw['hardware_id']) && !hash_equals($auth_fw['hardware_id'], $hardware_id)
    )) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Authentication failed']);
        exit;
    }
    
    $scheduler = new AgentScheduler($DB);
    $config = [];
    
    switch ($config_type) {
        case 'ping':
            $config = $scheduler->getPingConfiguration();
            break;
            
        case 'speedtest':
            $config = $scheduler->getSpeedtestConfigForAgent($firewall_id);
            break;
            
        case 'full':
        default:
            $config = [
                'success' => true,
                'firewall_id' => $firewall_id,
                'ping' => $scheduler->getPingConfiguration(),
                'speedtest' => $scheduler->getSpeedtestConfigForAgent($firewall_id),
                'generated_at' => date('Y-m-d H:i:s UTC'),
                'server_version' => '2.1.0'
            ];
    }
    
    echo json_encode($config);
    
} catch (Exception $e) {
    error_log("agent_config.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
