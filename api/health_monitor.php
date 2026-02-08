<?php
/**
 * Health Monitoring API
 * Provides comprehensive system health and status information
 */

require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_system_health':
        getSystemHealth();
        break;
    case 'get_firewall_status':
        getFirewallStatus();
        break;
    case 'get_service_status':
        getServiceStatus();
        break;
    case 'get_performance_metrics':
        getPerformanceMetrics();
        break;
    case 'get_recent_alerts':
        getRecentAlerts();
        break;
    case 'get_system_info':
        getSystemInfo();
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}

function getSystemHealth() {
    global $DB;
    
    try {
        // Get overall system status
        $health = [];
        
        // Database health
        $db_status = 'healthy';
        try {
            $DB->query("SELECT 1");
        } catch (Exception $e) {
            $db_status = 'error';
        }
        
        // Queue health
        $queue_stmt = $DB->prepare("
            SELECT 
                COUNT(*) as total_commands,
                SUM(CASE WHEN status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 1 ELSE 0 END) as stuck_commands,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_commands
            FROM firewall_commands
        ");
        $queue_stmt->execute();
        $queue_stats = $queue_stmt->fetch(PDO::FETCH_ASSOC);
        
        $queue_health = 'healthy';
        if ($queue_stats['stuck_commands'] > 10) {
            $queue_health = 'critical';
        } elseif ($queue_stats['stuck_commands'] > 5 || $queue_stats['failed_commands'] > 20) {
            $queue_health = 'warning';
        }
        
        // Request queue health
        $req_stmt = $DB->prepare("
            SELECT 
                COUNT(*) as total_requests,
                SUM(CASE WHEN status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 1 ELSE 0 END) as stuck_requests,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_requests
            FROM request_queue
        ");
        $req_stmt->execute();
        $req_stats = $req_stmt->fetch(PDO::FETCH_ASSOC);
        
        $request_health = 'healthy';
        if ($req_stats['stuck_requests'] > 20) {
            $request_health = 'critical';
        } elseif ($req_stats['stuck_requests'] > 10 || $req_stats['failed_requests'] > 50) {
            $request_health = 'warning';
        }
        
        // Firewall connectivity health
        $fw_stmt = $DB->prepare("
            SELECT 
                COUNT(*) as total_firewalls,
                SUM(CASE WHEN last_checkin > DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 1 ELSE 0 END) as online_firewalls,
                SUM(CASE WHEN last_checkin < DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) as offline_firewalls
            FROM firewalls
        ");
        $fw_stmt->execute();
        $fw_stats = $fw_stmt->fetch(PDO::FETCH_ASSOC);
        
        $firewall_health = 'healthy';
        if ($fw_stats['offline_firewalls'] > 0) {
            $firewall_health = 'warning';
        }
        if ($fw_stats['offline_firewalls'] >= $fw_stats['total_firewalls'] / 2) {
            $firewall_health = 'critical';
        }
        
        // Overall system health
        $overall_health = 'healthy';
        if ($db_status === 'error' || $queue_health === 'critical' || $request_health === 'critical' || $firewall_health === 'critical') {
            $overall_health = 'critical';
        } elseif ($queue_health === 'warning' || $request_health === 'warning' || $firewall_health === 'warning') {
            $overall_health = 'warning';
        }
        
        echo json_encode([
            'success' => true,
            'timestamp' => date('Y-m-d H:i:s'),
            'overall_health' => $overall_health,
            'components' => [
                'database' => ['status' => $db_status, 'message' => 'Database connectivity'],
                'command_queue' => [
                    'status' => $queue_health,
                    'message' => "Commands: {$queue_stats['total_commands']} total, {$queue_stats['stuck_commands']} stuck, {$queue_stats['failed_commands']} failed"
                ],
                'request_queue' => [
                    'status' => $request_health,
                    'message' => "Requests: {$req_stats['total_requests']} total, {$req_stats['stuck_requests']} stuck, {$req_stats['failed_requests']} failed"
                ],
                'firewalls' => [
                    'status' => $firewall_health,
                    'message' => "Firewalls: {$fw_stats['online_firewalls']}/{$fw_stats['total_firewalls']} online"
                ]
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get system health: ' . $e->getMessage()]);
    }
}

function getFirewallStatus() {
    global $DB;
    
    try {
        $stmt = $DB->prepare("
            SELECT 
                id, hostname as name, ip_address, customer_name as customer_id,
                agent_version, opnsense_version, last_checkin,
                wan_ip, lan_ip, uptime, updates_available,
                CASE 
                    WHEN last_checkin > DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 'online'
                    WHEN last_checkin > DATE_SUB(NOW(), INTERVAL 30 MINUTE) THEN 'warning'
                    ELSE 'offline'
                END as status,
                TIMESTAMPDIFF(MINUTE, last_checkin, NOW()) as minutes_since_checkin
            FROM firewalls 
            ORDER BY last_checkin DESC
        ");
        $stmt->execute();
        $firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'firewalls' => $firewalls
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get firewall status: ' . $e->getMessage()]);
    }
}

function getServiceStatus() {
    global $DB;
    
    try {
        $services = [];
        
        // Check nginx
        $nginx_status = (shell_exec('systemctl is-active nginx') === "active\n") ? 'running' : 'stopped';
        $services['nginx'] = ['status' => $nginx_status, 'description' => 'Web Server'];
        
        // Check PHP-FPM
        $php_status = (shell_exec('systemctl is-active php8.3-fpm') === "active\n") ? 'running' : 'stopped';
        $services['php-fpm'] = ['status' => $php_status, 'description' => 'PHP FastCGI Process Manager'];
        
        // Check MySQL
        $mysql_status = (shell_exec('systemctl is-active mysql') === "active\n") ? 'running' : 'stopped';
        $services['mysql'] = ['status' => $mysql_status, 'description' => 'Database Server'];
        
        // Check cron
        $cron_status = (shell_exec('systemctl is-active cron') === "active\n") ? 'running' : 'stopped';
        $services['cron'] = ['status' => $cron_status, 'description' => 'Task Scheduler'];
        
        // Check disk space
        $disk_usage = (int)shell_exec("df / | awk 'NR==2{print $5}' | sed 's/%//'");
        $disk_status = ($disk_usage > 90) ? 'critical' : (($disk_usage > 80) ? 'warning' : 'healthy');
        $services['disk'] = ['status' => $disk_status, 'description' => "Disk Usage: {$disk_usage}%"];
        
        // Check memory
        $mem_info = shell_exec("free | awk 'NR==2{printf \"%.1f\", $3*100/$2}'");
        $mem_usage = (float)$mem_info;
        $mem_status = ($mem_usage > 90) ? 'critical' : (($mem_usage > 80) ? 'warning' : 'healthy');
        $services['memory'] = ['status' => $mem_status, 'description' => "Memory Usage: {$mem_usage}%"];
        
        // Check CPU usage
        $cpu_usage = (float)shell_exec("top -bn1 | grep 'Cpu(s)' | awk '{print $2}' | sed 's/%us,//'");
        $cpu_status = ($cpu_usage > 90) ? 'critical' : (($cpu_usage > 80) ? 'warning' : 'healthy');
        $services['cpu'] = ['status' => $cpu_status, 'description' => "CPU Usage: {$cpu_usage}%"];
        
        echo json_encode([
            'success' => true,
            'services' => $services
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get service status: ' . $e->getMessage()]);
    }
}

function getPerformanceMetrics() {
    global $DB;
    
    try {
        // Get recent performance data
        $metrics = [];
        
        // Command processing performance
        $cmd_perf = $DB->prepare("
            SELECT 
                COUNT(*) as commands_last_hour,
                AVG(TIMESTAMPDIFF(MINUTE, created_at, completed_at)) as avg_processing_time
            FROM firewall_commands 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            AND completed_at IS NOT NULL
        ");
        $cmd_perf->execute();
        $cmd_metrics = $cmd_perf->fetch(PDO::FETCH_ASSOC);
        
        // Request processing performance
        $req_perf = $DB->prepare("
            SELECT 
                COUNT(*) as requests_last_hour,
                AVG(TIMESTAMPDIFF(SECOND, created_at, completed_at)) as avg_response_time
            FROM request_queue 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            AND completed_at IS NOT NULL
        ");
        $req_perf->execute();
        $req_metrics = $req_perf->fetch(PDO::FETCH_ASSOC);
        
        // System load
        $load_avg = sys_getloadavg();
        
        echo json_encode([
            'success' => true,
            'metrics' => [
                'commands_per_hour' => $cmd_metrics['commands_last_hour'] ?? 0,
                'avg_command_processing_time' => round($cmd_metrics['avg_processing_time'] ?? 0, 2),
                'requests_per_hour' => $req_metrics['requests_last_hour'] ?? 0,
                'avg_request_response_time' => round($req_metrics['avg_response_time'] ?? 0, 2),
                'system_load' => [
                    '1min' => round($load_avg[0], 2),
                    '5min' => round($load_avg[1], 2),
                    '15min' => round($load_avg[2], 2)
                ]
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get performance metrics: ' . $e->getMessage()]);
    }
}

function getRecentAlerts() {
    global $DB;
    
    try {
        // Generate alerts based on system conditions
        $alerts = [];
        
        // Check for stuck commands
        $stuck_cmd = $DB->prepare("
            SELECT COUNT(*) as count 
            FROM firewall_commands 
            WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stuck_cmd->execute();
        $stuck_count = $stuck_cmd->fetchColumn();
        
        if ($stuck_count > 0) {
            $alerts[] = [
                'severity' => 'warning',
                'message' => "$stuck_count commands stuck in pending state for >15 minutes",
                'timestamp' => date('Y-m-d H:i:s'),
                'component' => 'command_queue'
            ];
        }
        
        // Check for offline firewalls
        $offline_fw = $DB->prepare("
            SELECT hostname as name, TIMESTAMPDIFF(MINUTE, last_checkin, NOW()) as minutes_offline
            FROM firewalls 
            WHERE last_checkin < DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $offline_fw->execute();
        $offline_firewalls = $offline_fw->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($offline_firewalls as $fw) {
            $alerts[] = [
                'severity' => 'critical',
                'message' => "Firewall {$fw['name']} offline for {$fw['minutes_offline']} minutes",
                'timestamp' => date('Y-m-d H:i:s'),
                'component' => 'firewall'
            ];
        }
        
        // Check for high failure rates
        $failure_rate = $DB->prepare("
            SELECT 
                (SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)) as failure_percentage
            FROM firewall_commands 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $failure_rate->execute();
        $failure_pct = $failure_rate->fetchColumn();
        
        if ($failure_pct > 10) {
            $alerts[] = [
                'severity' => 'warning',
                'message' => sprintf("High command failure rate: %.1f%% in last hour", $failure_pct),
                'timestamp' => date('Y-m-d H:i:s'),
                'component' => 'command_queue'
            ];
        }
        
        echo json_encode([
            'success' => true,
            'alerts' => $alerts
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get recent alerts: ' . $e->getMessage()]);
    }
}

function getSystemInfo() {
    try {
        $info = [];
        
        // Server information
        $info['server'] = [
            'hostname' => gethostname(),
            'os' => php_uname('s') . ' ' . php_uname('r'),
            'php_version' => phpversion(),
            'uptime' => shell_exec('uptime -p'),
            'current_time' => date('Y-m-d H:i:s T')
        ];
        
        // Application version
        if (file_exists(__DIR__ . '/../config/instance.json')) {
            $instance_config = json_decode(file_get_contents(__DIR__ . '/../config/instance.json'), true);
            $info['application'] = $instance_config;
        }
        
        echo json_encode([
            'success' => true,
            'system_info' => $info
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get system info: ' . $e->getMessage()]);
    }
}
?>