<?php
require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/agent_auth.php';

header('Content-Type: text/plain');
// Get firewall ID and validate agent identity
$firewall_id = (int)($_GET['firewall_id'] ?? 0);
$hardware_id = trim($_GET['hardware_id'] ?? '');

if (!$firewall_id || empty($hardware_id)) {
    http_response_code(400);
    echo "ERROR:Missing firewall_id or hardware_id parameter";
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
    http_response_code(500);
    echo "ERROR:Authentication error";
    exit;
}

try {
    // Check for pending updater commands
    $stmt = db()->prepare("
        SELECT command_type, command, description, id
        FROM updater_commands 
        WHERE firewall_id = ? AND status = 'pending' 
        ORDER BY created_at ASC 
        LIMIT 1
    ");
    $stmt->execute([$firewall_id]);
    $command = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($command) {
        // Mark command as sent
        $update_stmt = db()->prepare("
            UPDATE updater_commands 
            SET status = 'sent', sent_at = NOW() 
            WHERE id = ?
        ");
        $update_stmt->execute([$command['id']]);
        
        // Return command in simple format
        echo $command['command_type'] . ':' . $command['command'] . ':' . $command['description'];
    }
    // If no commands, return empty (no output)
    
} catch (Exception $e) {
    error_log("Updater command check error: " . $e->getMessage());
    http_response_code(500);
    echo "ERROR:Server error checking for commands";
}
?>