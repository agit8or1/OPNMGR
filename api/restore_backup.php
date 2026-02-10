<?php
/**
 * Restore Backup API
 * Queues a restore command for the specified backup
 */
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON input']);
    exit;
}

$backup_id = (int)($input['backup_id'] ?? 0);
$csrf_token = $input['csrf'] ?? '';

// Validate CSRF token
if (!csrf_verify($csrf_token)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF verification failed']);
    exit;
}

if (!$backup_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing backup ID']);
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
        echo json_encode(['error' => 'Backup not found']);
        exit;
    }
    
    // Queue restore command
    $restore_command = "curl -o /tmp/restore_config.xml '" . $_SERVER['HTTP_HOST'] . "/api/download_backup.php?id={$backup_id}' && " .
                      "configctl firmware restore /tmp/restore_config.xml && " .
                      "rm /tmp/restore_config.xml";
    
    $stmt = db()->prepare("
        INSERT INTO firewall_commands (firewall_id, command, description, status, created_at) 
        VALUES (?, ?, 'Restore configuration backup', 'pending', NOW())
    ");
    $stmt->execute([$backup['firewall_id'], $restore_command]);
    
    // Log the restore request
    $stmt = db()->prepare("
        INSERT INTO system_logs (firewall_id, category, message, level, timestamp) 
        VALUES (?, 'backup', ?, 'WARNING', NOW())
    ");
    $stmt->execute([$backup['firewall_id'], "Configuration restore queued for firewall: " . $backup['hostname']]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Restore command queued for firewall: ' . $backup['hostname']
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    error_log("restore_backup.php error: " . $e->getMessage());
    echo json_encode(['error' => 'Internal server error']);
}
?>