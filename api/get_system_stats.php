<?php
require_once __DIR__ . '/../inc/bootstrap.php';

requireLogin();

/**
 * API Endpoint: Get System Statistics
 * Returns CPU, memory, and disk usage data for charting
 */

// Set execution timeout to prevent hanging
set_time_limit(10);
ini_set('max_execution_time', 10);
header('Content-Type: application/json');
// Cache-busting headers to force fresh data
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Note: Authentication handled by parent page to avoid AJAX session issues

$firewall_id = (int)($_GET['firewall_id'] ?? 0);
$days = (int)($_GET['days'] ?? 7);
$metric = trim($_GET['metric'] ?? 'cpu'); // cpu, memory, or disk

if (!$firewall_id) {
    echo json_encode(['error' => 'Missing firewall_id']);
    exit;
}

// Validate days
if (!in_array($days, [1, 7, 14, 30])) {
    $days = 7;
}

// Validate metric
if (!in_array($metric, ['cpu', 'memory', 'disk'])) {
    $metric = 'cpu';
}

try {
    // Set database timeout
    db()->setAttribute(PDO::ATTR_TIMEOUT, 5);
    db()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Determine aggregation interval based on days
    $interval = $days == 1 ? 10 : 60; // 10-minute intervals for 1 day, hourly for others
    $date_format = $days == 1 ? '%Y-%m-%d %H:%i' : '%Y-%m-%d %H:00';

    // LIMIT data points to prevent browser hanging
    $max_points = 144; // Max 144 points (10-min intervals for 24 hours)

    if ($metric == 'cpu') {
        // CPU load averages - Get MOST RECENT data points, not oldest
        $stmt = db()->prepare("
            SELECT * FROM (
                SELECT
                    DATE_FORMAT(recorded_at, ?) as time_label,
                    AVG(cpu_load_1min) as load_1min,
                    AVG(cpu_load_5min) as load_5min,
                    AVG(cpu_load_15min) as load_15min
                FROM firewall_system_stats
                WHERE firewall_id = ?
                AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY time_label
                HAVING COUNT(*) > 0
                ORDER BY time_label DESC
                LIMIT ?
            ) AS recent_data
            ORDER BY time_label ASC
        ");
        $stmt->execute([$date_format, $firewall_id, $days, $max_points]);
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
        
        echo json_encode([
            'success' => true,
            'labels' => $labels,
            'load_1min' => $load1,
            'load_5min' => $load5,
            'load_15min' => $load15,
            'unit' => 'load average'
        ]);
        
    } elseif ($metric == 'memory') {
        // Memory usage percentage - Get MOST RECENT data points, not oldest
        $stmt = db()->prepare("
            SELECT * FROM (
                SELECT
                    DATE_FORMAT(recorded_at, ?) as time_label,
                    AVG(memory_percent) as mem_percent,
                    AVG(memory_used_mb) as mem_used,
                    AVG(memory_total_mb) as mem_total
                FROM firewall_system_stats
                WHERE firewall_id = ?
                AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY time_label
                HAVING COUNT(*) > 0
                ORDER BY time_label DESC
                LIMIT ?
            ) AS recent_data
            ORDER BY time_label ASC
        ");
        $stmt->execute([$date_format, $firewall_id, $days, $max_points]);
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
        // Disk usage percentage - Get MOST RECENT data points, not oldest
        $stmt = db()->prepare("
            SELECT * FROM (
                SELECT
                    DATE_FORMAT(recorded_at, ?) as time_label,
                    AVG(disk_percent) as disk_percent,
                    AVG(disk_used_gb) as disk_used,
                    AVG(disk_total_gb) as disk_total
                FROM firewall_system_stats
                WHERE firewall_id = ?
                AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY time_label
                HAVING COUNT(*) > 0
                ORDER BY time_label DESC
                LIMIT ?
            ) AS recent_data
            ORDER BY time_label ASC
        ");
        $stmt->execute([$date_format, $firewall_id, $days, $max_points]);
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
