<?php
/**
 * Get SpeedTest Data API
 * Retrieves speedtest results for chart display
 */
require_once __DIR__ . '/../inc/bootstrap.php';

require_once __DIR__ . '/../inc/format_speed.php';

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
    $stmt = db()->prepare("
        SELECT
            DATE_FORMAT(tested_at, '%Y-%m-%d %H:%i') as time,
            download_speed as download_mbps,
            upload_speed as upload_mbps,
            latency as ping_ms,
            test_server as server_location,
            tested_at as test_date
        FROM bandwidth_tests
        WHERE firewall_id = ?
        AND test_status = 'completed'
        AND tested_at > DATE_SUB(NOW(), INTERVAL $interval)
        ORDER BY tested_at ASC
    ");

    $stmt->execute([$firewall_id]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add formatted speed strings to each record
    foreach ($records as &$record) {
        $record['download_formatted'] = format_speed($record['download_mbps']);
        $record['upload_formatted'] = format_speed($record['upload_mbps']);
    }
    unset($record);

    // Calculate statistics
    if (!empty($records)) {
        $downloads = array_map(fn($r) => (float)$r['download_mbps'], $records);
        $uploads = array_map(fn($r) => (float)$r['upload_mbps'], $records);
        $stats = [
            'download_min' => min($downloads),
            'download_max' => max($downloads),
            'download_avg' => array_sum($downloads) / count($downloads),
            'upload_min' => min($uploads),
            'upload_max' => max($uploads),
            'upload_avg' => array_sum($uploads) / count($uploads),
            'count' => count($records),
            'latest' => $records[count($records) - 1] ?? null,
            // Add formatted versions
            'download_min_formatted' => format_speed(min($downloads)),
            'download_max_formatted' => format_speed(max($downloads)),
            'download_avg_formatted' => format_speed(array_sum($downloads) / count($downloads)),
            'upload_min_formatted' => format_speed(min($uploads)),
            'upload_max_formatted' => format_speed(max($uploads)),
            'upload_avg_formatted' => format_speed(array_sum($uploads) / count($uploads))
        ];
    } else {
        $stats = [
            'download_min' => 0, 'download_max' => 0, 'download_avg' => 0,
            'upload_min' => 0, 'upload_max' => 0, 'upload_avg' => 0,
            'count' => 0, 'latest' => null
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $records,
        'stats' => $stats,
        'timeframe' => $timeframe
    ]);
} catch (Exception $e) {
    http_response_code(500);
    error_log("get_speedtest_data.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
?>
