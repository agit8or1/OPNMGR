<?php
// Agent Proxy Check Endpoint
// Checks if there are pending proxy requests for this firewall
// Enhanced with better timeout handling, multiple request support, and health checks

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/logging.php';

header('Content-Type: application/json');

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['firewall_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request', 'required' => ['firewall_id']]);
    exit;
}

$firewall_id = (int)$data['firewall_id'];
$include_recent = isset($data['include_recent']) ? (bool)$data['include_recent'] : false;

try {
    // Verify firewall exists
    $fw_stmt = $DB->prepare("SELECT id, hostname, status FROM firewalls WHERE id = ?");
    $fw_stmt->execute([$firewall_id]);
    $firewall = $fw_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$firewall) {
        http_response_code(404);
        echo json_encode(['error' => 'Firewall not found', 'firewall_id' => $firewall_id]);
        exit;
    }
    
    // Auto-timeout old requests that are stuck in processing
    // If a request has been "processing" for more than 5 minutes, mark it as timeout
    $timeout_stmt = $DB->prepare("
        UPDATE request_queue
        SET status = 'timeout',
            updated_at = NOW(),
            error_message = 'Auto-timeout: No status update received'
        WHERE firewall_id = ?
        AND status = 'processing'
        AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");
    $timeout_result = $timeout_stmt->execute([$firewall_id]);
    
    if ($timeout_stmt->rowCount() > 0) {
        log_warning('proxy', "Auto-timed out {$timeout_stmt->rowCount()} stuck proxy request(s)", 
                   ['firewall_id' => $firewall_id], $firewall_id);
    }
    
    // Check for pending or processing proxy requests
    $stmt = $DB->prepare("
        SELECT id, firewall_id, status, tunnel_port, created_at, updated_at, error_message
        FROM request_queue
        WHERE firewall_id = ?
        AND status IN ('pending', 'processing')
        AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ORDER BY created_at ASC
        LIMIT 10
    ");
    $stmt->execute([$firewall_id]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $response = [
        'has_request' => !empty($requests),
        'firewall_id' => $firewall_id,
        'firewall_status' => $firewall['status'],
        'check_time' => date('Y-m-d H:i:s')
    ];
    
    if (!empty($requests)) {
        // Return all active requests
        $response['requests'] = array_map(function($req) {
            return [
                'request_id' => (int)$req['id'],
                'tunnel_port' => (int)$req['tunnel_port'],
                'status' => $req['status'],
                'created_at' => $req['created_at'],
                'updated_at' => $req['updated_at'],
                'age_seconds' => strtotime('now') - strtotime($req['created_at'])
            ];
        }, $requests);
        
        // Primary request is the oldest pending one
        $response['primary_request'] = $response['requests'][0];
    }
    
    // Optionally include recently completed/failed requests for debugging
    if ($include_recent) {
        $recent_stmt = $DB->prepare("
            SELECT id, status, tunnel_port, created_at, updated_at, error_message
            FROM request_queue
            WHERE firewall_id = ?
            AND status IN ('completed', 'failed', 'timeout')
            AND updated_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
            ORDER BY updated_at DESC
            LIMIT 5
        ");
        $recent_stmt->execute([$firewall_id]);
        $recent = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $response['recent_history'] = array_map(function($req) {
            return [
                'request_id' => (int)$req['id'],
                'status' => $req['status'],
                'tunnel_port' => (int)$req['tunnel_port'],
                'completed_at' => $req['updated_at'],
                'error_message' => $req['error_message']
            ];
        }, $recent);
    }
    
    // Check for any dead/orphaned tunnels (requests that never completed)
    $orphan_stmt = $DB->prepare("
        SELECT COUNT(*) as orphan_count
        FROM request_queue
        WHERE firewall_id = ?
        AND status = 'processing'
        AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    ");
    $orphan_stmt->execute([$firewall_id]);
    $orphan_data = $orphan_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($orphan_data['orphan_count'] > 0) {
        $response['warning'] = "Found {$orphan_data['orphan_count']} orphaned request(s) - may need cleanup";
    }
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
    error_log("Proxy check error: " . $e->getMessage());
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error', 'message' => $e->getMessage()]);
    error_log("Proxy check error: " . $e->getMessage());
}
