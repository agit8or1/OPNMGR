<?php
/**
 * Get real-time status of agent repair operation
 */
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');

// Verify authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$session_id = $_GET['session_id'] ?? '';

if (empty($session_id)) {
    echo json_encode(['success' => false, 'error' => 'Invalid session ID']);
    exit;
}

$log_file = "/tmp/agent_repair_{$session_id}.log";

if (!file_exists($log_file)) {
    echo json_encode([
        'success' => false,
        'error' => 'Session not found or expired',
        'status' => 'unknown'
    ]);
    exit;
}

// Read log file
$log_content = file_get_contents($log_file);
$log_lines = explode("\n", trim($log_content));

// Parse log to determine status
$status = 'running';
$progress = 0;
$current_step = 'Initializing...';
$error = null;
$completed = false;

foreach ($log_lines as $line) {
    if (strpos($line, '[ERROR]') !== false) {
        $status = 'error';
        $error = trim(substr($line, strpos($line, '[ERROR]') + 7));
    } elseif (strpos($line, '[COMPLETE]') !== false) {
        $status = 'complete';
        $completed = true;
        $progress = 100;
        $current_step = 'Repair completed successfully';
    } elseif (strpos($line, '[STEP]') !== false) {
        $current_step = trim(substr($line, strpos($line, '[STEP]') + 6));

        // Calculate progress based on step
        if (strpos($current_step, 'Testing SSH') !== false) {
            $progress = 20;
        } elseif (strpos($current_step, 'Creating repair script') !== false) {
            $progress = 40;
        } elseif (strpos($current_step, 'Transferring') !== false) {
            $progress = 60;
        } elseif (strpos($current_step, 'Executing repair') !== false) {
            $progress = 80;
        }
    } elseif (strpos($line, '[SUCCESS]') !== false && $progress < 90) {
        $progress = min($progress + 10, 90);
    }
}

// If no complete marker but file is old, assume timeout
if (!$completed && (time() - filemtime($log_file)) > 120) {
    $status = 'timeout';
    $error = 'Operation timed out after 2 minutes';
}

echo json_encode([
    'success' => true,
    'status' => $status,
    'progress' => $progress,
    'current_step' => $current_step,
    'error' => $error,
    'completed' => $completed,
    'log' => $log_lines
]);
