#!/usr/bin/env php
<?php
/**
 * Cleanup Abandoned Proxy Sessions
 * Runs via cron every 5 minutes to timeout old requests
 */

require_once __DIR__ . '/inc/bootstrap_agent.php';

$timeout_minutes = 10;

// Find stale requests (pending/processing for more than timeout_minutes)
$stmt = db()->prepare('
    SELECT id, firewall_id, tunnel_port, status, created_at
    FROM request_queue
    WHERE status IN ("pending", "processing")
    AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
');
$stmt->execute([$timeout_minutes]);
$stale_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cleaned = 0;

foreach ($stale_requests as $request) {
    // Update status to timeout
    db()->prepare('UPDATE request_queue SET status = ?, updated_at = NOW(), completed_at = NOW() WHERE id = ?')
       ->execute(['timeout', $request['id']]);
    
    // Log the timeout
    $details = json_encode([
        'request_id' => $request['id'],
        'tunnel_port' => $request['tunnel_port'],
        'original_status' => $request['status'],
        'age_minutes' => round((time() - strtotime($request['created_at'])) / 60, 1)
    ]);
    
    db()->prepare('INSERT INTO system_logs (category, message, details, firewall_id, created_at) VALUES (?, ?, ?, ?, NOW())')
       ->execute(['proxy', "Cleaned up stale proxy request (timeout)", $details, $request['firewall_id']]);
    
    $cleaned++;
}

if ($cleaned > 0) {
    echo "Cleaned up $cleaned stale proxy request(s)\n";
} else {
    echo "No stale requests found\n";
}
