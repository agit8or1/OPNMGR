<?php
require_once __DIR__ . '/../inc/bootstrap.php';

requireLogin();

/**
 * API Endpoint: Get System Statistics
 * Returns CPU, memory, and disk usage data for charting
 */

set_time_limit(10);
ini_set('max_execution_time', 10);
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$firewall_id = (int)($_GET['firewall_id'] ?? 0);
$metric = trim($_GET['metric'] ?? 'cpu');

// Support both hours (new) and days (legacy) parameters
$hours = (int)($_GET['hours'] ?? 0);
if (!$hours) {
    $days = (int)($_GET['days'] ?? 1);
    $hours = $days * 24;
}

if (!$firewall_id) {
    echo json_encode(['error' => 'Missing firewall_id']);
    exit;
}

$hours = max(1, min($hours, 720));

if (!in_array($metric, ['cpu', 'memory', 'disk'])) {
    $metric = 'cpu';
}

try {
    db()->setAttribute(PDO::ATTR_TIMEOUT, 5);
    db()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Determine aggregation interval based on hours
    if ($hours <= 4) {
        $date_format = '%Y-%m-%d %H:%i';     // per-minute
        $max_points = 240;
    } elseif ($hours <= 24) {
        $date_format = '%Y-%m-%d %H:%i';     // will group by 10-min below
        $max_points = 144;
    } else {
        $date_format = '%Y-%m-%d %H:00';     // hourly
        $max_points = 720;
    }

    // For 4-24h range, use 10-minute grouping
    if ($hours > 4 && $hours <= 24) {
        $time_label_expr = "DATE_FORMAT(DATE_ADD(DATE_FORMAT(recorded_at, '%Y-%m-%d %H:00:00'), INTERVAL FLOOR(MINUTE(recorded_at)/10)*10 MINUTE), '%Y-%m-%d %H:%i')";
    } else {
        $time_label_expr = "DATE_FORMAT(recorded_at, '$date_format')";
    }

    if ($metric == 'cpu') {
        $stmt = db()->prepare("
            SELECT * FROM (
                SELECT
                    $time_label_expr as time_label,
                    AVG(cpu_load_1min) as load_1min,
                    AVG(cpu_load_5min) as load_5min,
                    AVG(cpu_load_15min) as load_15min
                FROM firewall_system_stats
                WHERE firewall_id = ?
                AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY time_label
                HAVING COUNT(*) > 0
                ORDER BY time_label DESC
                LIMIT ?
            ) AS recent_data
            ORDER BY time_label ASC
        ");
        $stmt->execute([$firewall_id, $hours, $max_points]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $labels = [];
        $load1 = [];
        $load5 = [];
        $load15 = [];

        foreach ($data as $row) {
            $labels[] = $row['time_label'];
            $load1[] = round($row['load_1min'], 2);
            $load5[] = round($row['load_5min'], 2);
            $load15[] = round($row['load_15min'], 2);
        }

        $sorted = $load1;
        sort($sorted);
        $p95 = !empty($sorted) ? $sorted[(int)floor(count($sorted) * 0.95)] : 0;

        echo json_encode([
            'success' => true,
            'labels' => $labels,
            'load_1min' => $load1,
            'load_5min' => $load5,
            'load_15min' => $load15,
            'unit' => 'load average',
            'p95' => round($p95, 2)
        ]);

    } elseif ($metric == 'memory') {
        $stmt = db()->prepare("
            SELECT * FROM (
                SELECT
                    $time_label_expr as time_label,
                    AVG(memory_percent) as mem_percent,
                    AVG(memory_used_mb) as mem_used,
                    AVG(memory_total_mb) as mem_total
                FROM firewall_system_stats
                WHERE firewall_id = ?
                AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY time_label
                HAVING COUNT(*) > 0
                ORDER BY time_label DESC
                LIMIT ?
            ) AS recent_data
            ORDER BY time_label ASC
        ");
        $stmt->execute([$firewall_id, $hours, $max_points]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $labels = [];
        $percentages = [];

        foreach ($data as $row) {
            $labels[] = $row['time_label'];
            $percentages[] = round($row['mem_percent'], 2);
        }

        echo json_encode([
            'success' => true,
            'labels' => $labels,
            'usage' => $percentages,
            'unit' => '%'
        ]);

    } elseif ($metric == 'disk') {
        $stmt = db()->prepare("
            SELECT * FROM (
                SELECT
                    $time_label_expr as time_label,
                    AVG(disk_percent) as disk_percent,
                    AVG(disk_used_gb) as disk_used,
                    AVG(disk_total_gb) as disk_total
                FROM firewall_system_stats
                WHERE firewall_id = ?
                AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY time_label
                HAVING COUNT(*) > 0
                ORDER BY time_label DESC
                LIMIT ?
            ) AS recent_data
            ORDER BY time_label ASC
        ");
        $stmt->execute([$firewall_id, $hours, $max_points]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $labels = [];
        $percentages = [];

        foreach ($data as $row) {
            $labels[] = $row['time_label'];
            $percentages[] = round($row['disk_percent'], 2);
        }

        echo json_encode([
            'success' => true,
            'labels' => $labels,
            'usage' => $percentages,
            'unit' => '%'
        ]);
    }

} catch (PDOException $e) {
    error_log("System stats API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error fetching system stats'
    ]);
} catch (Exception $e) {
    error_log("System stats API exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch system stats'
    ]);
}
?>
