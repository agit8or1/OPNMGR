<?php
require_once __DIR__ . '/../inc/bootstrap.php';

requireLogin();

/**
 * QuickConnect Check API
 * Ultra-fast endpoint for quickconnect agent
 * Only returns TRUE/FALSE if there are pending tunnel requests
 */

header('Content-Type: application/json');

// Get JSON input (no DB connection unless needed)
$input = json_decode(file_get_contents('php://input'), true);
$firewall_id = (int)($input['firewall_id'] ?? 0);

if (!$firewall_id) {
    echo json_encode(['has_requests' => false]);
    exit;
}

// Only connect to DB if we have a valid firewall ID
try {
    // Super fast query - just check if ANY pending requests exist
    $stmt = db()->prepare('SELECT 1 FROM request_queue WHERE firewall_id = ? AND status = "pending" LIMIT 1');
    $stmt->execute([$firewall_id]);
    $has_requests = (bool)$stmt->fetch();
    
    echo json_encode([
        'has_requests' => $has_requests,
        'firewall_id' => $firewall_id
    ]);
    
} catch (Exception $e) {
    echo json_encode(['has_requests' => false]);
}
