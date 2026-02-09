<?php
require_once __DIR__ . '/../inc/auth.php';
requireLogin();
require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json');

// Get system information
$info = [];

try {
    // Get current version
    $stmt = $pdo->query("SELECT version FROM platform_versions WHERE status = 'released' ORDER BY created_at DESC LIMIT 1");
    $info['version'] = $stmt->fetchColumn() ?: '1.0.0';
    
    // Get uptime
    $uptime_seconds = shell_exec('cat /proc/uptime | cut -d" " -f1');
    if ($uptime_seconds) {
        $uptime_seconds = (int)$uptime_seconds;
        $days = floor($uptime_seconds / 86400);
        $hours = floor(($uptime_seconds % 86400) / 3600);
        $minutes = floor(($uptime_seconds % 3600) / 60);
        
        if ($days > 0) {
            $info['uptime'] = "{$days} days, {$hours} hours, {$minutes} minutes";
        } elseif ($hours > 0) {
            $info['uptime'] = "{$hours} hours, {$minutes} minutes";
        } else {
            $info['uptime'] = "{$minutes} minutes";
        }
    } else {
        $info['uptime'] = 'Unknown';
    }
    
    // Get firewall counts
    $stmt = $pdo->query("SELECT COUNT(*) FROM firewalls");
    $info['total_firewalls'] = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM firewalls f JOIN firewall_agents fa ON f.id = fa.firewall_id WHERE fa.last_checkin > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $info['active_agents'] = (int)$stmt->fetchColumn();
    
    // Get bug/todo counts
    $stmt = $pdo->query("SELECT COUNT(*) FROM bugs WHERE status NOT IN ('resolved', 'closed')");
    $info['open_bugs'] = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM todos WHERE status NOT IN ('completed', 'cancelled')");
    $info['pending_todos'] = (int)$stmt->fetchColumn();
    
    echo json_encode($info);
    
} catch (Exception $e) {
    error_log("system_info.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>