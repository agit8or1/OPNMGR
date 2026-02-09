<?php
/**
 * Network Diagnostics API
 * Runs ping or traceroute commands from firewall
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';

requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!csrf_verify($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$firewall_id = (int)($_POST['firewall_id'] ?? 0);
$command_type = $_POST['command_type'] ?? '';
$target = $_POST['target'] ?? '';

if (!$firewall_id || !$command_type || !$target) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

if (!in_array($command_type, ['ping', 'traceroute'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid command type']);
    exit;
}

// Basic validation for target (IP or hostname)
if (!filter_var($target, FILTER_VALIDATE_IP) && !filter_var($target, FILTER_VALIDATE_DOMAIN)) {
    echo json_encode(['success' => false, 'error' => 'Invalid target IP or hostname']);
    exit;
}

// Verify firewall exists and is online
$stmt = $DB->prepare("SELECT id, hostname, status FROM firewalls WHERE id = ?");
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$firewall) {
    echo json_encode(['success' => false, 'error' => 'Firewall not found']);
    exit;
}

if ($firewall['status'] !== 'online') {
    echo json_encode(['success' => false, 'error' => 'Firewall is not online']);
    exit;
}

try {
    // Create appropriate command
    if ($command_type === 'ping') {
        $command = <<<BASH
#!/bin/sh
# Ping diagnostic from OPNsense firewall
TARGET="$target"
echo "=== PING TEST TO \$TARGET ==="
echo "Started: \$(date)"
echo ""

# Ping with 4 packets, 5 second timeout
ping -c 4 -W 5000 "\$TARGET" 2>&1

echo ""
echo "Completed: \$(date)"
BASH;
    } else {
        $command = <<<BASH
#!/bin/sh
# Traceroute diagnostic from OPNsense firewall  
TARGET="$target"
echo "=== TRACEROUTE TO \$TARGET ==="
echo "Started: \$(date)"
echo ""

# Traceroute with max 30 hops, 5 second timeout
traceroute -m 30 -w 5 "\$TARGET" 2>&1

echo ""
echo "Completed: \$(date)"
BASH;
    }
    
    $description = ucfirst($command_type) . " diagnostic to " . $target;
    
    // Queue command for firewall
    $stmt = $DB->prepare("
        INSERT INTO firewall_commands (firewall_id, command, description, command_type, status)
        VALUES (?, ?, ?, 'shell', 'pending')
    ");
    $stmt->execute([$firewall_id, $command, $description]);
    
    $command_id = $DB->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'command_id' => $command_id,
        'message' => $description . ' queued for execution'
    ]);
    
} catch (Exception $e) {
    error_log("network_diagnostic error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}