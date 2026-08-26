<?php
/**
 * Download Backup API
 * Serves backup file for download
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/backup_storage.php';

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
    
    // resolve_backup_path() knows about both the new out-of-webroot storage and
    // the legacy /var/www/opnsense/backups directory, and basenames the legacy
    // filename so a crafted row cannot traverse out of it.
    $backup_path = resolve_backup_path($backup);

    if ($backup_path === null) {
        http_response_code(404);
        echo 'Backup file not found on disk';
        exit;
    }

    audit_log('backup.download', [
        'object_type' => 'backup',
        'object_id'   => (string)$backup_id,
        'firewall_id' => (int)$backup['firewall_id'],
        'message'     => 'Configuration backup downloaded',
    ]);
    
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