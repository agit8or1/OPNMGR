<?php
require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/agent_auth.php';

header('Content-Type: text/plain');
$firewall_id = (int)($_GET['firewall_id'] ?? 0);
$hardware_id = trim($_GET['hardware_id'] ?? '');

if (!$firewall_id || empty($hardware_id)) {
    echo "ERROR";
    exit;
}

// Validate agent identity
try {
    // Centralised agent authentication: identity resolution, hardware_id
    // pinning, API key verification and HMAC signature checking all live in
    // inc/agent_auth.php. It emits a generic error and exits on failure.
    $authenticated_firewall = authenticateAgentRequest(
        array_merge(is_array($_GET) ? $_GET : [], ['firewall_id' => $firewall_id])
    );
    $firewall_id = (int)$authenticated_firewall['id'];
} catch (Exception $e) {
    echo "ERROR";
    exit;
}

try {
    // Clear update flag and set status back to online
    $stmt = db()->prepare("
        UPDATE firewalls 
        SET update_requested = 0, status = 'online' 
        WHERE id = ?
    ");
    $stmt->execute([$firewall_id]);
    
    echo "OK";
    
} catch (Exception $e) {
    echo "ERROR";
}
?>