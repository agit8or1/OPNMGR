<?php
require_once __DIR__ . '/../inc/bootstrap.php';

requireLogin();

/**
 * Firewall Health Check API
 * Monitors firewall checkin status and auto-recovery
 * Called by monitoring system to check if firewalls are responsive
 */
require_once __DIR__ . '/../inc/logging.php';

header('Content-Type: application/json');

try {
    $results = [];
    $alerts = [];
    
    // Get all active firewalls
    $stmt = db()->prepare("SELECT id, hostname, ip_address, status, last_checkin, checkin_interval FROM firewalls WHERE status != 'archived'");
    $stmt->execute();
    $firewalls = $stmt->fetchAll();
    
    foreach ($firewalls as $fw) {
        $fw_id = $fw['id'];
        $hostname = $fw['hostname'];
        $ip = $fw['ip_address'];
        
        // Calculate seconds since last checkin
        $last_checkin = strtotime($fw['last_checkin']);
        $now = time();
        $seconds_since_checkin = $now - $last_checkin;
        $expected_interval = $fw['checkin_interval'] ?? 120;
        
        // Alert threshold: 5x the expected interval or 30 minutes, whichever is greater
        $alert_threshold = max($expected_interval * 5, 1800);
        
        $fw_result = [
            'firewall_id' => $fw_id,
            'hostname' => $hostname,
            'ip_address' => $ip,
            'status' => $fw['status'],
            'last_checkin' => $fw['last_checkin'],
            'seconds_since_checkin' => $seconds_since_checkin,
            'expected_interval' => $expected_interval,
            'alert_threshold' => $alert_threshold,
            'healthy' => $seconds_since_checkin <= $alert_threshold
        ];
        
        // If firewall is overdue and still marked as 'online', update to 'offline'
        if ($seconds_since_checkin > $alert_threshold && $fw['status'] === 'online') {
            $update = db()->prepare("UPDATE firewalls SET status = 'offline' WHERE id = ?");
            $update->execute([$fw_id]);
            $fw_result['status_updated'] = 'online -> offline';
            
            // Queue a restart command
            $insert = db()->prepare("INSERT INTO firewall_commands (firewall_id, command, description, status) VALUES (?, ?, ?, 'pending')");
            $insert->execute([
                $fw_id,
                'systemctl restart opnsense-agent || service opnsense-agent restart || /etc/init.d/opnsense-agent restart',
                'Auto-recovery: restart agent service after prolonged checkin failure'
            ]);
            
            $alerts[] = [
                'level' => 'critical',
                'firewall' => $hostname,
                'message' => "No checkin for " . round($seconds_since_checkin / 3600, 1) . " hours. Status updated to offline. Restart command queued."
            ];
            
            error_log("[HEALTH_CHECK] Firewall $hostname (ID: $fw_id) offline for " . round($seconds_since_checkin / 3600, 1) . " hours. Auto-recovery initiated.");
        } elseif ($seconds_since_checkin > $alert_threshold && $fw['status'] === 'offline') {
            $alerts[] = [
                'level' => 'warning',
                'firewall' => $hostname,
                'message' => "Still offline - no checkin for " . round($seconds_since_checkin / 3600, 1) . " hours. Restart command pending."
            ];
        }
        
        $results[] = $fw_result;
    }
    
    echo json_encode([
        'success' => true,
        'checked_at' => date('Y-m-d H:i:s'),
        'firewalls' => $results,
        'alerts' => $alerts,
        'total_firewalls' => count($results),
        'offline_count' => count(array_filter($results, function($r) { return !$r['healthy']; })),
        'alert_count' => count($alerts)
    ]);
    
} catch (Exception $e) {
    error_log("[HEALTH_CHECK] Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Health check failed'
    ]);
}
?>
