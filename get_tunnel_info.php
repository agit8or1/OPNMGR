<?php
/**
 * Get tunnel information for footer badge display
 */
require_once __DIR__ . '/inc/bootstrap.php';

requireLogin();

header('Content-Type: application/json');

$firewall_id = (int)($_GET['firewall_id'] ?? 0);

if (!$firewall_id) {
    echo json_encode(['error' => 'Missing firewall_id']);
    exit;
}

try {
    // Get active tunnel session for this firewall
    $stmt = db()->prepare("
        SELECT tunnel_port, created_at, expires_at 
        FROM ssh_access_sessions 
        WHERE firewall_id = ? AND status = 'active' 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$firewall_id]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($session) {
        echo json_encode([
            'success' => true,
            'tunnel_port' => (int)$session['tunnel_port'],
            'https_port' => (int)$session['tunnel_port'] - 1,
            'created_at' => $session['created_at'],
            'expires_at' => $session['expires_at']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'No active tunnel found'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error'
    ]);
}
