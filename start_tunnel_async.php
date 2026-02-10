<?php
/**
 * Async Tunnel Starter - called via AJAX to start tunnel in background
 * Checks for existing active sessions before creating new one
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/scripts/manage_ssh_tunnel.php';

// Use db() connection from bootstrap
$pdo = db();

requireLogin();
requireAdmin();

header('Content-Type: application/json');

$firewall_id = (int)($_POST['firewall_id'] ?? 0);

if (!$firewall_id) {
    echo json_encode(['success' => false, 'error' => 'Missing firewall ID']);
    exit;
}

$firewall = get_firewall_by_id($firewall_id);
if (!$firewall) {
    echo json_encode(['success' => false, 'error' => 'Firewall not found']);
    exit;
}

// Pre-flight cleanup: Remove orphaned tunnels and expired sessions
// This ensures ports are available and prevents conflicts
exec("sudo /usr/bin/php " . __DIR__ . "/scripts/manage_ssh_access.php cleanup_expired 2>&1", $cleanup_output, $cleanup_result);
if ($cleanup_result !== 0) {
    error_log("WARNING: Cleanup before tunnel creation failed: " . implode("\n", $cleanup_output));
}

try {
    // Check for existing active session for this firewall
    $stmt = $pdo->prepare("
        SELECT id, tunnel_port, expires_at 
        FROM ssh_access_sessions 
        WHERE firewall_id = ? 
        AND status = 'active' 
        AND expires_at > NOW()
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->execute([$firewall_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Reuse existing session - use tunnel_proxy.php URL
        echo json_encode([
            'success' => true,
            'session_id' => $existing['id'],
            'tunnel_port' => $existing['tunnel_port'],
            'url' => "https://opn.agit8or.net/tunnel_proxy.php?session={$existing['id']}&fresh=1",
            'expires_at' => $existing['expires_at'],
            'message' => 'Using existing active tunnel session',
            'reused' => true
        ]);
        exit;
    }
    
    // No existing session, create new one
    $tunnel_result = start_tunnel($firewall, 30); // 30 minute duration
    
    if ($tunnel_result && $tunnel_result['success']) {
        $session_id = $tunnel_result['session_id'] ?? null;
        if ($session_id) {
            // Use tunnel_proxy.php URL
            $tunnel_result['url'] = "https://opn.agit8or.net/tunnel_proxy.php?session={$session_id}&fresh=1";
            $tunnel_result['reused'] = false;
        }
    }
    
    echo json_encode($tunnel_result);
} catch (Exception $e) {
    error_log("start_tunnel_async.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
