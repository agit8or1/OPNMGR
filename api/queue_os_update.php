<?php
// Enhanced OS Update Command Generator
// Creates comprehensive OS update commands for execution via agent command queue

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
requireLogin();
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$firewall_id = (int)($input['firewall_id'] ?? 0);
$update_type = trim($input['update_type'] ?? 'full');

if (!$firewall_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid firewall ID']);
    exit;
}

// Get firewall details
$stmt = $DB->prepare("SELECT hostname, ip_address FROM firewalls WHERE id = ?");
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch();

if (!$firewall) {
    echo json_encode(['success' => false, 'message' => 'Firewall not found']);
    exit;
}

// Generate comprehensive update command based on type
$commands = [];

switch ($update_type) {
    case 'packages':
        $commands[] = [
            'command' => 'pkg update -f && pkg upgrade -y',
            'description' => 'Update packages only'
        ];
        break;
        
    case 'firmware':
        $commands[] = [
            'command' => 'opnsense-update -f',
            'description' => 'Update OPNsense firmware'
        ];
        break;
        
    case 'full':
    default:
        // Comprehensive update sequence
        $commands[] = [
            'command' => 'echo "=== Starting Full System Update ===" && pkg update -f',
            'description' => 'Update package repositories'
        ];
        $commands[] = [
            'command' => 'pkg upgrade -y',
            'description' => 'Upgrade all packages'
        ];
        $commands[] = [
            'command' => 'opnsense-update -f',
            'description' => 'Update OPNsense firmware'
        ];
        $commands[] = [
            'command' => 'echo "=== Update Complete - System may reboot if required ==="',
            'description' => 'Mark update completion'
        ];
        break;
}

// Queue all commands
$queued_commands = [];
try {
    foreach ($commands as $cmd) {
        $stmt = $DB->prepare("INSERT INTO firewall_commands (firewall_id, command, description, status) VALUES (?, ?, ?, 'pending')");
        $stmt->execute([$firewall_id, $cmd['command'], $cmd['description']]);
        $queued_commands[] = [
            'id' => $DB->lastInsertId(),
            'command' => $cmd['command'],
            'description' => $cmd['description']
        ];
    }
    
    // Update firewall status
    $stmt = $DB->prepare("UPDATE firewalls SET 
        update_requested = 1,
        update_requested_at = NOW(),
        update_type = ?,
        status = 'update_pending'
        WHERE id = ?");
    $stmt->execute([$update_type, $firewall_id]);
    
    echo json_encode([
        'success' => true,
        'message' => "OS update commands queued for {$firewall['hostname']} (type: $update_type)",
        'queued_commands' => $queued_commands,
        'firewall' => $firewall['hostname']
    ]);
    
} catch (Exception $e) {
    error_log("queue_os_update.php error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}
?>