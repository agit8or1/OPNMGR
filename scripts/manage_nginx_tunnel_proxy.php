#!/usr/bin/env php
<?php
/**
 * Dynamic Nginx Tunnel Proxy Manager
 * 
 * Creates/removes nginx HTTPS proxy configs for SSH tunnel sessions
 * Each session gets:
 *   - HTTPS port (even): 8100, 8102, 8104, etc. (nginx with SSL)
 *   - HTTP port (odd): 8101, 8103, 8105, etc. (SSH tunnel)
 * 
 * Usage:
 *   php manage_nginx_tunnel_proxy.php create <session_id>
 *   php manage_nginx_tunnel_proxy.php remove <session_id>
 *   php manage_nginx_tunnel_proxy.php cleanup
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';

// Use the db() connection from bootstrap
$pdo = db();

// SSL certificate paths - check both possible locations
$ssl_paths = [
    '/etc/letsencrypt/live/opn.agit8or.net/fullchain.pem',
    '/var/log/opnmgr/config/live/opn.agit8or.net/fullchain.pem'
];

$ssl_cert = '';
$ssl_key = '';

foreach ($ssl_paths as $path) {
    $key_path = str_replace('fullchain.pem', 'privkey.pem', $path);
    if (file_exists($path) && file_exists($key_path)) {
        $ssl_cert = $path;
        $ssl_key = $key_path;
        break;
    }
}

if (empty($ssl_cert)) {
    error_log("ERROR: SSL certificates not found in any expected location!");
    error_log("Checked paths: " . implode(', ', $ssl_paths));
    exit(1);
}

error_log("Using SSL certificates: $ssl_cert");

function create_nginx_config($session_id, $https_port, $http_port) {
    global $ssl_cert, $ssl_key;
    
    $config = <<<EOF
# HTTPS proxy for SSH tunnel session {$session_id}
# Auto-generated - do not edit manually
server {
    listen {$https_port} ssl http2;
    server_name opn.agit8or.net;
    
    # SSL configuration
    ssl_certificate {$ssl_cert};
    ssl_certificate_key {$ssl_key};
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers on;
    
    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    
    # Proxy to local SSH tunnel
    location / {
        proxy_pass http://127.0.0.1:{$http_port};
        proxy_http_version 1.1;
        
        # Decompress gzipped responses so sub_filter can work
        gunzip on;
        proxy_set_header Accept-Encoding "";
        
        # Forward headers
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header Referer "";
        
        # WebSocket support
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        
        # Timeouts
        proxy_connect_timeout 60s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
        
        # Disable buffering
        proxy_buffering off;
        proxy_request_buffering off;
        
        # Cookie isolation DISABLED - it breaks firewall login
        # The path/domain rewriting prevents cookies from being sent back to the firewall
        # TODO: Find a better solution that doesn't break authentication
        # proxy_cookie_path / /fw/;
        # proxy_cookie_domain opn.agit8or.net fw-{$session_id}.opn.agit8or.net;
        
        # Inject "Tunnel Mode" badge via JavaScript
        sub_filter '</head>' '<script>document.addEventListener("DOMContentLoaded",function(){var e=document.createElement("div");e.innerHTML="🔒 Tunnel Mode";e.style.cssText="position:fixed;top:10px;right:10px;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white;padding:12px 20px;border-radius:25px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;font-size:14px;font-weight:600;box-shadow:0 4px 15px rgba(0,0,0,0.3);z-index:999999;display:flex;align-items:center;gap:8px;backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,0.2);animation:slideIn 0.3s ease-out";var t=document.createElement("style");t.textContent="@keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}";document.head.appendChild(t);document.body.appendChild(e)});</script></head>';
        sub_filter_once on;
    }
}
EOF;

    $config_file = "/etc/nginx/sites-available/tunnel-session-{$session_id}";
    $symlink = "/etc/nginx/sites-enabled/tunnel-session-{$session_id}";
    
    // Write config file
    if (file_put_contents($config_file, $config) === false) {
        error_log("Failed to write nginx config for session {$session_id}");
        return false;
    }
    
    // Create symlink
    if (!file_exists($symlink)) {
        if (!symlink($config_file, $symlink)) {
            error_log("Failed to create symlink for session {$session_id}");
            return false;
        }
    }
    
    // Test nginx config
    exec('sudo nginx -t 2>&1', $output, $return_code);
    if ($return_code !== 0) {
        error_log("Nginx config test failed for session {$session_id}: " . implode("\n", $output));
        // Cleanup bad config
        unlink($symlink);
        unlink($config_file);
        return false;
    }
    
    // Reload nginx
    exec('sudo systemctl reload nginx 2>&1', $output, $return_code);
    if ($return_code !== 0) {
        error_log("Failed to reload nginx for session {$session_id}: " . implode("\n", $output));
        return false;
    }
    
    error_log("Created nginx proxy for session {$session_id}: HTTPS:{$https_port} -> HTTP:{$http_port}");
    return true;
}

function remove_nginx_config($session_id) {
    $config_file = "/etc/nginx/sites-available/tunnel-session-{$session_id}";
    $symlink = "/etc/nginx/sites-enabled/tunnel-session-{$session_id}";
    
    $removed = false;
    
    // Remove symlink
    if (file_exists($symlink)) {
        unlink($symlink);
        $removed = true;
    }
    
    // Remove config file
    if (file_exists($config_file)) {
        unlink($config_file);
        $removed = true;
    }
    
    if ($removed) {
        // Reload nginx
        exec('sudo systemctl reload nginx 2>&1', $output, $return_code);
        if ($return_code !== 0) {
            error_log("Failed to reload nginx after removing session {$session_id}: " . implode("\n", $output));
            return false;
        }
        
        error_log("Removed nginx proxy for session {$session_id}");
    }
    
    return true;
}

function cleanup_orphaned_configs($pdo) {
    // Get all active session IDs
    $stmt = $pdo->query("SELECT id FROM ssh_access_sessions WHERE status = 'active'");
    $active_sessions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Scan for nginx configs
    $files = glob('/etc/nginx/sites-available/tunnel-session-*');
    $cleaned = 0;
    
    foreach ($files as $file) {
        if (preg_match('/tunnel-session-(\d+)$/', $file, $matches)) {
            $session_id = $matches[1];
            
            // If session is not active, remove config
            if (!in_array($session_id, $active_sessions)) {
                remove_nginx_config($session_id);
                $cleaned++;
            }
        }
    }
    
    if ($cleaned > 0) {
        error_log("Cleaned up {$cleaned} orphaned nginx tunnel configs");
    }
    
    return $cleaned;
}

// Main execution
if ($argc < 2) {
    echo "Usage: {$argv[0]} create|remove|cleanup [session_id]\n";
    exit(1);
}

$action = $argv[1];

switch ($action) {
    case 'create':
        if ($argc < 2) {
            echo "Usage: {$argv[0]} create <session_id>\n";
            exit(1);
        }
        
        $session_id = intval($argv[2]);
        
        // Get session details from database
        $stmt = $pdo->prepare("SELECT tunnel_port FROM ssh_access_sessions WHERE id = ?");
        $stmt->execute([$session_id]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$session) {
            echo "Error: Session {$session_id} not found\n";
            exit(1);
        }
        
        $http_port = $session['tunnel_port']; // Odd number (8101, 8103, etc.)
        $https_port = $http_port - 1; // Even number (8100, 8102, etc.)
        
        if (create_nginx_config($session_id, $https_port, $http_port)) {
            echo "Created nginx proxy: https://opn.agit8or.net:{$https_port} -> http://127.0.0.1:{$http_port}\n";
            exit(0);
        } else {
            echo "Failed to create nginx proxy for session {$session_id}\n";
            exit(1);
        }
        break;
        
    case 'remove':
        if ($argc < 3) {
            echo "Usage: {$argv[0]} remove <session_id>\n";
            exit(1);
        }
        
        $session_id = intval($argv[2]);
        
        if (remove_nginx_config($session_id)) {
            echo "Removed nginx proxy for session {$session_id}\n";
            exit(0);
        } else {
            echo "Failed to remove nginx proxy for session {$session_id}\n";
            exit(1);
        }
        break;
        
    case 'cleanup':
        $cleaned = cleanup_orphaned_configs($pdo);
        echo "Cleaned up {$cleaned} orphaned nginx configs\n";
        exit(0);
        break;
        
    default:
        echo "Unknown action: {$action}\n";
        echo "Usage: {$argv[0]} create|remove|cleanup [session_id]\n";
        exit(1);
}
