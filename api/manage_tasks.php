<?php
/**
 * Manage Scheduled Tasks API
 * Enables/disables scheduled cron tasks for the OPNsense Manager
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/logging.php';

// Require authentication
if (!check_authentication()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

// Only POST and GET allowed
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    handle_list_tasks();
} elseif ($method === 'POST') {
    handle_update_task();
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

function handle_list_tasks() {
    global $DB;
    
    try {
        $stmt = $DB->query("
            SELECT id, task_name, schedule, status, enabled, last_run, next_run
            FROM scheduled_tasks
            ORDER BY task_name
        ");
        
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If no tasks in DB, create defaults from cron
        if (empty($tasks)) {
            $tasks = get_default_tasks();
        }
        
        echo json_encode([
            'success' => true,
            'tasks' => $tasks,
            'count' => count($tasks)
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        error_log("manage_tasks.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Internal server error']);
    }
}

function handle_update_task() {
    global $DB;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['task_id']) || !isset($input['enabled'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields: task_id, enabled']);
        return;
    }
    
    $task_id = (int)$input['task_id'];
    $enabled = (bool)$input['enabled'];
    
    try {
        // Check if task exists in DB
        $stmt = $DB->prepare("SELECT id FROM scheduled_tasks WHERE id = ?");
        $stmt->execute([$task_id]);
        $task = $stmt->fetch();
        
        if ($task) {
            // Update existing
            $stmt = $DB->prepare("UPDATE scheduled_tasks SET enabled = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$enabled ? 1 : 0, $task_id]);
            $message = 'Task ' . ($enabled ? 'enabled' : 'disabled') . ' successfully';
        } else {
            // Shouldn't happen but handle gracefully
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Task not found']);
            return;
        }
        
        log_info('tasks', $message . ' (ID: ' . $task_id . ')', null, null);
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'task_id' => $task_id,
            'enabled' => $enabled
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        error_log("manage_tasks.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Internal server error']);
    }
}

function get_default_tasks() {
    // Return the default tasks that should always exist
    return [
        [
            'id' => 1,
            'task_name' => 'Nightly Backups',
            'schedule' => '2:00 AM daily',
            'status' => 'active',
            'enabled' => 1,
            'last_run' => date('Y-m-d H:i:s'),
            'next_run' => date('Y-m-d H:i:s', strtotime('+1 day'))
        ],
        [
            'id' => 2,
            'task_name' => 'Firewall Health Check',
            'schedule' => 'Every minute',
            'status' => 'active',
            'enabled' => 1,
            'last_run' => date('Y-m-d H:i:s'),
            'next_run' => date('Y-m-d H:i:s', time() + 60)
        ],
        [
            'id' => 3,
            'task_name' => 'SSH Tunnel Cleanup',
            'schedule' => 'Every 5 minutes',
            'status' => 'active',
            'enabled' => 1,
            'last_run' => date('Y-m-d H:i:s'),
            'next_run' => date('Y-m-d H:i:s', time() + 300)
        ],
        [
            'id' => 4,
            'task_name' => 'Proxy Session Cleanup',
            'schedule' => 'Every 5 minutes',
            'status' => 'active',
            'enabled' => 1,
            'last_run' => date('Y-m-d H:i:s'),
            'next_run' => date('Y-m-d H:i:s', time() + 300)
        ],
        [
            'id' => 5,
            'task_name' => 'AI Report Housekeeping',
            'schedule' => '3:00 AM daily',
            'status' => 'active',
            'enabled' => 1,
            'last_run' => date('Y-m-d H:i:s'),
            'next_run' => date('Y-m-d H:i:s', strtotime('+1 day'))
        ]
    ];
}

?>
