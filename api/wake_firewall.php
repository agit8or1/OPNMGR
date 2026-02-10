<?php
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

$input = json_decode(file_get_contents('php://input'), true);
$firewall_id = intval($input['firewall_id'] ?? 0);

if ($firewall_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid firewall_id']);
    exit;
}

try {
    // Set wake_agent flag to trigger immediate checkin on next cron
    $stmt = db()->prepare('UPDATE firewalls SET wake_agent = 1 WHERE id = ?');
    $stmt->execute([$firewall_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Wake signal sent to firewall',
        'firewall_id' => $firewall_id
    ]);
} catch (PDOException $e) {
    error_log("wake_firewall error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
?>
