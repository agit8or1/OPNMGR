<?php
/**
 * Check Update Request API
 * Separate service checks if firewall update is requested
 */
require_once __DIR__ . '/../inc/bootstrap_agent.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$firewall_id = (int)($input['firewall_id'] ?? 0);
$hardware_id = trim($input['hardware_id'] ?? '');

if (!$firewall_id || empty($hardware_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing firewall_id or hardware_id']);
    exit;
}

// Validate agent identity
$auth_stmt = db()->prepare('SELECT hardware_id FROM firewalls WHERE id = ?');
$auth_stmt->execute([$firewall_id]);
$auth_fw = $auth_stmt->fetch(PDO::FETCH_ASSOC);
if (!$auth_fw || (
    !empty($auth_fw['hardware_id']) && !hash_equals($auth_fw['hardware_id'], $hardware_id)
)) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication failed']);
    exit;
}

try {
    $stmt = db()->prepare('SELECT update_requested FROM firewalls WHERE id = ?');
    $stmt->execute([$firewall_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && $result['update_requested']) {
        echo json_encode([
            'success' => true,
            'update_requested' => true,
            'update_command' => '/usr/sbin/pkg update -f && /usr/sbin/pkg upgrade -y'
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'update_requested' => false
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log("check_update_request.php error: " . $e->getMessage());
    echo json_encode(['error' => 'Internal server error']);
}
?>
