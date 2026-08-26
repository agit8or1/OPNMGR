<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/backup_storage.php';

requireLogin();

/**
 * Create Backup API
 * Queues a backup command for the specified firewall
 */
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
$csrf_token = $input['csrf'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

// Validate CSRF token
if (!csrf_verify($csrf_token)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF verification failed']);
    exit;
}

if (!$firewall_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing firewall ID']);
    exit;
}

try {
    // Verify firewall exists
    $stmt = db()->prepare("SELECT hostname FROM firewalls WHERE id = ?");
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
    $stmt = db()->prepare("
        INSERT INTO backups (firewall_id, backup_file, backup_type, created_at) 
        VALUES (?, ?, 'manual', NOW())
    ");
    $stmt->execute([$firewall_id, $backup_filename]);
    $backup_id = db()->lastInsertId();
    
    // Built centrally so the command carries the agent's credentials (without
    // embedding them in the queued command text) and targets the configured
    // server URL rather than a hardcoded hostname.
    $backup_command = build_backup_upload_command($firewall_id, (int)$backup_id, $backup_filename);
    
    $stmt = db()->prepare("
        INSERT INTO firewall_commands (firewall_id, command, description, status, created_at) 
        VALUES (?, ?, 'Create manual configuration backup', 'pending', NOW())
    ");
    $stmt->execute([$firewall_id, $backup_command]);
    
    // Get the command ID for tracking
    $command_id = db()->lastInsertId();
    
    // Log the backup request
    $stmt = db()->prepare("
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
    error_log("create_backup.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create backup']);
}
?>