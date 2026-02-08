<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/logging.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

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
    $stmt = $DB->prepare("UPDATE firewall_commands SET status = ?, result = ?, completed_at = NOW() WHERE id = ?");
    $stmt->execute([$status, $decoded_output ?: $result, $command_id]);
    
    if ($stmt->rowCount() > 0) {
        // Get command details for logging
        $stmt = $DB->prepare("SELECT fc.*, f.hostname 
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
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
