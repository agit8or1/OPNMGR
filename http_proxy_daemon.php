#!/usr/bin/env php
<?php
/**
 * Simple HTTP Proxy for Firewall Connections
 * Creates HTTP proxies instead of SSH tunnels for easier setup
 */

require_once __DIR__ . '/inc/db.php';

$CHECK_INTERVAL = 30;
$PROXY_TIMEOUT = 3600;
$active_proxies = [];

echo "Starting HTTP Proxy Daemon...\n";

while (true) {
    try {
        cleanupDeadProxies();
        checkProxyRequests();
        cleanupExpiredProxies();
        sleep($CHECK_INTERVAL);
    } catch (Exception $e) {
        error_log("Proxy daemon error: " . $e->getMessage());
        sleep(5);
    }
}

function cleanupDeadProxies() {
    global $active_proxies, $DB;
    
    foreach ($active_proxies as $firewall_id => $proxy_info) {
        $pid = $proxy_info['pid'];
        
        if (!posix_kill($pid, 0)) {
            echo "Proxy for firewall $firewall_id (PID $pid) has died\n";
            
            $stmt = $DB->prepare('UPDATE firewalls SET tunnel_active = 0, tunnel_port = NULL WHERE id = ?');
            $stmt->execute([$firewall_id]);
            
            unset($active_proxies[$firewall_id]);
        }
    }
}

function checkProxyRequests() {
    global $active_proxies, $DB;
    
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
        
        if (isset($active_proxies[$firewall_id])) {
            continue;
        }
        
        $proxy_result = startHttpProxy($request);
        if ($proxy_result['success']) {
            $active_proxies[$firewall_id] = [
                'pid' => $proxy_result['pid'],
                'port' => $tunnel_port,
                'established' => time()
            ];
            echo "Started HTTP proxy for firewall $firewall_id on port $tunnel_port\n";
        } else {
            echo "Failed to start proxy for firewall $firewall_id: {$proxy_result['message']}\n";
            
            $stmt = $DB->prepare('UPDATE firewalls SET tunnel_active = 0 WHERE id = ?');
            $stmt->execute([$firewall_id]);
        }
    }
}

function startHttpProxy($firewall_info) {
    $tunnel_port = $firewall_info['tunnel_port'];
    $wan_ip = $firewall_info['wan_ip'];
    
    if (!$wan_ip) {
        return ['success' => false, 'message' => 'No WAN IP available'];
    }
    
    // Create a simple socat proxy - use port 8443 for OPNsense web interface
    $proxy_cmd = "socat TCP-LISTEN:$tunnel_port,fork,reuseaddr SSL:$wan_ip:8443,verify=0";
    
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    
    $process = proc_open($proxy_cmd, $descriptors, $pipes);
    
    if (!is_resource($process)) {
        return ['success' => false, 'message' => 'Failed to start socat process'];
    }
    
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    
    $status = proc_get_status($process);
    $pid = $status['pid'];
    
    sleep(1);
    
    if (!posix_kill($pid, 0)) {
        return ['success' => false, 'message' => 'Proxy process died immediately'];
    }
    
    return ['success' => true, 'pid' => $pid];
}

function cleanupExpiredProxies() {
    global $active_proxies, $DB, $PROXY_TIMEOUT;
    
    $cutoff_time = time() - $PROXY_TIMEOUT;
    
    foreach ($active_proxies as $firewall_id => $proxy_info) {
        if ($proxy_info['established'] < $cutoff_time) {
            echo "Proxy for firewall $firewall_id has expired, terminating\n";
            
            posix_kill($proxy_info['pid'], SIGTERM);
            
            $stmt = $DB->prepare('UPDATE firewalls SET tunnel_active = 0, tunnel_port = NULL WHERE id = ?');
            $stmt->execute([$firewall_id]);
            
            unset($active_proxies[$firewall_id]);
        }
    }
}

function signalHandler($signal) {
    global $active_proxies, $DB;
    
    echo "\nReceived signal $signal, cleaning up...\n";
    
    foreach ($active_proxies as $firewall_id => $proxy_info) {
        posix_kill($proxy_info['pid'], SIGTERM);
        
        $stmt = $DB->prepare('UPDATE firewalls SET tunnel_active = 0, tunnel_port = NULL WHERE id = ?');
        $stmt->execute([$firewall_id]);
    }
    
    exit(0);
}

pcntl_signal(SIGTERM, 'signalHandler');
pcntl_signal(SIGINT, 'signalHandler');