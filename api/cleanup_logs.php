<?php
require_once __DIR__ . '/../inc/bootstrap.php';

require_once __DIR__ . '/../inc/logging.php';
requireLogin();
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// CSRF validation
$csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!csrf_verify($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
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
        'message' => 'Internal server error'
    ]);
}
?>