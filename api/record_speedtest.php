<?php
// API endpoint to record speedtest results from agent
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

$download_mbps = floatval($input['download_mbps'] ?? 0);
$upload_mbps = floatval($input['upload_mbps'] ?? 0);
$latency_ms = floatval($input['latency_ms'] ?? 0);

try {
    // Create table if it doesn't exist
    db()->exec("CREATE TABLE IF NOT EXISTS firewall_speedtest (
        id BIGINT PRIMARY KEY AUTO_INCREMENT,
        firewall_id INT NOT NULL,
        download_mbps FLOAT DEFAULT 0,
        upload_mbps FLOAT DEFAULT 0,
        latency_ms FLOAT DEFAULT 0,
        status VARCHAR(50) DEFAULT 'pending',
        tested_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_firewall_time (firewall_id, tested_at)
    )");

    // Insert speedtest result
    $stmt = db()->prepare("INSERT INTO firewall_speedtest (firewall_id, download_mbps, upload_mbps, latency_ms, status, tested_at) VALUES (?, ?, ?, ?, 'completed', NOW())");
    $stmt->execute([$firewall_id, $download_mbps, $upload_mbps, $latency_ms]);

    // Update pending speedtest record if one exists
    $stmt = db()->prepare("UPDATE firewall_speedtest SET download_mbps = ?, upload_mbps = ?, latency_ms = ?, status = 'completed', tested_at = NOW() WHERE firewall_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$download_mbps, $upload_mbps, $latency_ms, $firewall_id]);

    error_log("[SPEEDTEST] Firewall $firewall_id: DL ${download_mbps}Mbps, UL ${upload_mbps}Mbps, Latency ${latency_ms}ms");

    echo json_encode([
        'success' => true,
        'message' => 'Speedtest result recorded',
        'download_mbps' => $download_mbps,
        'upload_mbps' => $upload_mbps,
        'latency_ms' => $latency_ms
    ]);

} catch (Exception $e) {
    error_log("Speedtest recording error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error recording speedtest']);
}
?>
