<?php
/**
 * Agent Configuration API
 * Agents query this endpoint to get their scheduled tasks
 * 
 * Example usage:
 * curl https://manager.local/api/agent_config.php?firewall_id=21
 */
require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/agent_auth.php';

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


    // Validate agent identity
    // Centralised agent authentication: identity resolution, hardware_id
    // pinning, API key verification and HMAC signature checking all live in
    // inc/agent_auth.php. It emits a generic error and exits on failure.
    $authenticated_firewall = authenticateAgentRequest(
        array_merge(is_array($_GET) ? $_GET : [], ['firewall_id' => $firewall_id])
    );
    $firewall_id = (int)$authenticated_firewall['id'];
    
    $scheduler = new AgentScheduler(db());
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
