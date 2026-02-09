<?php
/**
 * Bandwidth Graph API
 * Returns bandwidth statistics and utilization data
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';

requireLogin();

header('Content-Type: application/json');

$firewall_id = (int)($_GET['id'] ?? 0);
$time_range = $_GET['range'] ?? '24h'; // 24h, 7d, 30d

if (!$firewall_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing firewall_id']);
    exit;
}

// Verify user has access to this firewall
$stmt = $DB->prepare('SELECT id FROM firewalls WHERE id = ?');
$stmt->execute([$firewall_id]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Determine time range
$hours = 24;
switch ($time_range) {
    case '7d':
        $hours = 168;
        break;
    case '30d':
        $hours = 720;
        break;
    case '1h':
        $hours = 1;
        break;
}

try {
    // Get traffic stats for the time range
    $stmt = $DB->prepare('
        SELECT 
            recorded_at,
            bytes_in,
            bytes_out,
            packets_in,
            packets_out
        FROM firewall_traffic_stats
        WHERE firewall_id = ? AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ORDER BY recorded_at ASC
    ');
    $stmt->execute([$firewall_id, $hours]);
    $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate bandwidth and utilization
    $bandwidth_data = [];
    $previous_stat = null;
    $total_in = 0;
    $total_out = 0;
    $peak_in = 0;
    $peak_out = 0;
    
    foreach ($stats as $stat) {
        if ($previous_stat) {
            // Calculate bytes transferred since last check-in
            $bytes_in_delta = max(0, (int)$stat['bytes_in'] - (int)$previous_stat['bytes_in']);
            $bytes_out_delta = max(0, (int)$stat['bytes_out'] - (int)$previous_stat['bytes_out']);
            
            // Calculate time delta in seconds
            $time_prev = strtotime($previous_stat['recorded_at']);
            $time_curr = strtotime($stat['recorded_at']);
            $time_delta = max(1, $time_curr - $time_prev);
            
            // Calculate Mbps (bytes to megabits: divide by 125000)
            $mbps_in = ($bytes_in_delta / 125000) / ($time_delta / 60);
            $mbps_out = ($bytes_out_delta / 125000) / ($time_delta / 60);
            
            // Track stats
            $total_in += $bytes_in_delta;
            $total_out += $bytes_out_delta;
            $peak_in = max($peak_in, $mbps_in);
            $peak_out = max($peak_out, $mbps_out);
            
            $bandwidth_data[] = [
                'timestamp' => $stat['recorded_at'],
                'time' => strtotime($stat['recorded_at']) * 1000, // JS timestamp
                'mbps_in' => round($mbps_in, 2),
                'mbps_out' => round($mbps_out, 2),
                'total_mbps' => round($mbps_in + $mbps_out, 2)
            ];
        }
        $previous_stat = $stat;
    }
    
    // Calculate averages
    $count = count($bandwidth_data);
    $avg_in = 0;
    $avg_out = 0;
    
    if ($count > 0) {
        $sum_in = array_sum(array_column($bandwidth_data, 'mbps_in'));
        $sum_out = array_sum(array_column($bandwidth_data, 'mbps_out'));
        $avg_in = round($sum_in / $count, 2);
        $avg_out = round($sum_out / $count, 2);
    }
    
    // Get current interface info
    $stmt = $DB->prepare('
        SELECT wan_ip, lan_ip FROM firewalls WHERE id = ?
    ');
    $stmt->execute([$firewall_id]);
    $fw_info = $stmt->fetch();
    
    echo json_encode([
        'success' => true,
        'data' => [
            'bandwidth' => $bandwidth_data,
            'stats' => [
                'total_gb_in' => round($total_in / 1073741824, 2),
                'total_gb_out' => round($total_out / 1073741824, 2),
                'peak_mbps_in' => round($peak_in, 2),
                'peak_mbps_out' => round($peak_out, 2),
                'avg_mbps_in' => $avg_in,
                'avg_mbps_out' => $avg_out,
                'current_mbps_in' => isset($bandwidth_data[count($bandwidth_data) - 1]) ? $bandwidth_data[count($bandwidth_data) - 1]['mbps_in'] : 0,
                'current_mbps_out' => isset($bandwidth_data[count($bandwidth_data) - 1]) ? $bandwidth_data[count($bandwidth_data) - 1]['mbps_out'] : 0
            ]
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Bandwidth API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>
