<?php
/**
 * Agent SpeedTest Result API
 * Receives speedtest results from agents running nightly speedtest scheduler
 * 
 * Expected JSON payload:
 * {
 *     "firewall_id": 21,
 *     "agent_token": "secure_token",
 *     "download_mbps": 465.3,
 *     "upload_mbps": 95.7,
 *     "ping_ms": 15.2,
 *     "server_location": "New York",
 *     "test_duration_seconds": 45,
 *     "timestamp": "2025-10-29T23:15:00Z"
 * }
 */

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        exit;
    }
    
    $firewall_id = (int)($input['firewall_id'] ?? 0);
    $agent_token = $input['agent_token'] ?? '';
    $download_mbps = (float)($input['download_mbps'] ?? 0);
    $upload_mbps = (float)($input['upload_mbps'] ?? 0);
    $ping_ms = (float)($input['ping_ms'] ?? 0);
    $server_location = $input['server_location'] ?? 'Unknown';
    $test_duration = (int)($input['test_duration_seconds'] ?? 0);
    
    // Validate required fields
    if (!$firewall_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing firewall_id']);
        exit;
    }
    
    if ($download_mbps <= 0 || $upload_mbps < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid speed values']);
        exit;
    }
    
    // Verify firewall exists
    $verify_stmt = $DB->prepare('SELECT id FROM firewalls WHERE id = ?');
    $verify_stmt->execute([$firewall_id]);
    if (!$verify_stmt->fetch()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid firewall']);
        exit;
    }
    
    // Store speedtest result
    $insert_stmt = $DB->prepare('
        INSERT INTO firewall_speedtest (firewall_id, download_mbps, upload_mbps, ping_ms, server_location, test_date)
        VALUES (?, ?, ?, ?, ?, NOW())
    ');
    
    $insert_stmt->execute([
        $firewall_id,
        $download_mbps,
        $upload_mbps,
        $ping_ms,
        $server_location
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'SpeedTest result stored successfully',
        'firewall_id' => $firewall_id,
        'download_mbps' => $download_mbps,
        'upload_mbps' => $upload_mbps,
        'ping_ms' => $ping_ms,
        'server_location' => $server_location,
        'stored_at' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log("agent_speedtest_result.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
