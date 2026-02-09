<?php
/**
 * Wake Agent API
 * Signals an agent to check in immediately instead of waiting for next scheduled checkin
 * Used for instant tunnel connections
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
requireLogin();

header('Content-Type: application/json');

if (!csrf_verify($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$firewall_id = (int)($_POST['firewall_id'] ?? $_GET['firewall_id'] ?? 0);

if (!$firewall_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing firewall_id']);
    exit;
}

try {
    // Set wake flag for this firewall
    $stmt = $DB->prepare('UPDATE firewalls SET wake_agent = 1, wake_requested_at = NOW() WHERE id = ?');
    $result = $stmt->execute([$firewall_id]);
    
    if ($result) {
        error_log("Wake signal sent to firewall $firewall_id");
        echo json_encode([
            'success' => true,
            'message' => 'Wake signal sent to agent',
            'firewall_id' => $firewall_id
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to set wake flag']);
    }
    
} catch (Exception $e) {
    error_log("wake_agent error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
