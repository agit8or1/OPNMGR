<?php
// Agent Proxy Update Endpoint
// Allows agent to update proxy request status
// Enhanced with better error handling, timeout management, and monitoring

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/logging.php';

header('Content-Type: application/json');

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['request_id']) || !isset($data['status'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request', 'required' => ['request_id', 'status']]);
    exit;
}

$request_id = (int)$data['request_id'];
$status = $data['status'];
$tunnel_pid = isset($data['tunnel_pid']) ? (int)$data['tunnel_pid'] : null;
$error_message = isset($data['error_message']) ? trim($data['error_message']) : null;
$tunnel_port = isset($data['tunnel_port']) ? (int)$data['tunnel_port'] : null;

// Validate status
$valid_statuses = ['pending', 'processing', 'completed', 'failed', 'timeout', 'cancelled'];
if (!in_array($status, $valid_statuses)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid status', 'valid_statuses' => $valid_statuses]);
    exit;
}

try {
    // First, verify the request exists and get its current state
    $check_stmt = $DB->prepare("SELECT id, status, firewall_id, created_at FROM request_queue WHERE id = ?");
    $check_stmt->execute([$request_id]);
    $existing_request = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existing_request) {
        http_response_code(404);
        echo json_encode(['error' => 'Request not found', 'request_id' => $request_id]);
        exit;
    }
    
    // Don't allow updates to completed/failed/cancelled requests (prevent duplicate processing)
    $final_statuses = ['completed', 'failed', 'cancelled'];
    if (in_array($existing_request['status'], $final_statuses) && $status !== $existing_request['status']) {
        http_response_code(409);
        echo json_encode([
            'error' => 'Cannot update request in final state',
            'current_status' => $existing_request['status'],
            'attempted_status' => $status
        ]);
        exit;
    }
    
    // Build update query dynamically based on provided fields
    $update_fields = ['status' => $status, 'updated_at' => 'NOW()'];
    $update_values = [$status];
    
    if ($tunnel_pid !== null) {
        $update_fields['tunnel_pid'] = '?';
        $update_values[] = $tunnel_pid;
    }
    
    if ($error_message !== null) {
        $update_fields['error_message'] = '?';
        $update_values[] = $error_message;
    }
    
    if ($tunnel_port !== null) {
        $update_fields['tunnel_port'] = '?';
        $update_values[] = $tunnel_port;
    }
    
    // Build the SET clause
    $set_clause = [];
    foreach ($update_fields as $field => $placeholder) {
        if ($placeholder === 'NOW()') {
            $set_clause[] = "$field = NOW()";
        } else {
            $set_clause[] = "$field = ?";
        }
    }
    
    // Update request status
    $stmt = $DB->prepare("
        UPDATE request_queue
        SET " . implode(', ', $set_clause) . "
        WHERE id = ?
    ");
    
    $update_values[] = $request_id;
    $result = $stmt->execute($update_values);
    
    if (!$result) {
        throw new Exception("Failed to update request_queue: " . implode(', ', $stmt->errorInfo()));
    }
    
    // Log the status change with appropriate level
    $log_level = 'info';
    $log_message = "Proxy request #$request_id: $status";
    
    if ($status === 'failed') {
        $log_level = 'error';
        $log_message .= ($error_message ? " - $error_message" : " - Unknown error");
    } elseif ($status === 'timeout') {
        $log_level = 'warning';
        $log_message .= " - Request timed out";
    } elseif ($status === 'completed') {
        $log_level = 'info';
        $log_message .= " - Successfully completed";
    }
    
    // Use the logging function
    $log_details = [
        'request_id' => $request_id,
        'status' => $status,
        'tunnel_pid' => $tunnel_pid,
        'tunnel_port' => $tunnel_port,
        'error_message' => $error_message
    ];
    
    if ($log_level === 'error') {
        log_error('proxy', $log_message, $log_details, $existing_request['firewall_id']);
    } elseif ($log_level === 'warning') {
        log_warning('proxy', $log_message, $log_details, $existing_request['firewall_id']);
    } else {
        log_info('proxy', $log_message, $log_details, $existing_request['firewall_id']);
    }
    
    echo json_encode([
        'success' => true,
        'request_id' => $request_id,
        'status' => $status,
        'previous_status' => $existing_request['status'],
        'updated_at' => date('Y-m-d H:i:s')
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
    error_log("Proxy update error: " . $e->getMessage());
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    error_log("Proxy update error: " . $e->getMessage());
}
