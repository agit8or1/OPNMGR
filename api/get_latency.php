<?php
// API endpoint to get latency data for a firewall
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');

$firewall_id = (int)($_GET['firewall_id'] ?? 0);
$days = (int)($_GET['days'] ?? 1);

if (!$firewall_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing firewall_id']);
    exit;
}

try {
    // Get latency data for the specified period
    $stmt = db()->prepare("
        SELECT 
            measured_at,
            latency_ms
        FROM firewall_latency
        WHERE firewall_id = ?
        AND measured_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ORDER BY measured_at ASC
    ");
    $stmt->execute([$firewall_id, $days]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [];
    foreach ($results as $row) {
        $data[] = [
            'timestamp' => $row['measured_at'],
            'latency_ms' => (float)$row['latency_ms']
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $data,
        'count' => count($data)
    ]);

} catch (Exception $e) {
    error_log("Latency API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error fetching latency data']);
}
?>
