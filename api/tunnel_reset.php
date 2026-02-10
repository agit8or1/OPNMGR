<?php
/**
 * Tunnel System Reset API
 * Provides complete reset functionality for SSH tunnels and nginx configs
 */
// Require authentication - check if user session exists
require_once __DIR__ . '/../inc/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// CSRF validation for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
}

// Get action
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'status':
        getTunnelStatus();
        break;
    
    case 'reset_all':
        resetAllTunnels();
        break;
    
    case 'kill_tunnel':
        $pid = $_POST['pid'] ?? 0;
        killTunnel($pid);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

/**
 * Get current tunnel system status
 */
function getTunnelStatus() {
    // Get all SSH tunnel processes
    exec("ps aux | grep 'ssh.*-L 0.0.0.0:' | grep -v grep", $ps_output);
    
    $ssh_tunnels = [];
    foreach ($ps_output as $line) {
        if (preg_match('/\s+(\d+)\s+.*-L 0\.0\.0\.0:(\d+):localhost:80/', $line, $matches)) {
            $pid = $matches[1];
            $port = $matches[2];
            
            // Check if session exists in DB
            $stmt = db()->prepare("SELECT id, firewall_id, status, created_at, expires_at 
                                   FROM ssh_access_sessions 
                                   WHERE tunnel_port = ? 
                                   ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$port]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $ssh_tunnels[] = [
                'pid' => $pid,
                'port' => $port,
                'session_id' => $session['id'] ?? null,
                'firewall_id' => $session['firewall_id'] ?? null,
                'status' => $session['status'] ?? 'orphaned',
                'created_at' => $session['created_at'] ?? null,
                'is_zombie' => !$session || $session['status'] !== 'active'
            ];
        }
    }
    
    // Get active sessions from DB
    $stmt = db()->query("SELECT id, firewall_id, tunnel_port, status, created_at, expires_at 
                        FROM ssh_access_sessions 
                        WHERE status = 'active' 
                        ORDER BY created_at DESC");
    $db_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get nginx tunnel configs
    exec("ls /etc/nginx/sites-enabled/tunnel-session-* 2>/dev/null", $nginx_configs);
    $nginx_ports = [];
    foreach ($nginx_configs as $config) {
        if (preg_match('/tunnel-session-(\d+)$/', $config, $matches)) {
            $session_id = $matches[1];
            
            // Read config to get port
            $config_content = file_get_contents($config);
            if (preg_match('/listen (\d+) ssl/', $config_content, $port_matches)) {
                $nginx_ports[] = [
                    'session_id' => $session_id,
                    'port' => $port_matches[1],
                    'config_file' => $config
                ];
            }
        }
    }
    
    // Get listening nginx ports
    exec("sudo ss -tlnp 2>/dev/null | grep nginx | grep -oP ':\K(810[0-9]|811[0-9])' | sort -n | uniq", $listening_ports);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'ssh_tunnels' => $ssh_tunnels,
            'db_sessions' => $db_sessions,
            'nginx_configs' => $nginx_ports,
            'nginx_listening' => $listening_ports,
            'zombie_count' => count(array_filter($ssh_tunnels, fn($t) => $t['is_zombie'])),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
}

/**
 * Reset all tunnels - nuclear option
 */
function resetAllTunnels() {
    $log = [];
    
    // Step 1: Kill ALL SSH tunnel processes
    $log[] = "Step 1: Killing all SSH tunnel processes...";
    exec("ps aux | grep 'ssh.*-L 0.0.0.0:' | grep -v grep | awk '{print $2}'", $pids);
    
    foreach ($pids as $pid) {
        exec("sudo kill -9 $pid 2>&1", $output, $result);
        if ($result === 0) {
            $log[] = "  ✓ Killed SSH tunnel PID $pid";
        } else {
            $log[] = "  ✗ Failed to kill PID $pid: " . implode(', ', $output);
        }
    }
    
    if (empty($pids)) {
        $log[] = "  • No SSH tunnels to kill";
    }
    
    // Step 2: Close all active sessions in DB
    $log[] = "\nStep 2: Closing all active sessions in database...";
    $stmt = db()->query("SELECT COUNT(*) as count FROM ssh_access_sessions WHERE status = 'active'");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($count > 0) {
        db()->exec("UPDATE ssh_access_sessions 
                   SET status = 'closed', 
                       closed_at = NOW() 
                   WHERE status = 'active'");
        $log[] = "  ✓ Closed $count active session(s)";
    } else {
        $log[] = "  • No active sessions to close";
    }
    
    // Step 3: Remove all nginx tunnel configs
    $log[] = "\nStep 3: Removing nginx tunnel configurations...";
    exec("ls /etc/nginx/sites-enabled/tunnel-session-* 2>/dev/null", $configs);
    
    foreach ($configs as $config) {
        $basename = basename($config);
        exec("sudo rm -f /etc/nginx/sites-enabled/$basename /etc/nginx/sites-available/$basename 2>&1", $output, $result);
        if ($result === 0) {
            $log[] = "  ✓ Removed $basename";
        } else {
            $log[] = "  ✗ Failed to remove $basename: " . implode(', ', $output);
        }
    }
    
    if (empty($configs)) {
        $log[] = "  • No nginx configs to remove";
    }
    
    // Step 4: Reload nginx
    $log[] = "\nStep 4: Reloading nginx...";
    exec("sudo systemctl reload nginx 2>&1", $output, $result);
    if ($result === 0) {
        $log[] = "  ✓ Nginx reloaded successfully";
    } else {
        $log[] = "  ✗ Failed to reload nginx: " . implode(', ', $output);
    }
    
    // Step 5: Update firewall tunnel ports
    $log[] = "\nStep 5: Clearing firewall tunnel ports...";
    db()->exec("UPDATE firewalls SET ssh_tunnel_port = NULL");
    $log[] = "  ✓ Cleared all firewall tunnel ports";
    
    // Step 6: Verify cleanup
    $log[] = "\nStep 6: Verifying cleanup...";
    exec("ps aux | grep 'ssh.*-L 0.0.0.0:' | grep -v grep | wc -l", $remaining_tunnels);
    $remaining = intval($remaining_tunnels[0] ?? 0);
    
    exec("ls /etc/nginx/sites-enabled/tunnel-session-* 2>/dev/null | wc -l", $remaining_configs);
    $remaining_nginx = intval($remaining_configs[0] ?? 0);
    
    $stmt = db()->query("SELECT COUNT(*) as count FROM ssh_access_sessions WHERE status = 'active'");
    $remaining_db = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $log[] = "  • SSH tunnels remaining: $remaining";
    $log[] = "  • Nginx configs remaining: $remaining_nginx";
    $log[] = "  • Active DB sessions: $remaining_db";
    
    $success = ($remaining === 0 && $remaining_nginx === 0 && $remaining_db === 0);
    
    if ($success) {
        $log[] = "\n✓ TUNNEL RESET COMPLETE - All systems cleared";
    } else {
        $log[] = "\n⚠ TUNNEL RESET INCOMPLETE - Manual intervention may be needed";
    }
    
    // Log to system
    error_log("TUNNEL RESET performed by " . ($_SESSION['username'] ?? 'unknown') . " at " . date('Y-m-d H:i:s'));
    
    echo json_encode([
        'success' => $success,
        'log' => $log,
        'summary' => [
            'tunnels_killed' => count($pids),
            'sessions_closed' => $count,
            'configs_removed' => count($configs),
            'remaining' => [
                'tunnels' => $remaining,
                'configs' => $remaining_nginx,
                'db_sessions' => $remaining_db
            ]
        ]
    ]);
}

/**
 * Kill a specific tunnel by PID
 */
function killTunnel($pid) {
    if (!$pid || !is_numeric($pid)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid PID']);
        return;
    }
    
    // Verify it's an SSH tunnel process
    exec("ps aux | grep -E '^www-data\s+$pid\s+.*ssh.*-L' | grep -v grep", $check);
    if (empty($check)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Process not found or not an SSH tunnel']);
        return;
    }
    
    exec("sudo kill -9 $pid 2>&1", $output, $result);
    
    if ($result === 0) {
        echo json_encode([
            'success' => true,
            'message' => "Killed tunnel process $pid"
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to kill process',
            'details' => implode("\n", $output)
        ]);
    }
}
