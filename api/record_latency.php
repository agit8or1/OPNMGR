<?php
// API endpoint to record latency measurements from firewalls
// Called by agent v3.6.1+ via POST
require_once __DIR__ . '/../inc/bootstrap_agent.php';

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

if (!$firewall_id || empty($hardware_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing authentication']);
    exit;
}

$auth_stmt = db()->prepare('SELECT hardware_id FROM firewalls WHERE id = ?');
$auth_stmt->execute([$firewall_id]);
$auth_fw = $auth_stmt->fetch(PDO::FETCH_ASSOC);
if (!$auth_fw || (
    !empty($auth_fw['hardware_id']) && !hash_equals($auth_fw['hardware_id'], $hardware_id)
)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Authentication failed']);
    exit;
}

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
