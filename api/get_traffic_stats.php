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

// Note: Authentication handled by parent page to avoid AJAX session issues

$firewall_id = (int)($_GET['firewall_id'] ?? 0);
$days = (int)($_GET['days'] ?? 7);

if (!$firewall_id) {
    echo json_encode(['error' => 'Missing firewall_id']);
    exit;
}

// Validate days
if (!in_array($days, [1, 7, 14, 30])) {
    $days = 7;
}

try {
    // Get traffic data calculating the RATE (delta between consecutive samples)
    // Since bytes are cumulative counters, we calculate: (current_bytes - previous_bytes) / time_diff * 8 / 1000000
    $stmt = db()->prepare('
        WITH deltas AS (
            SELECT 
                recorded_at,
                bytes_in,
                bytes_out,
                packets_in,
                packets_out,
                LAG(bytes_in) OVER (ORDER BY recorded_at) as prev_bytes_in,
                LAG(bytes_out) OVER (ORDER BY recorded_at) as prev_bytes_out,
                LAG(recorded_at) OVER (ORDER BY recorded_at) as prev_time
            FROM firewall_traffic_stats
            WHERE firewall_id = ?
            AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ),
        rates AS (
            SELECT
                recorded_at,
                bytes_in,
                prev_bytes_in,
                CASE
                    -- Only calculate rate if: previous exists, current >= previous (no counter reset), and time > 0
                    WHEN prev_bytes_in IS NOT NULL
                        AND bytes_in >= prev_bytes_in
                        AND TIMESTAMPDIFF(SECOND, prev_time, recorded_at) > 0
                        AND (bytes_in - prev_bytes_in) < 100000000000  -- Sanity check: < 100GB delta
                        AND ((bytes_in - prev_bytes_in) * 8 / TIMESTAMPDIFF(SECOND, prev_time, recorded_at) / 1000000) < 15000  -- Rate < 15 Gbps
                    THEN (bytes_in - prev_bytes_in) * 8 / TIMESTAMPDIFF(SECOND, prev_time, recorded_at) / 1000000
                    ELSE 0
                END as mbps_in,
                CASE
                    WHEN prev_bytes_out IS NOT NULL
                        AND bytes_out >= prev_bytes_out
                        AND TIMESTAMPDIFF(SECOND, prev_time, recorded_at) > 0
                        AND (bytes_out - prev_bytes_out) < 100000000000  -- Sanity check: < 100GB delta
                        AND ((bytes_out - prev_bytes_out) * 8 / TIMESTAMPDIFF(SECOND, prev_time, recorded_at) / 1000000) < 15000  -- Rate < 15 Gbps
                    THEN (bytes_out - prev_bytes_out) * 8 / TIMESTAMPDIFF(SECOND, prev_time, recorded_at) / 1000000
                    ELSE 0
                END as mbps_out
            FROM deltas
            WHERE prev_time IS NOT NULL
        )
        SELECT
            CASE
                WHEN ? = 1 THEN
                    -- For 1-day view: group by 10-minute intervals to reduce data density
                    DATE_FORMAT(
                        DATE_ADD(
                            DATE_FORMAT(recorded_at, "%Y-%m-%d %H:00:00"),
                            INTERVAL FLOOR(MINUTE(recorded_at)/10)*10 MINUTE
                        ),
                        "%Y-%m-%d %H:%i"
                    )
                ELSE
                    -- For multi-day views: group by hour
                    DATE_FORMAT(recorded_at, "%Y-%m-%d %H:00")
            END as time_label,
            AVG(mbps_in) as mbps_in,
            AVG(mbps_out) as mbps_out
        FROM rates
        GROUP BY time_label
        ORDER BY time_label ASC
    ');
    $stmt->execute([$firewall_id, $days, $days]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $labels = [];
    $inbound = [];
    $outbound = [];
    
    // Check if we have any byte data, if not fall back to packet visualization
    // Process calculated rates - always use this path
    foreach ($data as $row) {
        $labels[] = $row['time_label'];
        $inbound[] = round($row['mbps_in'], 2);
        $outbound[] = round($row['mbps_out'], 2);
    }

    // Auto-scale to Gbps if max value exceeds 1000 Mbps
    $max_value = max(max($inbound ?: [0]), max($outbound ?: [0]));
    $unit = 'Mb/s';

    if ($max_value >= 1000) {
        // Convert to Gbps
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
