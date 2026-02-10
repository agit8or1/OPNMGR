<?php
/**
 * Tunnel Proxy Manager
 * Manages nginx reverse proxy configurations for SSH tunnels
 * Handles activity tracking, timeouts, and port recycling
 */

require_once(__DIR__ . '/../inc/bootstrap_agent.php');

// Configuration
define('NGINX_TUNNEL_CONFIG_DIR', '/etc/nginx/tunnel-proxies');
define('NGINX_TUNNEL_CONFIG_MAIN', '/etc/nginx/sites-enabled/tunnel-proxy.conf');
define('MAX_TUNNEL_LIFETIME', 2 * 60 * 60); // 2 hours max
define('IDLE_TIMEOUT', 30 * 60); // 30 minutes idle timeout

/**
 * Create nginx config for a tunnel session
 */
function create_tunnel_proxy_config($session_id, $tunnel_port, $firewall_id) {
        
    // Generate unique path for this tunnel
    $proxy_path = "/tunnel/{$session_id}";
    
    // Create config snippet
    $config = "
# Tunnel Proxy for Session {$session_id} (Firewall {$firewall_id})
location {$proxy_path}/ {
    proxy_pass http://127.0.0.1:{$tunnel_port}/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade \$http_upgrade;
    proxy_set_header Connection 'upgrade';
    proxy_set_header Host \$host;
    proxy_set_header X-Real-IP \$remote_addr;
    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto \$scheme;
    proxy_cache_bypass \$http_upgrade;
    
    # Timeouts
    proxy_connect_timeout 60s;
    proxy_send_timeout 300s;
    proxy_read_timeout 300s;
    
    # Activity tracking
    access_log /var/log/nginx/tunnel_{$session_id}_access.log;
}
";
    
    // Create directory if not exists
    if (!is_dir(NGINX_TUNNEL_CONFIG_DIR)) {
        mkdir(NGINX_TUNNEL_CONFIG_DIR, 0755, true);
    }
    
    $config_file = NGINX_TUNNEL_CONFIG_DIR . "/session_{$session_id}.conf";
    file_put_contents($config_file, $config);
    
    // Update main include file
    regenerate_main_config();
    
    // Reload nginx
    exec('sudo systemctl reload nginx 2>&1', $output, $return);
    
    // Update database with proxy path
    $stmt = db()->prepare("
        UPDATE ssh_access_sessions 
        SET proxy_path = ?, last_activity = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$proxy_path, $session_id]);
    
    return [
        'success' => $return === 0,
        'proxy_path' => $proxy_path,
        'config_file' => $config_file,
        'reload_output' => implode("\n", $output)
    ];
}

/**
 * Regenerate main tunnel proxy config that includes all session configs
 */
function regenerate_main_config() {
    $config = "# Auto-generated tunnel proxy configurations\n";
    $config .= "# DO NOT EDIT - Managed by manage_tunnel_proxy.php\n\n";
    
    if (is_dir(NGINX_TUNNEL_CONFIG_DIR)) {
        $files = glob(NGINX_TUNNEL_CONFIG_DIR . '/session_*.conf');
        foreach ($files as $file) {
            $config .= "include {$file};\n";
        }
    }
    
    file_put_contents(NGINX_TUNNEL_CONFIG_MAIN, $config);
}

/**
 * Remove tunnel proxy config
 */
function remove_tunnel_proxy_config($session_id) {
    $config_file = NGINX_TUNNEL_CONFIG_DIR . "/session_{$session_id}.conf";
    
    if (file_exists($config_file)) {
        unlink($config_file);
    }
    
    // Remove access log
    $log_file = "/var/log/nginx/tunnel_{$session_id}_access.log";
    if (file_exists($log_file)) {
        unlink($log_file);
    }
    
    // Regenerate main config
    regenerate_main_config();
    
    // Reload nginx
    exec('sudo systemctl reload nginx 2>&1', $output, $return);
    
    return ['success' => $return === 0, 'output' => implode("\n", $output)];
}

/**
 * Get last activity time from nginx access log
 */
function get_last_activity($session_id) {
    $log_file = "/var/log/nginx/tunnel_{$session_id}_access.log";
    
    if (!file_exists($log_file)) {
        return null;
    }
    
    // Get last line of log
    exec("tail -1 {$log_file}", $output);
    
    if (empty($output)) {
        return null;
    }
    
    // Parse nginx log timestamp
    if (preg_match('/\[([^\]]+)\]/', $output[0], $matches)) {
        return strtotime($matches[1]);
    }
    
    return null;
}

