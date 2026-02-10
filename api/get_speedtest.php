<?php
// API endpoint to get speedtest results
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');

$firewall_id = (int)($_GET['firewall_id'] ?? 0);
$days = (int)($_GET['days'] ?? 7);

if (!$firewall_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing firewall_id']);
    exit;
}

try {
    $stmt = db()->prepare("SELECT tested_at as test_date, download_speed as download_mbps, upload_speed as upload_mbps, latency as ping_ms FROM bandwidth_tests WHERE firewall_id = ? AND test_status = 'completed' AND tested_at >= DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY tested_at ASC");
    $stmt->execute([$firewall_id, $days]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [];
    foreach ($results as $row) {
        $data[] = ['timestamp' => $row['test_date'], 'download_mbps' => (float)$row['download_mbps'], 'upload_mbps' => (float)$row['upload_mbps'], 'latency_ms' => (float)$row['ping_ms']];
    }

    echo json_encode(['success' => true, 'data' => $data, 'count' => count($data)]);
} catch (Exception $e) {
    error_log("Speedtest API: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error']);
}
?>
