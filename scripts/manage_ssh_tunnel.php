<?php
/**
 * SSH Tunnel Manager for OPNsense Firewalls
 * Manages SSH tunnels for remote firewall access
 */

require_once(__DIR__ . '/../inc/bootstrap_agent.php');
require_once(__DIR__ . '/manage_ssh_keys.php');
require_once(__DIR__ . '/manage_ssh_access.php');

require_once(__DIR__ . '/manage_tunnel_proxy.php');

function get_firewall_lan_ip($firewall_id) {
        $stmt = db()->prepare("SELECT lan_ip FROM firewall_agents WHERE firewall_id = ? AND lan_ip IS NOT NULL AND lan_ip != '' ORDER BY last_checkin DESC LIMIT 1");
    $stmt->execute([$firewall_id]);
    $result = $stmt->fetchColumn();
    error_log("DEBUG get_firewall_lan_ip($firewall_id): Retrieved LAN IP = " . ($result ?: 'NULL'));
    return $result ?: null;
}

function get_firewall_by_id($firewall_id) {
        $stmt = db()->prepare("SELECT * FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    return $stmt->fetch();
}

function is_tunnel_active($port) {
    // Check if tunnel is already running on this port (any destination IP)
    $cmd = "ps aux | grep 'ssh.*-L {$port}:' | grep -v grep";
    exec($cmd, $output);
    return !empty($output);
}

function start_tunnel($firewall, $duration_minutes = 30) {
    $firewall_id = $firewall['id'];
    $hostname = $firewall['hostname'];
    $ip = $firewall['wan_ip'] ?: ($firewall['ip_address'] ?: $firewall['hostname']);
    
    // Request temporary SSH access (creates firewall rule with expiry)
    $access_result = request_ssh_access($firewall_id, $duration_minutes);
    if (!$access_result['success']) {
        return ['success' => false, 'error' => 'Failed to request SSH access: ' . ($access_result['error'] ?? 'Unknown error')];
    }
    
    $port = $access_result['tunnel_port'];
    
    // Get firewall's web port (default to 443 if not set)
    $firewall_web_port = !empty($firewall['web_port']) ? (int)$firewall['web_port'] : 443;
    $protocol = ($firewall_web_port == 443) ? 'https' : 'http';
    $manager_host = 'localhost';
    
    // Check if tunnel is already running (quick check - just look for process)
    // Don't do a full connectivity test here, let the frontend polling handle that
    if (is_tunnel_active($port)) {
        return [
            'success' => true,
            'message' => 'Tunnel process already running',
            'port' => $port,
            'url' => "{$protocol}://{$manager_host}:{$port}",
            'expires_at' => $access_result['expires_at'] ?? null,
            'session_id' => $access_result['session_id'] ?? null,
            'pending' => false // Already running, should be ready quickly
        ];
    }
    
    // Ensure SSH key exists and is valid (non-blocking mode - don't wait for agent)
    $key_result = ensure_ssh_key($firewall_id, false, false);  // force_regenerate=false, allow_blocking=false
    if (!$key_result['success']) {
        return ['success' => false, 'error' => 'SSH key setup failed: ' . ($key_result['error'] ?? 'Unknown error')];
    }

    $key_file = $key_result['key_file'];

    // If key test failed but we're using it anyway, log warning
    if (isset($key_result['test_failed']) && $key_result['test_failed']) {
        error_log("WARNING: SSH key test failed for firewall {$firewall_id}, but attempting connection anyway");
    }
    
    // IMPORTANT: SSH tunnel forwards to the FIREWALL's localhost, not its LAN IP
    // The tunnel runs FROM manager TO firewall, so we access firewall's local web interface
    // -L manager_port:localhost:firewall_web_port
    $tunnel_target = 'localhost';  // Always use localhost on the firewall side
    error_log("DEBUG start_tunnel: Tunnel will forward port $port to firewall's localhost:$firewall_web_port");
    
    // Start SSH tunnel
    // -L port:target:web_port means: Listen on localhost:port, forward to firewall's target:web_port
    // SECURITY: Bind to 127.0.0.1 only - tunnels should ONLY be accessible via tunnel_proxy.php
    $ssh_cmd = sprintf(
        "timeout 10 ssh -i %s -o StrictHostKeyChecking=no -o ConnectTimeout=5 -o ServerAliveInterval=60 -o ServerAliveCountMax=2 -L 127.0.0.1:%s:%s:%s -N -f root@%s 2>&1",
        escapeshellarg($key_file),
        $port,
        $tunnel_target,
        $firewall_web_port,
        escapeshellarg($ip)
    );
    
    exec($ssh_cmd, $output, $return_code);
    
    if ($return_code === 0) {
        // OPTION A: Wait for tunnel to actually respond before proceeding
        // This prevents nginx from being created before SSH tunnel is ready
        $tunnel_ready = false;
        $max_attempts = 3; // 3 attempts = 3 seconds max wait (faster, prevents UI hang)
        
        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            // Test if port is actually accepting connections (use correct protocol based on web_port)
            $test_cmd = "timeout 1 curl -s -k -o /dev/null -w '%{http_code}' {$protocol}://localhost:{$port}/ 2>/dev/null";
            exec($test_cmd, $test_output, $test_result);
            
            // Any HTTP response (even 403, 404, 500) means tunnel is working
            // We just need to know the port is accepting connections
            if ($test_result === 0 || !empty($test_output)) {
                $tunnel_ready = true;
                error_log("Tunnel on port {$port} ready after {$attempt} attempts");
                break;
            }
            
            // Wait 1 second before retry
            if ($attempt < $max_attempts) {
                sleep(1);
            }
        }
        
        if (!$tunnel_ready) {
            // Tunnel process started but not responding - still return success but warn
            error_log("WARNING: Tunnel on port {$port} started but not responding after {$max_attempts} seconds");
        }
        
        return [
            'success' => true, 
            'port' => $port,
            'url' => "{$protocol}://{$manager_host}:{$port}",
            'message' => "Tunnel established on port {$port}",
            'expires_at' => $access_result['expires_at'],
            'session_id' => $access_result['session_id'],
            'tunnel_port' => $port,
            'pending' => !$tunnel_ready // Only pending if not yet ready
        ];
    } else {
        return ['success' => false, 'error' => 'SSH command failed', 'output' => implode("\n", $output)];
    }
}