/**
 * Cleanup expired tunnels
 */
function cleanup_expired_tunnels() {
        
    $now = time();
    $cleaned = [];
    
    // Get all active sessions
    $stmt = db()->query("
        SELECT id, firewall_id, tunnel_port, created_at, last_activity, expires_at 
        FROM ssh_access_sessions 
        WHERE status = 'active'
    ");
    
    while ($session = $stmt->fetch()) {
        $session_id = $session['id'];
        $should_cleanup = false;
        $reason = '';
        
        // Check max lifetime
        $created = strtotime($session['created_at']);
        $age = $now - $created;
        
        if ($age > MAX_TUNNEL_LIFETIME) {
            $should_cleanup = true;
            $reason = "Max lifetime exceeded ({$age}s)";
        }
        
        // Check expiry time
        if ($session['expires_at'] && strtotime($session['expires_at']) < $now) {
            $should_cleanup = true;
            $reason = "Expired at {$session['expires_at']}";
        }
        
        // Check idle timeout
        $last_activity = get_last_activity($session_id);
        if ($last_activity) {
            $idle_time = $now - $last_activity;
            if ($idle_time > IDLE_TIMEOUT) {
                $should_cleanup = true;
                $reason = "Idle timeout ({$idle_time}s)";
            }
        }
        
        if ($should_cleanup) {
            // Kill SSH tunnel
            $port = $session['tunnel_port'];
            exec("ps aux | grep 'ssh.*-L {$port}:localhost:' | grep -v grep | awk '{print \$2}'", $pids);
            foreach ($pids as $pid) {
                posix_kill(intval($pid), 15);
            }
            
            // Remove nginx config
            remove_tunnel_proxy_config($session_id);
            
            // Update database
            $upd = db()->prepare("UPDATE ssh_access_sessions SET status = 'closed', closed_reason = ? WHERE id = ?");
            $upd->execute([$reason, $session_id]);
            
            $cleaned[] = [
                'session_id' => $session_id,
                'firewall_id' => $session['firewall_id'],
                'port' => $port,
                'reason' => $reason
            ];
        }
    }
    
    return ['cleaned' => count($cleaned), 'sessions' => $cleaned];
}

/**
 * Get available port for new tunnel
 */
function get_available_port() {
        
    // Get currently used ports
    $stmt = db()->query("SELECT tunnel_port FROM ssh_access_sessions WHERE status = 'active'");
    $used_ports = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Port range: 8100-8199
    for ($port = 8100; $port < 8200; $port++) {
        if (!in_array($port, $used_ports)) {
            // Check if port is actually free on system
            exec("ss -tln | grep ':$port '", $output);
            if (empty($output)) {
                return $port;
            }
        }
    }
    
    return null; // No ports available
}

// CLI interface
if (php_sapi_name() === 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'manage_tunnel_proxy.php') {
    $command = $argv[1] ?? 'help';
    
    switch ($command) {
        case 'cleanup':
            $result = cleanup_expired_tunnels();
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            exit(0);
            
        case 'create':
            $session_id = $argv[2] ?? null;
            $port = $argv[3] ?? null;
            $firewall_id = $argv[4] ?? null;
            
            if (!$session_id || !$port || !$firewall_id) {
                echo "Usage: manage_tunnel_proxy.php create <session_id> <port> <firewall_id>\n";
                exit(1);
            }
            
            $result = create_tunnel_proxy_config($session_id, $port, $firewall_id);
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            exit($result['success'] ? 0 : 1);
            
        case 'remove':
            $session_id = $argv[2] ?? null;
            
            if (!$session_id) {
                echo "Usage: manage_tunnel_proxy.php remove <session_id>\n";
                exit(1);
            }
            
            $result = remove_tunnel_proxy_config($session_id);
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            exit($result['success'] ? 0 : 1);
            
        case 'get-port':
            $port = get_available_port();
            echo json_encode(['port' => $port]) . "\n";
            exit($port ? 0 : 1);
            
        case 'help':
        default:
            echo "Tunnel Proxy Manager\n";
            echo "Usage: manage_tunnel_proxy.php <command> [args]\n\n";
            echo "Commands:\n";
            echo "  cleanup                        - Clean up expired tunnels\n";
            echo "  create <sid> <port> <fwid>     - Create nginx proxy config\n";
            echo "  remove <session_id>            - Remove proxy config\n";
            echo "  get-port                       - Get available port\n";
            exit(0);
    }
}
