<?php
/**
 * Agent Ping Data API
 * Receives ping latency data from agents during checkin
 * 
 * Expected JSON payload:
 * {
 *     "firewall_id": 21,
 *     "agent_token": "secure_token",
 *     "ping_results": [
 *         { "latency_ms": 15.2 },
 *         { "latency_ms": 14.8 },
 *         { "latency_ms": 15.5 },
 *         { "latency_ms": 15.1 }
 *     ]
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
    $ping_results = $input['ping_results'] ?? [];
    
    if (!$firewall_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing firewall_id']);
        exit;
    }
    
    if (empty($ping_results)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing ping_results']);
        exit;
    }
    
    // Verify agent token (basic validation - could be expanded with token table)
    // For now, just verify firewall exists and is registered
    $verify_stmt = $DB->prepare('SELECT id FROM firewalls WHERE id = ?');
    $verify_stmt->execute([$firewall_id]);
    if (!$verify_stmt->fetch()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid firewall']);
        exit;
    }
    
    // Calculate average latency from ping results
    $latencies = array_filter(array_map(function($r) { 
        return isset($r['latency_ms']) ? (float)$r['latency_ms'] : null; 
    }, $ping_results));
    
    if (empty($latencies)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No valid latency data']);
        exit;
    }
    
    $average_latency = array_sum($latencies) / count($latencies);
    
    // Store each ping result in the database
    $insert_stmt = $DB->prepare('
        INSERT INTO firewall_agent_pings (firewall_id, latency_ms, ping_number, created_at)
        VALUES (?, ?, ?, NOW())
    ');
    
    $stored_count = 0;
    $ping_num = 1;
    foreach ($ping_results as $result) {
        if (isset($result['latency_ms'])) {
            $insert_stmt->execute([$firewall_id, (float)$result['latency_ms'], $ping_num]);
            $stored_count++;
            $ping_num++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Stored $stored_count ping results",
        'average_latency_ms' => round($average_latency, 2),
        'min_latency_ms' => round(min($latencies), 2),
        'max_latency_ms' => round(max($latencies), 2)
    ]);
    
} catch (Exception $e) {
    error_log("agent_ping_data.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
