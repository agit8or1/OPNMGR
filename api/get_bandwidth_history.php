<?php
/**
 * Get Bandwidth Test History API
 * Returns recent bandwidth tests for a firewall
 */
require_once __DIR__ . '/../inc/bootstrap.php';

requireLogin();
header('Content-Type: application/json');

$firewall_id = (int)($_GET['firewall_id'] ?? 0);

if (!$firewall_id) {
    echo json_encode(['success' => false, 'error' => 'Missing firewall ID']);
    exit;
}

try {
    // Get last 10 bandwidth tests
    $stmt = db()->prepare("
        SELECT 
            id,
            test_type,
            download_speed,
            upload_speed,
            latency,
            test_server,
            test_status,
            error_message,
            test_duration,
            DATE_FORMAT(tested_at, '%m/%d %H:%i') as tested_at
        FROM bandwidth_tests 
        WHERE firewall_id = ? 
        ORDER BY tested_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$firewall_id]);
    $tests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'tests' => $tests
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}