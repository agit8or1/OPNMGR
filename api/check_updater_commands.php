<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/../inc/db.php';

// Get firewall ID
$firewall_id = $_GET['firewall_id'] ?? null;

if (!$firewall_id) {
    http_response_code(400);
    echo "ERROR:Missing firewall_id parameter";
    exit;
}

try {
    // Check for pending updater commands
    $stmt = $DB->prepare("
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
        $update_stmt = $DB->prepare("
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