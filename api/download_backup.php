<?php
/**
 * Download Backup API
 * Serves backup file for download
 */
require_once __DIR__ . '/../inc/bootstrap.php';

requireLogin();

$backup_id = (int)($_GET['id'] ?? 0);

if (!$backup_id) {
    http_response_code(400);
    echo 'Missing backup ID';
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
        echo 'Backup not found';
        exit;
    }
    
    $backup_dir = '/var/www/opnsense/backups';
    $backup_path = $backup_dir . '/' . $backup['backup_file'];
    
    if (!file_exists($backup_path)) {
        http_response_code(404);
        echo 'Backup file not found on disk';
        exit;
    }
    
    // Set headers for download
    $filename = $backup['hostname'] . '_' . date('Y-m-d_H-i-s', strtotime($backup['created_at'])) . '.xml';
    header('Content-Type: application/xml');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($backup_path));
    
    // Output file
    readfile($backup_path);
    
} catch (Exception $e) {
    http_response_code(500);
    error_log("download_backup.php error: " . $e->getMessage());
    echo 'Internal server error';
}
?>