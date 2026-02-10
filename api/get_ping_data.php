<?php
/**
 * Get Ping Data API
 * Returns recent ping data from agents for display in charts
 * 
 * Query Parameters:
 * - firewall_id: ID of the firewall (required)
 * - days: Number of days to look back (default: 7)
 */
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');

try {
    $firewall_id = (int)($_GET['firewall_id'] ?? 0);
    $days = (int)($_GET['days'] ?? 7);
    
    if (!$firewall_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing firewall_id']);
        exit;
    }
    
    // Get ping data grouped by hour
    $query = '
        SELECT 
            DATE_FORMAT(created_at, "%Y-%m-%d %H:00") as time_slot,
            AVG(latency_ms) as avg_latency,
            MIN(latency_ms) as min_latency,
            MAX(latency_ms) as max_latency,
            COUNT(*) as ping_count
        FROM firewall_agent_pings
        WHERE firewall_id = ?
        AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY DATE_FORMAT(created_at, "%Y-%m-%d %H:00")
        ORDER BY created_at DESC
    ';
    
    $stmt = db()->prepare($query);
    $stmt->execute([$firewall_id, $days]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format data for charting
    $labels = [];
    $latencies = [];
    
    foreach (array_reverse($results) as $row) {
        $labels[] = $row['time_slot'];
        $latencies[] = round((float)$row['avg_latency'], 2);
    }
    
    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'latency' => $latencies,
        'data_points' => count($results),
        'firewall_id' => $firewall_id,
        'days' => $days
    ]);
    
} catch (Exception $e) {
    error_log("get_ping_data.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
?>
