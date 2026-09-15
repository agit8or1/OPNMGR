<?php
/**
 * Run Bandwidth Test API
 * Triggers bandwidth test on firewall via agent command
 */
require_once __DIR__ . '/../inc/bootstrap.php';

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

if (!$firewall_id) {
    echo json_encode(['success' => false, 'error' => 'Missing firewall ID']);
    exit;
}

// Verify firewall exists and is online
$stmt = db()->prepare("SELECT id, hostname, status FROM firewalls WHERE id = ?");
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
    // Check if there's already a running test
    $stmt = db()->prepare("
        SELECT id FROM bandwidth_tests 
        WHERE firewall_id = ? AND test_status = 'running' 
        AND tested_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    ");
    $stmt->execute([$firewall_id]);
    
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Bandwidth test already running']);
        exit;
    }
    
    // Create bandwidth test record
    $stmt = db()->prepare("
        INSERT INTO bandwidth_tests (firewall_id, test_type, test_status) 
        VALUES (?, 'manual', 'running')
    ");
    $stmt->execute([$firewall_id]);
    $test_id = db()->lastInsertId();
    
    // Queue agent command to run iperf3 bandwidth test
    // The agent's run_speedtest function will use iperf3 to test to opn.agit8or.net
    // Results are returned via agent check-in and recorded in bandwidth_tests table

    // Queue command for agent to run built-in speedtest (uses iperf3)
    $stmt = db()->prepare("
        INSERT INTO firewall_commands (firewall_id, command, description, command_type, status)
        VALUES (?, 'run_speedtest', ?, 'shell', 'pending')
    ");
    $stmt->execute([
        $firewall_id,
        'Bandwidth test via iperf3 (ID: ' . $test_id . ')'
    ]);
    
    echo json_encode([
        'success' => true,
        'test_id' => $test_id,
        'message' => 'Bandwidth test queued for execution'
    ]);
    
} catch (Exception $e) {
    error_log("run_bandwidth_test error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}