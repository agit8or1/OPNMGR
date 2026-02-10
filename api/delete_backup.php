<?php
/**
 * Delete Backup API
 * Deletes specified backup file and database record
 */
require_once __DIR__ . '/../inc/bootstrap.php';

requireLogin();
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit;
}

$backup_id = (int)($input['backup_id'] ?? 0);
$csrf_token = $input['csrf'] ?? '';

// Debug info
$debug = [
    'session_id' => session_id(),
    'session_has_token' => isset($_SESSION['csrf_token']),
    'session_token' => isset($_SESSION['csrf_token']) ? substr($_SESSION['csrf_token'], 0, 10) . '...' : 'NOT SET',
    'input_token' => $csrf_token ? substr($csrf_token, 0, 10) . '...' : 'EMPTY',
    'tokens_match' => isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $csrf_token)
];

// Validate CSRF token
if (!csrf_verify($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF verification failed', 'debug' => $debug]);
    exit;
}

if (!$backup_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing backup ID']);
    exit;
}

try {
    // Get backup info
    $stmt = db()->prepare("
        SELECT b.*, f.hostname 
        FROM backups b 
        JOIN firewalls f ON b.firewall_id = f.id 
        WHERE b.id = ?
    ");
    $stmt->execute([$backup_id]);
    $backup = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$backup) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Backup not found']);
        exit;
    }
    
    // Delete backup file (if it exists)
    $backup_dir = '/var/www/opnsense/backups';
    $backup_path = $backup_dir . '/' . $backup['backup_file'];
    $file_deleted = false;
    
    if (file_exists($backup_path)) {
        if (unlink($backup_path)) {
            $file_deleted = true;
        }
    }
    
    // Delete database record (even if file doesn't exist)
    $stmt = db()->prepare("DELETE FROM backups WHERE id = ?");
    $stmt->execute([$backup_id]);
    
    // Log the deletion
    $stmt = db()->prepare("
        INSERT INTO system_logs (firewall_id, category, message, level, timestamp) 
        VALUES (?, 'backup', ?, 'INFO', NOW())
    ");
    $stmt->execute([$backup['firewall_id'], "Backup deleted for firewall: " . $backup['hostname']]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Backup deleted successfully'
    ]);
    
} catch (Exception $e) {
    error_log("delete_backup.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}
?>