<?php
/**
 * Request Queue Management System
 * Handles cleanup, logging, and monitoring of HTTP request queues
 */

require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'cleanup_old_requests':
        cleanupOldRequests();
        break;
    case 'get_queue_status':
        getQueueStatus();
        break;
    case 'get_failed_requests':
        getFailedRequests();
        break;
    case 'get_request_stats':
        getRequestStats();
        break;
    case 'clear_completed_requests':
        clearCompletedRequests();
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}

function cleanupOldRequests() {
    global $DB;
    
    try {
        // Define timeout periods (in minutes)
        $pending_timeout = 15;    // Pending requests timeout after 15 minutes
        $completed_timeout = 60;  // Completed requests kept for 1 hour for logging
        $failed_timeout = 240;    // Failed requests kept for 4 hours for debugging
        
        // Log failed requests before cleanup
        $failed_stmt = $DB->prepare("
            SELECT id, firewall_id, client_id, method, path, status, created_at, 
                   TIMESTAMPDIFF(MINUTE, created_at, NOW()) as age_minutes
            FROM request_queue 
            WHERE (status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))
               OR (status = 'processing' AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))
               OR (status = 'failed' AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))
        ");
        
        $failed_stmt->execute([$pending_timeout, $pending_timeout * 2, $failed_timeout]);
        $failed_requests = $failed_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Log to failed requests log
        if ($failed_requests) {
            $log_file = '/var/log/opnmgr/failed_requests.log';
            $log_dir = dirname($log_file);
            if (!is_dir($log_dir)) {
                mkdir($log_dir, 0755, true);
            }
            
            foreach ($failed_requests as $req) {
                $log_entry = sprintf(
                    "[%s] TIMEOUT - ID:%d FW:%d Client:%s %s %s Status:%s Age:%dmin\n",
                    date('Y-m-d H:i:s'),
                    $req['id'],
                    $req['firewall_id'],
                    $req['client_id'],
                    $req['method'],
                    $req['path'],
                    $req['status'],
                    $req['age_minutes']
                );
                file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
            }
        }
        
        // Cleanup old pending/processing requests (mark as failed first)
        $timeout_stmt = $DB->prepare("
            UPDATE request_queue 
            SET status = 'failed', completed_at = NOW() 
            WHERE (status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))
               OR (status = 'processing' AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))
        ");
        $timeout_count = $timeout_stmt->execute([$pending_timeout, $pending_timeout * 2]);
        $timed_out = $timeout_stmt->rowCount();
        
        // Remove old completed requests
        $completed_stmt = $DB->prepare("
            DELETE FROM request_queue 
            WHERE status = 'completed' AND completed_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $completed_stmt->execute([$completed_timeout]);
        $completed_removed = $completed_stmt->rowCount();
        
        // Remove old failed requests
        $failed_cleanup_stmt = $DB->prepare("
            DELETE FROM request_queue 
            WHERE status = 'failed' AND completed_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $failed_cleanup_stmt->execute([$failed_timeout]);
        $failed_removed = $failed_cleanup_stmt->rowCount();
        
        echo json_encode([
            'success' => true,
            'timed_out' => $timed_out,
            'completed_removed' => $completed_removed,
            'failed_removed' => $failed_removed,
            'total_cleaned' => $timed_out + $completed_removed + $failed_removed
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Cleanup failed: ' . $e->getMessage()]);
    }
}

function getQueueStatus() {
    global $DB;
    
    try {
        // Get current queue statistics
        $stats_stmt = $DB->prepare("
            SELECT 
                status,
                COUNT(*) as count,
                MIN(created_at) as oldest,
                MAX(created_at) as newest,
                AVG(TIMESTAMPDIFF(MINUTE, created_at, NOW())) as avg_age_minutes
            FROM request_queue 
            WHERE firewall_id = ?
            GROUP BY status
        ");
        
        $firewall_id = (int)($_GET['firewall_id'] ?? 21);
        $stats_stmt->execute([$firewall_id]);
        $stats = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get recent requests
        $recent_stmt = $DB->prepare("
            SELECT id, client_id, method, path, status, created_at, completed_at,
                   TIMESTAMPDIFF(MINUTE, created_at, NOW()) as age_minutes
            FROM request_queue 
            WHERE firewall_id = ?
            ORDER BY created_at DESC 
            LIMIT 20
        ");
        $recent_stmt->execute([$firewall_id]);
        $recent_requests = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'firewall_id' => $firewall_id,
            'statistics' => $stats,
            'recent_requests' => $recent_requests
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get queue status: ' . $e->getMessage()]);
    }
}

function getFailedRequests() {
    global $DB;
    
    try {
        $firewall_id = (int)($_GET['firewall_id'] ?? 21);
        $limit = (int)($_GET['limit'] ?? 50);
        
        $stmt = $DB->prepare("
            SELECT id, client_id, method, path, status, created_at, completed_at,
                   TIMESTAMPDIFF(MINUTE, created_at, COALESCE(completed_at, NOW())) as duration_minutes
            FROM request_queue 
            WHERE firewall_id = ? AND status = 'failed'
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$firewall_id, $limit]);
        $failed_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'failed_requests' => $failed_requests
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get failed requests: ' . $e->getMessage()]);
    }
}

function getRequestStats() {
    global $DB;
    
    try {
        // Overall statistics for the last 24 hours
        $stmt = $DB->prepare("
            SELECT 
                COUNT(*) as total_requests,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                AVG(CASE WHEN status = 'completed' THEN TIMESTAMPDIFF(MINUTE, created_at, completed_at) END) as avg_completion_time
            FROM request_queue 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Hourly breakdown for last 24 hours
        $hourly_stmt = $DB->prepare("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as hour,
                COUNT(*) as requests,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM request_queue 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d %H')
            ORDER BY hour DESC
        ");
        $hourly_stmt->execute();
        $hourly_stats = $hourly_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'overall_stats' => $stats,
            'hourly_breakdown' => $hourly_stats
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get request statistics: ' . $e->getMessage()]);
    }
}

function clearCompletedRequests() {
    global $DB;
    
    try {
        $firewall_id = (int)($_GET['firewall_id'] ?? 21);
        
        $stmt = $DB->prepare("
            DELETE FROM request_queue 
            WHERE firewall_id = ? AND status = 'completed'
        ");
        $stmt->execute([$firewall_id]);
        $cleared = $stmt->rowCount();
        
        echo json_encode([
            'success' => true,
            'cleared_count' => $cleared
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to clear completed requests: ' . $e->getMessage()]);
    }
}
?>