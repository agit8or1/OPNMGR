<?php
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');
// Cache-busting headers to force fresh data
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Set timezone to America/New_York (EST/EDT)
date_default_timezone_set('America/New_York');

requireLogin();

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
    // tested_at is a TIMESTAMP returned in the server's named time zone
    // (America/New_York, see /etc/mysql/conf.d/timezone.cnf), so it is
    // already local. The previous CONVERT_TZ('+00:00','-05:00') shifted
    // already-local values back five hours, and by the wrong amount in DST.
    $stmt = db()->prepare("
        SELECT
            DATE_FORMAT(tested_at, '%Y-%m-%d %H:%i') as test_label,
            AVG(download_speed) as avg_download,
            AVG(upload_speed) as avg_upload,
            AVG(latency) as avg_ping,
            MAX(streams) as streams,
            MAX(test_server) as test_server,
            MAX(local_cpu_percent) as local_cpu,
            MAX(engine) as engine,
            MAX(result_url) as result_url,
            COUNT(*) as test_count
        FROM bandwidth_tests
        WHERE firewall_id = :firewall_id
        AND test_status = 'completed'
        AND tested_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
        GROUP BY DATE_FORMAT(tested_at, '%Y-%m-%d %H:%i')
        ORDER BY test_label ASC
    ");

    $stmt->execute([
        ':firewall_id' => $firewall_id,
        ':hours' => $hours
    ]);

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // NULL means the leg was not measured. It stays null (a gap in the
    // chart) and is excluded from the statistics: it used to become 0 Mbps,
    // which dragged every average and "low" down with failed tests.
    $labels = [];
    $download_data = [];
    $upload_data = [];
    $meta = [];
    foreach ($results as $row) {
        $labels[] = $row['test_label'];
        $download_data[] = $row['avg_download'] === null ? null : round((float)$row['avg_download'], 2);
        $upload_data[] = $row['avg_upload'] === null ? null : round((float)$row['avg_upload'], 2);
        // iperf3's CPU figure is summed across its threads (one per stream
        // since 3.16), so CPU-bound means near 100% per stream, not overall.
        $perStream = ($row['local_cpu'] !== null && $row['streams']) ? (int)round($row['local_cpu'] / $row['streams']) : null;
        $meta[] = ['streams' => $row['streams'] !== null ? (int)$row['streams'] : null,
                   'server' => $row['test_server'], 'local_cpu_per_stream' => $perStream,
                   'engine' => $row['engine'] ?: 'iperf3', 'result_url' => $row['result_url']];
    }

    $summ = function (array $vals): array {
        $vals = array_values(array_filter($vals, fn($v) => $v !== null));
        if (!$vals) {
            return ['avg' => null, 'peak' => null, 'low' => null, 'measured' => 0];
        }
        return ['avg' => round(array_sum($vals) / count($vals), 2), 'peak' => round(max($vals), 2),
                'low' => round(min($vals), 2), 'measured' => count($vals)];
    };
    $stats = ['download' => $summ($download_data), 'upload' => $summ($upload_data)];

    $fw = db()->prepare('SELECT wan_link_down_mbps, wan_link_up_mbps FROM firewalls WHERE id = ?');
    $fw->execute([$firewall_id]);
    $link = $fw->fetch(PDO::FETCH_ASSOC) ?: [];
    $failed = db()->prepare("SELECT COUNT(*) FROM bandwidth_tests WHERE firewall_id = ? AND test_status = 'failed' AND tested_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)");
    $failed->execute([$firewall_id, $hours]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'download' => $download_data,
        'upload' => $upload_data,
        'count' => count($labels),
        'stats' => $stats,
        'meta' => $meta,
        'failed_tests' => (int)$failed->fetchColumn(),
        'link' => ['down_mbps' => isset($link['wan_link_down_mbps']) ? (int)$link['wan_link_down_mbps'] ?: null : null,
                   'up_mbps' => isset($link['wan_link_up_mbps']) ? (int)$link['wan_link_up_mbps'] ?: null : null],
        'note' => 'Measured from the firewall to the listed iperf3 server: bounded by that server and path, not only the circuit.'
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
