#!/usr/bin/env php
<?php
/**
 * Tunnel Management Daemon
 * Monitors database for tunnel requests and establishes SSH reverse tunnels
 */

require_once __DIR__ . '/inc/db.php';

// Configuration
$CHECK_INTERVAL = 30; // seconds between database checks
$TUNNEL_TIMEOUT = 3600; // 1 hour tunnel timeout
$MAX_TUNNELS = 50; // maximum concurrent tunnels

// Active tunnel processes
$active_tunnels = [];

echo "Starting Tunnel Management Daemon...\n";
echo "Check interval: {$CHECK_INTERVAL} seconds\n";
echo "Tunnel timeout: {$TUNNEL_TIMEOUT} seconds\n";

while (true) {
    try {
        // Clean up dead processes
        cleanupDeadTunnels();
        
        // Check for new tunnel requests
        checkTunnelRequests();
        
        // Clean up expired tunnels
        cleanupExpiredTunnels();
        
        sleep($CHECK_INTERVAL);
        
    } catch (Exception $e) {
        error_log("Tunnel daemon error: " . $e->getMessage());
        sleep(5);
    }
}

function cleanupDeadTunnels() {
    global $active_tunnels, $DB;
    
    foreach ($active_tunnels as $firewall_id => $tunnel_info) {
        $pid = $tunnel_info['pid'];
        
        // Check if process is still running
        if (!posix_kill($pid, 0)) {
            echo "Tunnel for firewall $firewall_id (PID $pid) has died\n";
            
            // Update database
            $stmt = $DB->prepare('UPDATE firewalls SET tunnel_active = 0, tunnel_port = NULL WHERE id = ?');
            $stmt->execute([$firewall_id]);
            
            unset($active_tunnels[$firewall_id]);
        }
    }
}

function checkTunnelRequests() {
    global $active_tunnels, $DB, $MAX_TUNNELS;
    
    // Get pending tunnel requests
    $stmt = $DB->prepare('
        SELECT id, hostname, wan_ip, tunnel_port, tunnel_client_ip 
        FROM firewalls 
        WHERE tunnel_active = 1 
        AND tunnel_port IS NOT NULL 
        AND tunnel_established > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    ');
    $stmt->execute();
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($requests as $request) {
        $firewall_id = $request['id'];
        $tunnel_port = $request['tunnel_port'];
        $client_ip = $request['tunnel_client_ip'];
        
        // Skip if we already have a tunnel for this firewall
        if (isset($active_tunnels[$firewall_id])) {
            continue;
        }
        
        // Skip if we're at max capacity
        if (count($active_tunnels) >= $MAX_TUNNELS) {
            echo "Max tunnels reached ($MAX_TUNNELS), skipping new requests\n";
            break;
        }
        
        // Try to establish tunnel
        $tunnel_result = establishTunnel($request);
        if ($tunnel_result['success']) {
            $active_tunnels[$firewall_id] = [
                'pid' => $tunnel_result['pid'],
                'port' => $tunnel_port,
                'established' => time(),
                'client_ip' => $client_ip
            ];
            echo "Established tunnel for firewall $firewall_id on port $tunnel_port (PID {$tunnel_result['pid']})\n";
        } else {
            echo "Failed to establish tunnel for firewall $firewall_id: {$tunnel_result['message']}\n";
            
            // Mark tunnel as failed in database
            $stmt = $DB->prepare('UPDATE firewalls SET tunnel_active = 0 WHERE id = ?');
            $stmt->execute([$firewall_id]);
        }
    }
}

function establishTunnel($firewall_info) {
    $firewall_id = $firewall_info['id'];
    $tunnel_port = $firewall_info['tunnel_port'];
    $wan_ip = $firewall_info['wan_ip'];
    $hostname = $firewall_info['hostname'];
    
    if (!$wan_ip) {
        return ['success' => false, 'message' => 'No WAN IP available'];
    }
    
    // Check if port is already in use
    $check_cmd = "ss -tln | grep ':$tunnel_port '";
    $port_check = shell_exec($check_cmd);
    if ($port_check) {
        return ['success' => false, 'message' => "Port $tunnel_port already in use"];
    }
    
    // Create SSH tunnel command
    // This creates a reverse tunnel where the firewall connects to us
    // and we forward local port to firewall's web interface (port 80, not 443)
    $key_path = "/etc/opnmgr/keys/id_firewall_{$firewall_id}";
    $ssh_cmd = "ssh -i $key_path -o StrictHostKeyChecking=no -o ConnectTimeout=5 " .
               "-o ServerAliveInterval=60 -o ServerAliveCountMax=2 " .
               "-L 127.0.0.1:$tunnel_port:localhost:80 -N root@$wan_ip";
    
    // Start tunnel in background
    $descriptors = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w']   // stderr
    ];
    
    $process = proc_open($ssh_cmd, $descriptors, $pipes);
    
    if (!is_resource($process)) {
        return ['success' => false, 'message' => 'Failed to start SSH process'];
    }
    
    // Close pipes we don't need
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    
    // Get PID
    $status = proc_get_status($process);
    $pid = $status['pid'];
    
    // Give it a moment to establish
    sleep(2);
    
    // Check if process is still running
    if (!posix_kill($pid, 0)) {
        return ['success' => false, 'message' => 'SSH tunnel process died immediately'];
    }
    
    // Test if tunnel is working
    $test_result = testTunnelConnection($tunnel_port);
    if (!$test_result) {
        posix_kill($pid, SIGTERM);
        return ['success' => false, 'message' => 'Tunnel established but connection test failed'];
    }
    
    return ['success' => true, 'pid' => $pid];
}

function testTunnelConnection($port) {
    // Test HTTP connection since tunnel forwards to port 80 (not 443)
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "http://127.0.0.1:$port",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_NOBODY => true  // HEAD request only
    ]);

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Any HTTP response (even 404) means the tunnel is working
    return $http_code > 0;
}

function cleanupExpiredTunnels() {
    global $active_tunnels, $DB, $TUNNEL_TIMEOUT;
    
    $cutoff_time = time() - $TUNNEL_TIMEOUT;
    
    foreach ($active_tunnels as $firewall_id => $tunnel_info) {
        if ($tunnel_info['established'] < $cutoff_time) {
            echo "Tunnel for firewall $firewall_id has expired, terminating\n";
            
            // Kill the process
            posix_kill($tunnel_info['pid'], SIGTERM);
            
            // Update database
            $stmt = $DB->prepare('UPDATE firewalls SET tunnel_active = 0, tunnel_port = NULL WHERE id = ?');
            $stmt->execute([$firewall_id]);
            
            unset($active_tunnels[$firewall_id]);
        }
    }
}

// Handle signals gracefully
function signalHandler($signal) {
    global $active_tunnels, $DB;
    
    echo "\nReceived signal $signal, cleaning up...\n";
    
    // Kill all tunnel processes
    foreach ($active_tunnels as $firewall_id => $tunnel_info) {
        posix_kill($tunnel_info['pid'], SIGTERM);
        
        // Update database
        $stmt = $DB->prepare('UPDATE firewalls SET tunnel_active = 0, tunnel_port = NULL WHERE id = ?');
        $stmt->execute([$firewall_id]);
    }
    
    exit(0);
}

pcntl_signal(SIGTERM, 'signalHandler');
pcntl_signal(SIGINT, 'signalHandler');