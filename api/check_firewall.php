<?php
require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/agent_auth.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}
// Validate agent identity
$firewall_id = (int)($input['firewall_id'] ?? 0);
$hardware_id = trim($input['hardware_id'] ?? '');


// Centralised agent authentication: identity resolution, hardware_id
// pinning, API key verification and HMAC signature checking all live in
// inc/agent_auth.php. It emits a generic error and exits on failure.
$authenticated_firewall = authenticateAgentRequest(
    array_merge(is_array($input) ? $input : [], ['firewall_id' => $firewall_id])
);
$firewall_id = (int)$authenticated_firewall['id'];

$hostname = trim($input['hostname'] ?? '');
$ip_address = trim($input['ip_address'] ?? '');
if (empty($hostname)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Hostname required']);
    exit;
}
try {
    $stmt = db()->prepare('SELECT id, hostname, ip_address FROM firewalls WHERE hostname = ?');
    $stmt->execute([$hostname]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($firewall) {
        if (!empty($ip_address) && $firewall['ip_address'] !== $ip_address) {
            $update_stmt = db()->prepare('UPDATE firewalls SET ip_address = ?, last_checkin = NOW() WHERE id = ?');
            $update_stmt->execute([$ip_address, $firewall['id']]);
        }
        echo json_encode([
            'success' => true,
            'exists' => true,
            'firewall_id' => intval($firewall['id']),
            'hostname' => $firewall['hostname'],
            'ip_address' => $ip_address ?: $firewall['ip_address']
        ]);
    } else {
        echo json_encode(['success' => true, 'exists' => false]);
    }
} catch (Exception $e) {
    error_log("Check firewall error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
