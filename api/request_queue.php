<?php
/**
 * HTTP Request Queue System
 * Handles proxying HTTP requests through firewall agent polling
 */

require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json');

// Handle different request methods
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'queue_request':
        queueRequest();
        break;
    case 'poll_requests':
        pollRequests();
        break;
    case 'submit_response':
        submitResponse();
        break;
    case 'get_response':
        getResponse();
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}

function queueRequest() {
    global $DB;
    
    $firewall_id = (int)($_POST['firewall_id'] ?? 0);
    $request_method = $_POST['method'] ?? 'GET';
    $request_path = $_POST['path'] ?? '/';
    $request_headers = $_POST['headers'] ?? '{}';
    $request_body = $_POST['body'] ?? '';
    $client_id = $_POST['client_id'] ?? uniqid();
    
    if (!$firewall_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing firewall_id']);
        return;
    }
    
    try {
        $stmt = $DB->prepare("INSERT INTO request_queue 
            (firewall_id, client_id, method, path, headers, body, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
        
        $stmt->execute([
            $firewall_id, $client_id, $request_method, 
            $request_path, $request_headers, $request_body
        ]);
        
        $request_id = $DB->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'request_id' => $request_id,
            'client_id' => $client_id
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function pollRequests() {
    global $DB;
    
    $firewall_id = (int)($_GET['firewall_id'] ?? 0);
    
    if (!$firewall_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing firewall_id']);
        return;
    }
    
    try {
        $stmt = $DB->prepare("SELECT id, client_id, method, path, headers, body 
            FROM request_queue 
            WHERE firewall_id = ? AND status = 'pending' 
            ORDER BY created_at ASC LIMIT 10");
        
        $stmt->execute([$firewall_id]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Mark requests as processing
        if (!empty($requests)) {
            $request_ids = array_column($requests, 'id');
            $placeholders = str_repeat('?,', count($request_ids) - 1) . '?';
            $update_stmt = $DB->prepare("UPDATE request_queue SET status = 'processing' WHERE id IN ($placeholders)");
            $update_stmt->execute($request_ids);
        }
        
        echo json_encode([
            'success' => true,
            'requests' => $requests
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function submitResponse() {
    global $DB;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $request_id = (int)($input['request_id'] ?? 0);
    $response_status = (int)($input['status'] ?? 200);
    $response_headers = $input['headers'] ?? '{}';
    $response_body = $input['body'] ?? '';
    
    if (!$request_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing request_id']);
        return;
    }
    
    try {
        $stmt = $DB->prepare("UPDATE request_queue SET 
            status = 'completed',
            response_status = ?,
            response_headers = ?,
            response_body = ?,
            completed_at = NOW()
            WHERE id = ?");
        
        $stmt->execute([$response_status, $response_headers, $response_body, $request_id]);
        
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function getResponse() {
    global $DB;
    
    $client_id = $_GET['client_id'] ?? '';
    
    if (!$client_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing client_id']);
        return;
    }
    
    try {
        $stmt = $DB->prepare("SELECT response_status, response_headers, response_body, status 
            FROM request_queue WHERE client_id = ? ORDER BY created_at DESC LIMIT 1");
        
        $stmt->execute([$client_id]);
        $response = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$response) {
            echo json_encode(['status' => 'not_found']);
            return;
        }
        
        if ($response['status'] !== 'completed') {
            echo json_encode(['status' => 'pending']);
            return;
        }
        
        echo json_encode([
            'status' => 'completed',
            'response_status' => $response['response_status'],
            'response_headers' => $response['response_headers'],
            'response_body' => $response['response_body']
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}
?>