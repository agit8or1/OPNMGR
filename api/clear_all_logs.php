<?php
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';
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
    // Count logs before deletion
    $stmt = $DB->query('SELECT COUNT(*) as count FROM system_logs');
    $count_before = $stmt->fetchColumn();
    
    // Delete all logs
    $stmt = $DB->query('DELETE FROM system_logs');
    $deleted_count = $stmt->rowCount();
    
    // Log this action (will be the first entry in the fresh log)
    log_action('System Management', 'WARNING', 'All system logs cleared by admin user: ' . ($_SESSION['username'] ?? 'unknown'), '', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    
    echo json_encode([
        'success' => true,
        'deleted_count' => $deleted_count,
        'message' => "Successfully cleared all system logs"
    ]);
    
} catch (Exception $e) {
    error_log("Failed to clear all logs: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}
?>