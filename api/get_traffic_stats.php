<?php
/**
 * API Endpoint: Get Traffic Statistics
 * Returns traffic data for charting
 */
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');
// Cache-busting headers to force fresh data
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$firewall_id = (int)($_GET['firewall_id'] ?? 0);

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

// Clamp to reasonable range
$hours = max(1, min($hours, 720));

try {
    // Determine aggregation based on time range
    if ($hours <= 4) {
        // 1-4 hours: per-minute grouping
        $date_format = '%Y-%m-%d %H:%i';
    } elseif ($hours <= 24) {
        // 12-24 hours: 10-minute intervals
        $group_expr = 'DATE_FORMAT(DATE_ADD(DATE_FORMAT(recorded_at, "%Y-%m-%d %H:00:00"), INTERVAL FLOOR(MINUTE(recorded_at)/10)*10 MINUTE), "%Y-%m-%d %H:%i")';
        $date_format = null; // use custom expression
    } else {
        // 1 week+: hourly
        $date_format = '%Y-%m-%d %H:00';
    }

    // Build the time_label expression
    if ($hours <= 4) {
        $time_label_expr = "DATE_FORMAT(recorded_at, '$date_format')";
    } elseif ($hours <= 24) {
        $time_label_expr = $group_expr;
    } else {
        $time_label_expr = "DATE_FORMAT(recorded_at, '$date_format')";
    }

    $stmt = db()->prepare("
        WITH deltas AS (
            SELECT
                recorded_at,
                bytes_in,
                bytes_out,
                LAG(bytes_in) OVER (ORDER BY recorded_at) as prev_bytes_in,
                LAG(bytes_out) OVER (ORDER BY recorded_at) as prev_bytes_out,
                LAG(recorded_at) OVER (ORDER BY recorded_at) as prev_time
            FROM firewall_traffic_stats
            WHERE firewall_id = ?
            AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ),
        rates AS (
            SELECT
                recorded_at,
                CASE
                    WHEN prev_bytes_in IS NOT NULL
                        AND bytes_in >= prev_bytes_in
                        AND TIMESTAMPDIFF(SECOND, prev_time, recorded_at) > 0
                        AND (bytes_in - prev_bytes_in) < 100000000000
                        AND ((bytes_in - prev_bytes_in) * 8 / TIMESTAMPDIFF(SECOND, prev_time, recorded_at) / 1000000) < 15000
                    THEN (bytes_in - prev_bytes_in) * 8 / TIMESTAMPDIFF(SECOND, prev_time, recorded_at) / 1000000
                    ELSE 0
                END as mbps_in,
                CASE
                    WHEN prev_bytes_out IS NOT NULL
                        AND bytes_out >= prev_bytes_out
                        AND TIMESTAMPDIFF(SECOND, prev_time, recorded_at) > 0
                        AND (bytes_out - prev_bytes_out) < 100000000000
                        AND ((bytes_out - prev_bytes_out) * 8 / TIMESTAMPDIFF(SECOND, prev_time, recorded_at) / 1000000) < 15000
                    THEN (bytes_out - prev_bytes_out) * 8 / TIMESTAMPDIFF(SECOND, prev_time, recorded_at) / 1000000
                    ELSE 0
                END as mbps_out
            FROM deltas
            WHERE prev_time IS NOT NULL
        )
        SELECT
            $time_label_expr as time_label,
            AVG(mbps_in) as mbps_in,
            AVG(mbps_out) as mbps_out
        FROM rates
        GROUP BY time_label
        ORDER BY time_label ASC
    ");
    $stmt->execute([$firewall_id, $hours]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $labels = [];
    $inbound = [];
    $outbound = [];

    foreach ($data as $row) {
        $labels[] = $row['time_label'];
        $inbound[] = round($row['mbps_in'], 2);
        $outbound[] = round($row['mbps_out'], 2);
    }

    // Auto-scale to Gbps if max value exceeds 1000 Mbps
    $max_value = max(max($inbound ?: [0]), max($outbound ?: [0]));
    $unit = 'Mb/s';

    if ($max_value >= 1000) {
        $inbound = array_map(function($val) { return round($val / 1000, 2); }, $inbound);
        $outbound = array_map(function($val) { return round($val / 1000, 2); }, $outbound);
        $unit = 'Gb/s';
    }

    // Calculate 95th percentile for Y-axis suggested max
    $all_values = array_merge($inbound, $outbound);
    $p95 = 0;
    if (!empty($all_values)) {
        $sorted = array_filter($all_values, fn($v) => $v > 0);
        sort($sorted);
        if (!empty($sorted)) {
            $p95_index = (int)floor(count($sorted) * 0.95);
            $p95 = $sorted[min($p95_index, count($sorted) - 1)];
        }
    }

    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'inbound' => $inbound,
        'outbound' => $outbound,
        'unit' => $unit,
        'p95' => round($p95, 2)
    ]);

} catch (Exception $e) {
    error_log("get_traffic_stats.php error: " . $e->getMessage());
    echo json_encode([
        'error' => 'Failed to fetch traffic data',
        'message' => 'Internal server error'
    ]);
}
