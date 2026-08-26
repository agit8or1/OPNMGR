<?php
// API endpoint to record latency measurements from firewalls
// Called by agent v3.6.1+ via POST
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
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit;
}

$firewall_id = (int)($input['firewall_id'] ?? 0);
$hardware_id = trim($input['hardware_id'] ?? '');


// Centralised agent authentication: identity resolution, hardware_id
// pinning, API key verification and HMAC signature checking all live in
// inc/agent_auth.php. It emits a generic error and exits on failure.
$authenticated_firewall = authenticateAgentRequest(
    array_merge(is_array($input) ? $input : [], ['firewall_id' => $firewall_id])
);
$firewall_id = (int)$authenticated_firewall['id'];

$latency_ms = floatval($input['latency_ms'] ?? 0);

try {
    // Create table if it doesn't exist
    db()->exec("CREATE TABLE IF NOT EXISTS firewall_latency (
        id BIGINT PRIMARY KEY AUTO_INCREMENT,
        firewall_id INT NOT NULL,
        latency_ms FLOAT DEFAULT 0,
        measured_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_firewall_time (firewall_id, measured_at)
    )");

    // Insert latency record
    $stmt = db()->prepare("INSERT INTO firewall_latency (firewall_id, latency_ms) VALUES (?, ?)");
    $stmt->execute([$firewall_id, $latency_ms]);

    // Also update firewall_agents with latest latency
    $stmt = db()->prepare("UPDATE firewall_agents SET latency_ms = ? WHERE firewall_id = ? ORDER BY last_checkin DESC LIMIT 1");
    $stmt->execute([$latency_ms, $firewall_id]);

    echo json_encode([
        'success' => true,
        'message' => 'Latency recorded',
        'latency_ms' => $latency_ms
    ]);

} catch (Exception $e) {
    error_log("Latency recording error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error recording latency']);
}
?>
