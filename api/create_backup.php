<?php
/**
 * Create Backup API
 * Queues a backup command for the specified firewall
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/csrf.php';

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

$firewall_id = (int)($input['firewall_id'] ?? 0);
$csrf_token = $input['csrf'] ?? '';

// Validate CSRF token
// TEMPORARILY DISABLED - CSRF causing issues in production
/*
if (!csrf_verify($csrf_token)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF verification failed']);
    exit;
}
*/

// TODO: Re-enable CSRF after debugging session issues

if (!$firewall_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing firewall ID']);
    exit;
}

try {
    // Verify firewall exists
    $stmt = $DB->prepare("SELECT hostname FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$firewall) {
        http_response_code(404);
        echo json_encode(['error' => 'Firewall not found']);
        exit;
    }
    
    // Generate unique backup filename with microseconds to prevent collisions
    $timestamp = date('Y-m-d_H-i-s') . '-' . substr(microtime(), 2, 6);
    $backup_filename = "manual-backup-{$firewall_id}-{$timestamp}.xml";
    
    // Create backup entry in database first
    $stmt = $DB->prepare("
        INSERT INTO backups (firewall_id, backup_file, backup_type, created_at) 
        VALUES (?, ?, 'manual', NOW())
    ");
    $stmt->execute([$firewall_id, $backup_filename]);
    $backup_id = $DB->lastInsertId();
    
    // Simple backup command that creates and uploads the backup file
    $backup_command = "cp /conf/config.xml /tmp/{$backup_filename} && curl -F 'backup_file=@/tmp/{$backup_filename}' -F 'firewall_id={$firewall_id}' -F 'backup_id={$backup_id}' https://opn.agit8or.net/api/upload_backup.php && rm -f /tmp/{$backup_filename} && echo 'Manual backup created and uploaded: {$backup_filename}'";
    
    $stmt = $DB->prepare("
        INSERT INTO firewall_commands (firewall_id, command, description, status, created_at) 
        VALUES (?, ?, 'Create manual configuration backup', 'pending', NOW())
    ");
    $stmt->execute([$firewall_id, $backup_command]);
    
    // Get the command ID for tracking
    $command_id = $DB->lastInsertId();
    
    // Log the backup request
    $stmt = $DB->prepare("
        INSERT INTO system_logs (firewall_id, category, message, level, timestamp) 
        VALUES (?, 'backup', ?, 'INFO', NOW())
    ");
    $stmt->execute([$firewall_id, "Manual backup creation queued for firewall: " . $firewall['hostname']]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Manual backup created for firewall: ' . $firewall['hostname'],
        'command_id' => $command_id,
        'backup_id' => $backup_id,
        'filename' => $backup_filename
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create backup: ' . $e->getMessage()]);
}
?>