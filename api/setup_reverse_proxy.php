<?php
// Setup reverse proxy for firewall access
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/logging.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Validate CSRF token
if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$firewall_id = (int)($_POST['firewall_id'] ?? 0);

if ($firewall_id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid firewall ID']);
    exit;
}

try {
    // Get firewall details
    $stmt = $DB->prepare("SELECT * FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$firewall) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Firewall not found']);
        exit;
    }
    
    // Get proxy port settings
    $rows = $DB->query('SELECT `name`,`value` FROM settings WHERE name IN ("proxy_port_start", "proxy_port_end")')->fetchAll(PDO::FETCH_KEY_PAIR);
    $proxy_port_start = (int)($rows['proxy_port_start'] ?? 8100);
    $proxy_port_end = (int)($rows['proxy_port_end'] ?? 8199);
    
    // Check if firewall already has a proxy port assigned
    if ($firewall['proxy_port'] && $firewall['proxy_port'] >= $proxy_port_start && $firewall['proxy_port'] <= $proxy_port_end) {
        $proxy_port = $firewall['proxy_port'];
    } else {
        // Find next available port in range
        $used_ports = $DB->query("SELECT proxy_port FROM firewalls WHERE proxy_port IS NOT NULL AND proxy_port != 0")->fetchAll(PDO::FETCH_COLUMN);
        $proxy_port = null;
        
        for ($port = $proxy_port_start; $port <= $proxy_port_end; $port++) {
            if (!in_array($port, $used_ports)) {
                $proxy_port = $port;
                break;
            }
        }
        
        if (!$proxy_port) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'No available ports in range ' . $proxy_port_start . '-' . $proxy_port_end]);
            exit;
        }
    }
    
    $target_url = $firewall['ip_address'] . ':443';
    
    // Create nginx reverse proxy config
    $config_content = "
# Reverse proxy configuration for firewall {$firewall['hostname']}
server {
    listen {$proxy_port} ssl;
    server_name localhost;
    
    ssl_certificate /etc/ssl/certs/ssl-cert-snakeoil.pem;
    ssl_certificate_key /etc/ssl/private/ssl-cert-snakeoil.key;
    
    location / {
        proxy_pass https://{$target_url};
        proxy_ssl_verify off;
        proxy_set_header Host \$host:{$proxy_port};
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        
        # Handle WebSocket connections
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection \"upgrade\";
        
        # Increase buffer sizes for large responses
        proxy_buffer_size 128k;
        proxy_buffers 4 256k;
        proxy_busy_buffers_size 256k;
    }
}
";
    
    $config_file = "/etc/nginx/sites-available/firewall-proxy-{$firewall_id}";
    $link_file = "/etc/nginx/sites-enabled/firewall-proxy-{$firewall_id}";
    
    // Write configuration file using sudo
    $temp_file = "/tmp/firewall-proxy-{$firewall_id}.conf";
    if (file_put_contents($temp_file, $config_content) === false) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to write temporary config']);
        exit;
    }
    
    // Move to nginx directory with sudo
    $result = shell_exec("sudo mv " . escapeshellarg($temp_file) . " " . escapeshellarg($config_file) . " 2>&1");
    if (!file_exists($config_file)) {
        header('Content-Type: application/json');
        error_log("Failed to move nginx config for firewall_id=$firewall_id: " . $result);
        echo json_encode(['success' => false, 'error' => 'Failed to install proxy configuration']);
        exit;
    }
    
    // Create symbolic link to enable site
    if (!file_exists($link_file)) {
        $result = shell_exec("sudo ln -s " . escapeshellarg($config_file) . " " . escapeshellarg($link_file) . " 2>&1");
        if (!file_exists($link_file)) {
            header('Content-Type: application/json');
            error_log("Failed to enable nginx site for firewall_id=$firewall_id: " . $result);
            echo json_encode(['success' => false, 'error' => 'Failed to enable proxy configuration']);
            exit;
        }
    }
    
    // Test nginx configuration
    $test_output = shell_exec('sudo nginx -t 2>&1');
    if (strpos($test_output, 'syntax is ok') === false || strpos($test_output, 'test is successful') === false) {
        // Remove the config if it's invalid
        shell_exec("sudo rm -f " . escapeshellarg($config_file) . " " . escapeshellarg($link_file) . " 2>&1");
        header('Content-Type: application/json');
        error_log("Invalid nginx config for firewall_id=$firewall_id: " . $test_output);
        echo json_encode(['success' => false, 'error' => 'Invalid proxy configuration generated']);
        exit;
    }
    
    // Reload nginx
    $reload_output = shell_exec('sudo systemctl reload nginx 2>&1');
    if ($reload_output && strpos($reload_output, 'Failed') !== false) {
        header('Content-Type: application/json');
        error_log("Failed to reload nginx for firewall_id=$firewall_id: " . $reload_output);
        echo json_encode(['success' => false, 'error' => 'Failed to activate proxy configuration']);
        exit;
    }
    
    // Update database with proxy information
    $stmt = $DB->prepare("UPDATE firewalls SET proxy_port = ?, proxy_enabled = 1 WHERE id = ?");
    $stmt->execute([$proxy_port, $firewall_id]);
    
    // Log the action
    log_action("Proxy Management", "INFO", "Setup reverse proxy for firewall {$firewall['hostname']} on port {$proxy_port}", $firewall['hostname']);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'proxy_port' => $proxy_port,
        'proxy_url' => "https://localhost:{$proxy_port}",
        'message' => 'Reverse proxy configured successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Error setting up reverse proxy: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
}
?>