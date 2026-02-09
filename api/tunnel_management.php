<?php
/**
 * Tunnel Management API
 * Master reset and management for SSH tunnels and nginx configs
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';

header('Content-Type: application/json');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        listActiveTunnels();
        break;
    
    case 'reset_all':
        resetAllTunnels();
        break;
    
    case 'kill_tunnel':
        $session_id = (int)($_POST['session_id'] ?? 0);
        killTunnel($session_id);
        break;
    
    case 'cleanup_zombies':
        cleanupZombieTunnels();
        break;
    
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function listActiveTunnels() {
    global $DB;
    
    try {
        // Get all sessions from database
        $stmt = $DB->query("
            SELECT 
                s.id,
                s.firewall_id,
                s.tunnel_port,
                s.status,
                s.created_at,
                s.last_activity,
                f.hostname,
                f.ip_address,
                TIMESTAMPDIFF(MINUTE, s.created_at, NOW()) as age_minutes
            FROM ssh_access_sessions s
            LEFT JOIN firewalls f ON s.firewall_id = f.id
            ORDER BY s.created_at DESC
            LIMIT 50
        ");
        $db_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get running SSH tunnels
        exec("ps aux | grep 'ssh.*-L 0.0.0.0:' | grep -v grep", $ps_output);
        $running_tunnels = [];
        foreach ($ps_output as $line) {
            if (preg_match('/-L 0.0.0.0:(\d+):/', $line, $matches)) {
                preg_match('/^\S+\s+(\d+)/', $line, $pid_match);
                $running_tunnels[(int)$matches[1]] = [
                    'pid' => (int)$pid_match[1],
                    'port' => (int)$matches[1]
                ];
            }
        }
        
        // Get nginx configs
        exec("ls /etc/nginx/sites-enabled/tunnel-session-* 2>/dev/null", $nginx_output);
        $nginx_configs = [];
        foreach ($nginx_output as $file) {
            if (preg_match('/tunnel-session-(\d+)$/', $file, $matches)) {
                $nginx_configs[(int)$matches[1]] = true;
            }
        }
        
        // Match everything up
        $tunnels = [];
        foreach ($db_sessions as $session) {
            $port = (int)$session['tunnel_port'];
            $session_id = (int)$session['id'];
            $https_port = $port - 1;
            
            $has_ssh = isset($running_tunnels[$port]);
            $has_nginx = isset($nginx_configs[$session_id]);
            
            $tunnels[] = [
                'session_id' => $session_id,
                'firewall_id' => (int)$session['firewall_id'],
                'hostname' => $session['hostname'],
                'ip_address' => $session['ip_address'],
                'tunnel_port' => $port,
                'https_port' => $https_port,
                'status' => $session['status'],
                'created_at' => $session['created_at'],
                'age_minutes' => (int)$session['age_minutes'],
                'has_ssh_tunnel' => $has_ssh,
                'has_nginx_config' => $has_nginx,
                'ssh_pid' => $has_ssh ? $running_tunnels[$port]['pid'] : null,
                'is_zombie' => ($session['status'] === 'closed' && $has_ssh),
                'is_incomplete' => ($session['status'] === 'active' && (!$has_ssh || !$has_nginx))
            ];
        }
        
        echo json_encode([
            'success' => true,
            'tunnels' => $tunnels,
            'summary' => [
                'total' => count($tunnels),
                'active' => count(array_filter($tunnels, fn($t) => $t['status'] === 'active')),
                'zombies' => count(array_filter($tunnels, fn($t) => $t['is_zombie'])),
                'incomplete' => count(array_filter($tunnels, fn($t) => $t['is_incomplete']))
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("tunnel_management.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Internal server error']);
    }
}

function resetAllTunnels() {
    global $DB;
    
    try {
        $results = [
            'ssh_killed' => 0,
            'nginx_removed' => 0,
            'db_updated' => 0,
            'errors' => []
        ];
        
        // 1. Kill all SSH tunnels
        exec("ps aux | grep 'ssh.*-L 0.0.0.0:' | grep -v grep | awk '{print $2}'", $pids);
        foreach ($pids as $pid) {
            exec("sudo kill -9 " . escapeshellarg($pid), $output, $return);
            if ($return === 0) {
                $results['ssh_killed']++;
            } else {
                $results['errors'][] = "Failed to kill PID $pid";
            }
        }
        
        // 2. Remove all nginx tunnel configs
        exec("sudo rm -f /etc/nginx/sites-enabled/tunnel-session-* 2>&1", $output, $return);
        exec("sudo rm -f /etc/nginx/sites-available/tunnel-session-* 2>&1", $output2, $return2);
        if ($return === 0 && $return2 === 0) {
            exec("ls /etc/nginx/sites-available/tunnel-session-* 2>/dev/null | wc -l", $count_output);
            $results['nginx_removed'] = (int)$count_output[0];
        }
        
        // 3. Reload nginx
        exec("sudo systemctl reload nginx 2>&1", $output, $return);
        if ($return !== 0) {
            $results['errors'][] = "Failed to reload nginx: " . implode("\n", $output);
        }
        
        // 4. Mark all active sessions as closed in database
        $stmt = $DB->prepare("UPDATE ssh_access_sessions SET status = 'closed' WHERE status = 'active'");
        $stmt->execute();
        $results['db_updated'] = $stmt->rowCount();
        
        // 5. Reset firewall tunnel ports
        $stmt = $DB->prepare("UPDATE firewalls SET ssh_tunnel_port = NULL WHERE ssh_tunnel_port IS NOT NULL");
        $stmt->execute();
        
        echo json_encode([
            'success' => true,
            'message' => 'All tunnels reset successfully',
            'results' => $results
        ]);
        
    } catch (Exception $e) {
        error_log("tunnel_management.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Internal server error']);
    }
}

function killTunnel($session_id) {
    global $DB;
    
    if ($session_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid session ID']);
        return;
    }
    
    try {
        // Get session details
        $stmt = $DB->prepare("SELECT tunnel_port, firewall_id FROM ssh_access_sessions WHERE id = ?");
        $stmt->execute([$session_id]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$session) {
            echo json_encode(['success' => false, 'error' => 'Session not found']);
            return;
        }
        
        $results = [
            'ssh_killed' => false,
            'nginx_removed' => false,
            'db_updated' => false
        ];
        
        // 1. Kill SSH tunnel
        $port = (int)$session['tunnel_port'];
        exec("ps aux | grep 'ssh.*-L 0.0.0.0:$port' | grep -v grep | awk '{print $2}'", $pids);
        foreach ($pids as $pid) {
            exec("sudo kill -9 " . escapeshellarg($pid));
            $results['ssh_killed'] = true;
        }
        
        // 2. Remove nginx config
        exec("sudo /usr/bin/php /var/www/opnsense/scripts/manage_nginx_tunnel_proxy.php remove $session_id 2>&1");
        $results['nginx_removed'] = true;
        
        // 3. Update database
        $stmt = $DB->prepare("UPDATE ssh_access_sessions SET status = 'closed' WHERE id = ?");
        $stmt->execute([$session_id]);
        $results['db_updated'] = true;
        
        // 4. Clear firewall tunnel port if this was the active tunnel
        $stmt = $DB->prepare("UPDATE firewalls SET ssh_tunnel_port = NULL WHERE id = ? AND ssh_tunnel_port = ?");
        $stmt->execute([$session['firewall_id'], $port]);
        
        // Reload nginx
        exec("sudo systemctl reload nginx 2>&1");
        
        echo json_encode([
            'success' => true,
            'message' => 'Tunnel killed successfully',
            'results' => $results
        ]);
        
    } catch (Exception $e) {
        error_log("tunnel_management.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Internal server error']);
    }
}

function cleanupZombieTunnels() {
    global $DB;
    
    try {
        $results = [
            'zombies_killed' => 0,
            'orphan_configs_removed' => 0
        ];
        
        // Get all closed sessions from database
        $stmt = $DB->query("SELECT id, tunnel_port FROM ssh_access_sessions WHERE status = 'closed'");
        $closed_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get running SSH tunnels
        exec("ps aux | grep 'ssh.*-L 0.0.0.0:' | grep -v grep", $ps_output);
        $running_ports = [];
        foreach ($ps_output as $line) {
            if (preg_match('/-L 0.0.0.0:(\d+):/', $line, $matches)) {
                preg_match('/^\S+\s+(\d+)/', $line, $pid_match);
                $running_ports[(int)$matches[1]] = (int)$pid_match[1];
            }
        }
        
        // Kill tunnels for closed sessions
        foreach ($closed_sessions as $session) {
            $port = (int)$session['tunnel_port'];
            if (isset($running_ports[$port])) {
                $pid = $running_ports[$port];
                exec("sudo kill -9 $pid");
                $results['zombies_killed']++;
            }
        }
        
        // Get all nginx configs and check if session exists
        exec("ls /etc/nginx/sites-enabled/tunnel-session-* 2>/dev/null", $nginx_files);
        foreach ($nginx_files as $file) {
            if (preg_match('/tunnel-session-(\d+)$/', $file, $matches)) {
                $session_id = (int)$matches[1];
                $stmt = $DB->prepare("SELECT status FROM ssh_access_sessions WHERE id = ?");
                $stmt->execute([$session_id]);
                $session = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Remove if session doesn't exist or is closed
                if (!$session || $session['status'] === 'closed') {
                    exec("sudo /usr/bin/php /var/www/opnsense/scripts/manage_nginx_tunnel_proxy.php remove $session_id 2>&1");
                    $results['orphan_configs_removed']++;
                }
            }
        }
        
        // Reload nginx if any configs were removed
        if ($results['orphan_configs_removed'] > 0) {
            exec("sudo systemctl reload nginx 2>&1");
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Zombie tunnels cleaned up',
            'results' => $results
        ]);
        
    } catch (Exception $e) {
        error_log("tunnel_management.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Internal server error']);
    }
}