function stop_tunnel($firewall_id, $port) {
    $cmd = "ps aux | grep 'ssh.*-L {$port}:localhost:' | grep -v grep | awk '{print $2}'";
    exec($cmd, $pids);
    
    if (empty($pids)) {
        return ['success' => true, 'message' => 'No tunnel was running'];
    }
    
    foreach ($pids as $pid) {
        posix_kill(intval($pid), 15); // SIGTERM
    }
    
    return ['success' => true, 'message' => 'Tunnel stopped'];
}

function get_tunnel_status($firewall) {
    $port = $firewall['ssh_tunnel_port'] ?: 9443;
    $is_active = is_tunnel_active($port);
    $manager_host = gethostname();
    
    return [
        'active' => $is_active,
        'port' => $port,
        'url' => $is_active ? "https://{$manager_host}:{$port}" : null
    ];
}

// CLI interface
if (php_sapi_name() === 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'manage_ssh_tunnel.php') {
    $command = $argv[1] ?? 'help';
    $firewall_id = $argv[2] ?? null;
    
    if (!$firewall_id && $command !== 'help') {
        echo "Error: Firewall ID required\n";
        exit(1);
    }
    
    switch ($command) {
        case 'start':
            $firewall = get_firewall_by_id($firewall_id);
            if (!$firewall) {
                echo "Error: Firewall not found\n";
                exit(1);
            }
            $result = start_tunnel($firewall);
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            exit($result['success'] ? 0 : 1);
            
        case 'stop':
            $firewall = get_firewall_by_id($firewall_id);
            if (!$firewall) {
                echo "Error: Firewall not found\n";
                exit(1);
            }
            $port = $firewall['ssh_tunnel_port'] ?: 9443;
            $result = stop_tunnel($firewall_id, $port);
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            exit($result['success'] ? 0 : 1);
            
        case 'status':
            $firewall = get_firewall_by_id($firewall_id);
            if (!$firewall) {
                echo "Error: Firewall not found\n";
                exit(1);
            }
            $result = get_tunnel_status($firewall);
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            exit(0);
            
        case 'help':
        default:
            echo "SSH Tunnel Manager\n";
            echo "Usage: manage_ssh_tunnel.php <command> <firewall_id>\n\n";
            echo "Commands:\n";
            echo "  start <id>   - Start SSH tunnel for firewall\n";
            echo "  stop <id>    - Stop SSH tunnel for firewall\n";
            echo "  status <id>  - Check tunnel status\n";
            echo "  help         - Show this help\n";
            exit(0);
    }
}
