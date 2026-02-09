<?php
/**
 * Get Latency Data API
 * Retrieves latency measurements for chart display
 */

require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json');

$firewall_id = (int)($_GET['firewall_id'] ?? 0);
$timeframe = $_GET['timeframe'] ?? '24h'; // 24h, 7d, 30d

if (!$firewall_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing firewall_id']);
    exit;
}

// Determine date range
$interval = '24 HOUR';
switch ($timeframe) {
    case '7d':
        $interval = '7 DAY';
        break;
    case '30d':
        $interval = '30 DAY';
        break;
    case '24h':
    default:
        $interval = '24 HOUR';
}

try {
    $stmt = $DB->prepare("
        SELECT 
            DATE_FORMAT(measured_at, '%Y-%m-%d %H:%i') as time,
            latency_ms,
            measured_at
        FROM firewall_latency
        WHERE firewall_id = ?
        AND measured_at > DATE_SUB(NOW(), INTERVAL $interval)
        ORDER BY measured_at ASC
    ");
    
    $stmt->execute([$firewall_id]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate statistics
    if (!empty($records)) {
        $latencies = array_map(fn($r) => $r['latency_ms'], $records);
        $min = min($latencies);
        $max = max($latencies);
        $avg = array_sum($latencies) / count($latencies);
    } else {
        $min = $max = $avg = 0;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $records,
        'stats' => [
            'min' => $min,
            'max' => $max,
            'avg' => $avg,
            'count' => count($records)
        ],
        'timeframe' => $timeframe
    ]);
} catch (Exception $e) {
    http_response_code(500);
    error_log("get_latency_data.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
?>
