<?php
require_once __DIR__ . '/../inc/bootstrap_agent.php';

require_once __DIR__ . '/../inc/logging.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        exit;
    }
    
    // Validate agent identity
    $firewall_id = (int)($input['firewall_id'] ?? 0);
    $hardware_id = trim($input['hardware_id'] ?? '');

    if (!$firewall_id || empty($hardware_id)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing authentication']);
        exit;
    }

    $auth_stmt = db()->prepare('SELECT hardware_id FROM firewalls WHERE id = ?');
    $auth_stmt->execute([$firewall_id]);
    $auth_fw = $auth_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$auth_fw || (
        !empty($auth_fw['hardware_id']) && !hash_equals($auth_fw['hardware_id'], $hardware_id)
    )) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Authentication failed']);
        exit;
    }

    $command_id = $input['command_id'] ?? '';
    $result = $input['result'] ?? '';
    $output = $input['output'] ?? '';
    $error_output = $input['error_output'] ?? '';
    $error_output = $input['error_output'] ?? '';
    
    if (empty($command_id) || empty($result)) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }
    
    // Map result to status
    $status = ($result === 'success' || $result === 'partial') ? 'completed' : 'failed';
    
    // Decode output if it's base64 encoded
    $decoded_output = '';
    if (!empty($output)) {
        $decoded_output = base64_decode($output);
    }
    
    
    // Decode and append error output
    if (!empty($error_output)) {
        $decoded_error = base64_decode($error_output);
        if (!empty($decoded_error)) {
            $decoded_output .= "\n\n=== STDERR ===\n" . $decoded_error;
        }
    }
    // Update command status in firewall_commands table
    $stmt = db()->prepare("UPDATE firewall_commands SET status = ?, result = ?, completed_at = NOW() WHERE id = ?");
    $stmt->execute([$status, $decoded_output ?: $result, $command_id]);
    
    if ($stmt->rowCount() > 0) {
        // Get command details for logging
        $stmt = db()->prepare("SELECT fc.*, f.hostname 
                              FROM firewall_commands fc 
                              JOIN firewalls f ON fc.firewall_id = f.id 
                              WHERE fc.id = ?");
        $stmt->execute([$command_id]);
        $command = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($command) {
            $log_message = "Command completed for firewall {$command['hostname']}: {$command['description']} - Status: $status";
            if (!empty($decoded_output)) {
                $log_message .= " - Output: " . substr($decoded_output, 0, 200);
            } elseif (!empty($result)) {
                $log_message .= " - Result: $result";
            }
            
            // Log as ERROR if command failed, INFO if successful
            if ($status === 'failed') {
                log_error('command', $log_message, null, $command['firewall_id']);
            } else {
                log_info('command', $log_message, null, $command['firewall_id']);
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Command result updated']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Command not found or already updated']);
    }
    
} catch (Exception $e) {
    error_log("command_result.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
?>
