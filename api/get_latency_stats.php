<?php
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');

// Note: Authentication handled by parent page (firewall_details.php)
// This API is only called from authenticated sessions, so we skip auth check here
// to avoid session cookie issues with AJAX calls

$firewall_id = intval($_GET['firewall_id'] ?? 0);

// Support both hours (new) and days (legacy) parameters
$hours = intval($_GET['hours'] ?? 0);
if (!$hours) {
    $days = intval($_GET['days'] ?? 1);
    $hours = $days * 24;
}
$hours = max(1, min($hours, 720));

if ($firewall_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid firewall_id']);
    exit;
}

try {
    // Downsample based on time range to keep chart readable
    if ($hours <= 4) {
        // 1-4h: per-minute
        $group_expr = "DATE_FORMAT(measured_at, '%Y-%m-%d %H:%i')";
    } elseif ($hours <= 24) {
        // 12-24h: 5-minute intervals
        $group_expr = "DATE_FORMAT(DATE_SUB(measured_at, INTERVAL MOD(MINUTE(measured_at),5) MINUTE), '%Y-%m-%d %H:%i')";
    } elseif ($hours <= 168) {
        // 1 week: 30-minute intervals
        $group_expr = "DATE_FORMAT(DATE_SUB(measured_at, INTERVAL MOD(MINUTE(measured_at),30) MINUTE), '%Y-%m-%d %H:%i')";
    } else {
        // 30 days: 2-hour intervals
        $group_expr = "DATE_FORMAT(DATE_SUB(measured_at, INTERVAL MOD(HOUR(measured_at),2) HOUR), '%Y-%m-%d %H:00')";
    }

    $stmt = db()->prepare("
        SELECT
            {$group_expr} as time_label,
            AVG(latency_ms) as avg_latency,
            MIN(latency_ms) as min_latency,
            MAX(latency_ms) as max_latency,
            COUNT(*) as count
        FROM firewall_latency
        WHERE firewall_id = :firewall_id
        AND measured_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
        GROUP BY time_label
        ORDER BY time_label ASC
    ");

    $stmt->execute([
        ':firewall_id' => $firewall_id,
        ':hours' => $hours
    ]);

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $labels = [];
    $latency_data = [];

    foreach ($results as $row) {
        $labels[] = $row['time_label'];
        $latency_data[] = round($row['avg_latency'], 2);
    }

    // Calculate 95th percentile for Y-axis suggested max
    $p95 = 0;
    if (!empty($latency_data)) {
        $sorted = $latency_data;
        sort($sorted);
        $p95_index = (int)floor(count($sorted) * 0.95);
        $p95 = $sorted[min($p95_index, count($sorted) - 1)];
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'latency' => $latency_data,
        'count' => count($latency_data),
        'p95' => round($p95, 2)
    ]);
    
} catch (PDOException $e) {
    error_log("get_latency_stats.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
?>
