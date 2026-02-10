<?php
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');
// Cache-busting headers to force fresh data
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Set timezone to America/New_York (EST/EDT)
date_default_timezone_set('America/New_York');

// Note: Authentication handled by parent page (firewall_details.php)
// This API is only called from authenticated sessions, so we skip auth check here
// to avoid session cookie issues with AJAX calls

$firewall_id = intval($_GET['firewall_id'] ?? 0);
$days = intval($_GET['days'] ?? 7);

if ($firewall_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid firewall_id']);
    exit;
}

try {
    // Get iperf3 bandwidth test results with timezone conversion
    $stmt = db()->prepare("
        SELECT
            CONVERT_TZ(tested_at, '+00:00', '-05:00') as tested_at_local,
            DATE_FORMAT(CONVERT_TZ(tested_at, '+00:00', '-05:00'), '%Y-%m-%d %H:%i') as test_label,
            AVG(download_speed) as avg_download,
            AVG(upload_speed) as avg_upload,
            AVG(latency) as avg_ping,
            COUNT(*) as test_count
        FROM bandwidth_tests
        WHERE firewall_id = :firewall_id
        AND test_status = 'completed'
        AND tested_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
        GROUP BY DATE_FORMAT(CONVERT_TZ(tested_at, '+00:00', '-05:00'), '%Y-%m-%d %H:%i')
        ORDER BY test_label ASC
    ");

    $stmt->execute([
        ':firewall_id' => $firewall_id,
        ':days' => $days
    ]);

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $labels = [];
    $download_data = [];
    $upload_data = [];

    foreach ($results as $row) {
        $labels[] = $row['test_label'];
        $download_data[] = round($row['avg_download'], 2);
        $upload_data[] = round($row['avg_upload'], 2);
    }

    // Calculate statistics
    $stats = [
        'download' => [
            'avg' => 0,
            'peak' => 0,
            'low' => 0
        ],
        'upload' => [
            'avg' => 0,
            'peak' => 0,
            'low' => 0
        ]
    ];

    if (!empty($download_data)) {
        $stats['download']['avg'] = round(array_sum($download_data) / count($download_data), 2);
        $stats['download']['peak'] = round(max($download_data), 2);
        $stats['download']['low'] = round(min($download_data), 2);
    }

    if (!empty($upload_data)) {
        $stats['upload']['avg'] = round(array_sum($upload_data) / count($upload_data), 2);
        $stats['upload']['peak'] = round(max($upload_data), 2);
        $stats['upload']['low'] = round(min($upload_data), 2);
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'download' => $download_data,
        'upload' => $upload_data,
        'count' => count($labels),
        'stats' => $stats
    ]);
    
} catch (PDOException $e) {
    error_log("get_speedtest_results.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
?>
