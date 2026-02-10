<?php
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');

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
    $stmt = db()->prepare("
        SELECT 
            DATE_FORMAT(measured_at, '%Y-%m-%d %H:%i') as time_label,
            AVG(latency_ms) as avg_latency,
            MIN(latency_ms) as min_latency,
            MAX(latency_ms) as max_latency,
            COUNT(*) as count
        FROM firewall_latency
        WHERE firewall_id = :firewall_id 
        AND measured_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
        GROUP BY DATE_FORMAT(measured_at, '%Y-%m-%d %H:%i')
        ORDER BY time_label ASC
    ");
    
    $stmt->execute([
        ':firewall_id' => $firewall_id,
        ':days' => $days
    ]);
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $labels = [];
    $latency_data = [];
    
    foreach ($results as $row) {
        $labels[] = $row['time_label'];
        $latency_data[] = round($row['avg_latency'], 2);
    }
    
    // If no data, return empty arrays
    if (empty($labels)) {
        $labels = [];
        $latency_data = [];
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'latency' => $latency_data,
        'count' => count($latency_data)
    ]);
    
} catch (PDOException $e) {
    error_log("get_latency_stats.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
?>
