<?php
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/logging.php';
requireLogin();
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $deleted_count = cleanup_old_logs(30);
    
    log_info('system', "Admin manually triggered log cleanup", $_SESSION['user_id'] ?? null, null, [
        'deleted_count' => $deleted_count
    ]);
    
    echo json_encode([
        'success' => true,
        'deleted_count' => $deleted_count,
        'message' => "Successfully cleaned up $deleted_count old log entries"
    ]);
} catch (Exception $e) {
    log_error('system', "Log cleanup failed: " . $e->getMessage(), $_SESSION['user_id'] ?? null);
    
    echo json_encode([
        'success' => false,
        'message' => 'Failed to cleanup logs: ' . $e->getMessage()
    ]);
}
?>