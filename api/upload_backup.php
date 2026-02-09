<?php
/**
 * Upload Backup API
 * Receives backup files from firewalls
 */

require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$firewall_id = (int)($_POST['firewall_id'] ?? 0);
$hardware_id = trim($_POST['hardware_id'] ?? '');

if (!$firewall_id || empty($hardware_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing authentication']);
    exit;
}

$auth_stmt = $DB->prepare('SELECT hardware_id FROM firewalls WHERE id = ?');
$auth_stmt->execute([$firewall_id]);
$auth_fw = $auth_stmt->fetch(PDO::FETCH_ASSOC);
if (!$auth_fw || (
    !empty($auth_fw['hardware_id']) && !hash_equals($auth_fw['hardware_id'], $hardware_id)
)) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication failed']);
    exit;
}

if (!isset($_FILES['backup_file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No backup file uploaded']);
    exit;
}

try {
    $uploaded_file = $_FILES['backup_file'];
    $backup_dir = '/var/www/opnsense/backups';
    
    // Ensure backup directory exists
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }
    
    $target_path = $backup_dir . '/' . basename($uploaded_file['name']);
    
    if (move_uploaded_file($uploaded_file['tmp_name'], $target_path)) {
        // Update backup record with file size
        $file_size = filesize($target_path);
        $stmt = $DB->prepare("
            UPDATE backups 
            SET file_size = ? 
            WHERE firewall_id = ? AND backup_file = ?
        ");
        $stmt->execute([$file_size, $firewall_id, basename($uploaded_file['name'])]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Backup uploaded successfully'
        ]);
    } else {
        throw new Exception('Failed to save uploaded file');
    }
    
} catch (Exception $e) {
    error_log("upload_backup.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to upload backup']);
}
?>