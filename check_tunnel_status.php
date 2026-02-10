<?php
/**
 * Check Tunnel Status API
 * Returns current status of a proxy request
 */

require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();
requireAdmin();

header('Content-Type: application/json');

$request_id = (int)($_GET['request_id'] ?? 0);

if (!$request_id) {
    echo json_encode(['error' => 'Missing request_id']);
    exit;
}

$stmt = db()->prepare('SELECT status, updated_at FROM request_queue WHERE id = ?');
$stmt->execute([$request_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    echo json_encode(['error' => 'Request not found']);
    exit;
}

echo json_encode([
    'request_id' => $request_id,
    'status' => $request['status'],
    'updated_at' => $request['updated_at']
]);
