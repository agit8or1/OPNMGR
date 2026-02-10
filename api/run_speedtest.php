<?php
// API endpoint to run speedtest on a firewall
// Triggers speedtest via firewall command queue
require_once __DIR__ . '/../inc/bootstrap.php';

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

if (!$firewall_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing firewall_id']);
    exit;
}

try {
    // Verify firewall exists
    $stmt = db()->prepare("SELECT id, hostname FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$firewall) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Firewall not found']);
        exit;
    }

    // Create firewall_speedtest table if it doesn't exist
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

    // Create a new speedtest job
    $stmt = db()->prepare("INSERT INTO firewall_speedtest (firewall_id, status) VALUES (?, 'pending')");
    $stmt->execute([$firewall_id]);
    $speedtest_id = db()->lastInsertId();

    // Queue command for the agent to run speedtest
    $command = "speedtest --simple";  // Simple format: download,upload,ping
    $stmt = db()->prepare("INSERT INTO firewall_commands (firewall_id, command, command_type, status) VALUES (?, ?, 'speedtest', 'pending')");
    $stmt->execute([$firewall_id, $command]);

    echo json_encode([
        'success' => true,
        'message' => 'Speedtest initiated',
        'speedtest_id' => $speedtest_id,
        'firewall_id' => $firewall_id
    ]);

} catch (Exception $e) {
    error_log("Speedtest error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error starting speedtest']);
}
?>
