<?php
/**
 * Administration Queue Management API
 * Manages firewall commands, HTTP requests, and system tasks
 */

require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_command_queue':
        getCommandQueue();
        break;
    case 'get_request_queue':
        getRequestQueue();
        break;
    case 'cancel_command':
        cancelCommand();
        break;
    case 'cancel_request':
        cancelRequest();
        break;
    case 'delete_command':
        deleteCommand();
        break;
    case 'delete_request':
        deleteRequest();
        break;
    case 'clear_command_queue':
        clearCommandQueue();
        break;
    case 'clear_request_queue':
        clearRequestQueue();
        break;
    case 'retry_command':
        retryCommand();
        break;
    case 'get_queue_summary':
        getQueueSummary();
        break;
    case 'get_recent_activity':
        getRecentActivity();
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}

function getCommandQueue() {
    global $DB;
    
    try {
        $firewall_id = (int)($_GET['firewall_id'] ?? 21);
        $status = $_GET['status'] ?? 'all';
        $limit = (int)($_GET['limit'] ?? 50);
        
        $where_clause = "WHERE firewall_id = ?";
        $params = [$firewall_id];
        
        if ($status !== 'all') {
            $where_clause .= " AND status = ?";
            $params[] = $status;
        }
        
        $stmt = $DB->prepare("
            SELECT id, command, status, 
                   DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%sZ') as created_at,
                   DATE_FORMAT(sent_at, '%Y-%m-%dT%H:%i:%sZ') as sent_at,
                   DATE_FORMAT(completed_at, '%Y-%m-%dT%H:%i:%sZ') as completed_at,
                   result,
                   TIMESTAMPDIFF(MINUTE, created_at, COALESCE(completed_at, NOW())) as age_minutes
            FROM firewall_commands 
            $where_clause
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        
        $params[] = $limit;
        $stmt->execute($params);
        $commands = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get status counts
        $count_stmt = $DB->prepare("
            SELECT status, COUNT(*) as count 
            FROM firewall_commands 
            WHERE firewall_id = ?
            GROUP BY status
        ");
        $count_stmt->execute([$firewall_id]);
        $status_counts = $count_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'commands' => $commands,
            'status_counts' => $status_counts
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get command queue: ' . $e->getMessage()]);
    }
}

function getRequestQueue() {
    global $DB;
    
    try {
        $firewall_id = (int)($_GET['firewall_id'] ?? 21);
        $status = $_GET['status'] ?? 'all';
        $limit = (int)($_GET['limit'] ?? 50);
        
        $where_clause = "WHERE firewall_id = ?";
        $params = [$firewall_id];
        
        if ($status !== 'all') {
            $where_clause .= " AND status = ?";
            $params[] = $status;
        }
        
        $stmt = $DB->prepare("
            SELECT id, client_id, method, path, status, response_status, 
                   DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%sZ') as created_at,
                   DATE_FORMAT(completed_at, '%Y-%m-%dT%H:%i:%sZ') as completed_at,
                   TIMESTAMPDIFF(MINUTE, created_at, COALESCE(completed_at, NOW())) as age_minutes,
                   SUBSTRING(headers, 1, 100) as headers_preview
            FROM request_queue 
            $where_clause
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        
        $params[] = $limit;
        $stmt->execute($params);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get status counts
        $count_stmt = $DB->prepare("
            SELECT status, COUNT(*) as count 
            FROM request_queue 
            WHERE firewall_id = ?
            GROUP BY status
        ");
        $count_stmt->execute([$firewall_id]);
        $status_counts = $count_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'requests' => $requests,
            'status_counts' => $status_counts
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get request queue: ' . $e->getMessage()]);
    }
}

function cancelCommand() {
    global $DB;
    
    try {
        // Read JSON input
        $input = json_decode(file_get_contents('php://input'), true);
        $command_id = (int)($input['id'] ?? 0);
        $firewall_id = (int)($input['firewall_id'] ?? 21);
        
        if (!$command_id) {
            throw new Exception('Missing command ID');
        }
        
        $stmt = $DB->prepare("
            UPDATE firewall_commands 
            SET status = 'cancelled', updated_at = NOW() 
            WHERE id = ? AND firewall_id = ? AND status = 'pending'
        ");
        $stmt->execute([$command_id, $firewall_id]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Command cancelled successfully']);
        } else {
            throw new Exception('Command not found, already processed, or access denied');
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to cancel command: ' . $e->getMessage()]);
    }
}

function cancelRequest() {
    global $DB;
    
    try {
        // Read JSON input
        $input = json_decode(file_get_contents('php://input'), true);
        $request_id = (int)($input['id'] ?? 0);
        $firewall_id = (int)($input['firewall_id'] ?? 21);
        
        if (!$request_id) {
            throw new Exception('Missing request ID');
        }
        
        $stmt = $DB->prepare("
            UPDATE request_queue 
            SET status = 'cancelled', updated_at = NOW() 
            WHERE id = ? AND firewall_id = ? AND status = 'pending'
        ");
        $stmt->execute([$request_id, $firewall_id]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Request cancelled successfully']);
        } else {
            throw new Exception('Request not found, already processed, or access denied');
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to cancel request: ' . $e->getMessage()]);
    }
}

function deleteCommand() {
    global $DB;
    
    try {
        // Read JSON input
        $input = json_decode(file_get_contents('php://input'), true);
        $command_id = (int)($input['id'] ?? 0);
        $firewall_id = (int)($input['firewall_id'] ?? 21);
        
        if (!$command_id) {
            throw new Exception('Missing command ID');
        }
        
        $stmt = $DB->prepare("
            DELETE FROM firewall_commands 
            WHERE id = ? AND firewall_id = ?
        ");
        $stmt->execute([$command_id, $firewall_id]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Command deleted successfully']);
        } else {
            throw new Exception('Command not found or access denied');
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete command: ' . $e->getMessage()]);
    }
}

function deleteRequest() {
    global $DB;
    
    try {
        // Read JSON input
        $input = json_decode(file_get_contents('php://input'), true);
        $request_id = (int)($input['id'] ?? 0);
        $firewall_id = (int)($input['firewall_id'] ?? 21);
        
        if (!$request_id) {
            throw new Exception('Missing request ID');
        }
        
        $stmt = $DB->prepare("
            DELETE FROM request_queue 
            WHERE id = ? AND firewall_id = ?
        ");
        $stmt->execute([$request_id, $firewall_id]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Request deleted successfully']);
        } else {
            throw new Exception('Request not found or access denied');
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete request: ' . $e->getMessage()]);
    }
}

function clearCommandQueue() {
    global $DB;
    
    try {
        // Read JSON input
        $input = json_decode(file_get_contents('php://input'), true);
        $firewall_id = (int)($input['firewall_id'] ?? 21);
        $status = $input['status'] ?? 'all';
        
        if ($status === 'all') {
            $stmt = $DB->prepare("DELETE FROM firewall_commands WHERE firewall_id = ?");
            $stmt->execute([$firewall_id]);
        } else {
            $stmt = $DB->prepare("DELETE FROM firewall_commands WHERE firewall_id = ? AND status = ?");
            $stmt->execute([$firewall_id, $status]);
        }
        
        $cleared_count = $stmt->rowCount();
        
        echo json_encode([
            'success' => true, 
            'message' => "Cleared $cleared_count command(s)",
            'cleared_count' => $cleared_count
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to clear command queue: ' . $e->getMessage()]);
    }
}

function clearRequestQueue() {
    global $DB;
    
    try {
        // Read JSON input
        $input = json_decode(file_get_contents('php://input'), true);
        $firewall_id = (int)($input['firewall_id'] ?? 21);
        $status = $input['status'] ?? 'all';
        
        if ($status === 'all') {
            $stmt = $DB->prepare("DELETE FROM request_queue WHERE firewall_id = ?");
            $stmt->execute([$firewall_id]);
        } else {
            $stmt = $DB->prepare("DELETE FROM request_queue WHERE firewall_id = ? AND status = ?");
            $stmt->execute([$firewall_id, $status]);
        }
        
        $cleared_count = $stmt->rowCount();
        
        echo json_encode([
            'success' => true, 
            'message' => "Cleared $cleared_count request(s)",
            'cleared_count' => $cleared_count
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to clear request queue: ' . $e->getMessage()]);
    }
}

function retryCommand() {
    global $DB;
    
    try {
        $command_id = (int)($_POST['command_id'] ?? 0);
        $firewall_id = (int)($_POST['firewall_id'] ?? 21);
        
        if (!$command_id) {
            throw new Exception('Missing command ID');
        }
        
        $stmt = $DB->prepare("
            UPDATE firewall_commands 
            SET status = 'pending', sent_at = NULL, completed_at = NULL, result = NULL 
            WHERE id = ? AND firewall_id = ?
        ");
        $stmt->execute([$command_id, $firewall_id]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Command reset to pending status']);
        } else {
            throw new Exception('Command not found or access denied');
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to retry command: ' . $e->getMessage()]);
    }
}

function getQueueSummary() {
    global $DB;
    
    try {
        $firewall_id = (int)($_GET['firewall_id'] ?? 21);
        
        // Command queue summary
        $cmd_stmt = $DB->prepare("
            SELECT 
                COUNT(*) as total_commands,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_commands,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_commands,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_commands,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_commands,
                MIN(created_at) as oldest_command,
                MAX(created_at) as newest_command
            FROM firewall_commands 
            WHERE firewall_id = ?
        ");
        $cmd_stmt->execute([$firewall_id]);
        $command_summary = $cmd_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Request queue summary
        $req_stmt = $DB->prepare("
            SELECT 
                COUNT(*) as total_requests,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_requests,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_requests,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_requests,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_requests,
                MIN(created_at) as oldest_request,
                MAX(created_at) as newest_request
            FROM request_queue 
            WHERE firewall_id = ?
        ");
        $req_stmt->execute([$firewall_id]);
        $request_summary = $req_stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'firewall_id' => $firewall_id,
            'command_summary' => $command_summary,
            'request_summary' => $request_summary
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get queue summary: ' . $e->getMessage()]);
    }
}

function getRecentActivity() {
    global $DB;
    
    try {
        $firewall_id = (int)($_GET['firewall_id'] ?? 21);
        $hours = (int)($_GET['hours'] ?? 24);
        
        // Recent commands
        $cmd_stmt = $DB->prepare("
            SELECT 'command' as type, id, command as description, status, 
                   created_at, completed_at, result
            FROM firewall_commands 
            WHERE firewall_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
            
            UNION ALL
            
            SELECT 'request' as type, id, CONCAT(method, ' ', path) as description, 
                   status, created_at, completed_at, CAST(response_status AS CHAR) as result
            FROM request_queue 
            WHERE firewall_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
            
            ORDER BY created_at DESC
            LIMIT 100
        ");
        $cmd_stmt->execute([$firewall_id, $hours, $firewall_id, $hours]);
        $recent_activity = $cmd_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'recent_activity' => $recent_activity,
            'hours' => $hours
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get recent activity: ' . $e->getMessage()]);
    }
}
?>