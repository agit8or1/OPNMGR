<?php
// OPNsense Agent Self-Healing Status Reporter
// Receives status updates from self-healing scripts on firewalls

require_once __DIR__ . '/inc/bootstrap_agent.php';
require_once __DIR__ . '/inc/agent_auth.php';

header('Content-Type: application/json');

// Validate agent identity
$firewall_id = (int)($_POST['firewall_id'] ?? 0);
$hardware_id = trim($_POST['hardware_id'] ?? '');


// Centralised agent authentication: identity resolution, hardware_id
// pinning, API key verification and HMAC signature checking all live in
// inc/agent_auth.php. It emits a generic error and exits on failure.
$authenticated_firewall = authenticateAgentRequest(
    array_merge(is_array($_POST) ? $_POST : [], ['firewall_id' => $firewall_id])
);
$firewall_id = (int)$authenticated_firewall['id'];

// Get POST data
$hostname = $_POST['hostname'] ?? '';
$status = $_POST['status'] ?? '';
$details = $_POST['details'] ?? '';
$version = $_POST['version'] ?? '';
$timestamp = $_POST['timestamp'] ?? date('Y-m-d H:i:s');

if (empty($hostname) || empty($status)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters']);
    exit;
}

// Create self-healing log table if it doesn't exist
$createTable = "
CREATE TABLE IF NOT EXISTS agent_selfheal_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hostname VARCHAR(255) NOT NULL,
    status VARCHAR(100) NOT NULL,
    details TEXT,
    agent_version VARCHAR(20),
    reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    firewall_timestamp VARCHAR(100)
)";

try {
    db()->exec($createTable);
} catch(PDOException $e) {
    error_log("Failed to create table: " . $e->getMessage());
}

// Insert the status report
$insertSql = "INSERT INTO agent_selfheal_log (hostname, status, details, agent_version, firewall_timestamp) 
              VALUES (?, ?, ?, ?, ?)";

try {
    $stmt = db()->prepare($insertSql);
    $stmt->execute([$hostname, $status, $details, $version, $timestamp]);
    
    // Also update the main firewalls table if this is a completion report
    if ($status === 'completed' || $status === 'version_verified') {
        $updateFirewall = "UPDATE firewalls SET 
                          agent_version = ?, 
                          last_selfheal = NOW(),
                          selfheal_status = ?
                          WHERE hostname = ?";
        $updateStmt = db()->prepare($updateFirewall);
        $updateStmt->execute([$version, $status, $hostname]);
        
        // Also update firewall_agents table
        $updateAgent = "UPDATE firewall_agents SET 
                       agent_version = ?,
                       last_update = NOW()
                       WHERE firewall_id = (SELECT id FROM firewalls WHERE hostname = ?)";
        $updateAgentStmt = db()->prepare($updateAgent);
        $updateAgentStmt->execute([$version, $hostname]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Status report received',
        'hostname' => $hostname,
        'status' => $status,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch(PDOException $e) {
    error_log("Failed to insert status report: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save status report']);
}
?>