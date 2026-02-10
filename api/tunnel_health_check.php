<?php
/**
 * Tunnel System Health Check API
 * Returns JSON with system health status before allowing tunnel creation
 */

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');
$response = [
    'healthy' => true,
    'checks' => [],
    'errors' => []
];

// Check 1: SSL Certificates exist and are readable
$ssl_paths = [
    '/etc/letsencrypt/live/opn.agit8or.net/fullchain.pem',
    '/etc/letsencrypt/live/opn.agit8or.net/privkey.pem'
];

$ssl_found = true;
foreach ($ssl_paths as $path) {
    // Use sudo test -r to check if file is readable (privkey needs root)
    exec("sudo test -r " . escapeshellarg($path) . " 2>&1", $output, $returnCode);
    if ($returnCode !== 0) {
        $ssl_found = false;
        $response['errors'][] = "SSL certificate missing or unreadable: $path";
        $response['healthy'] = false;
        break;
    }
}

if ($ssl_found) {
    $response['checks']['ssl_certificates'] = [
        'status' => 'ok',
        'message' => 'SSL certificates found and readable'
    ];
} else {
    $response['checks']['ssl_certificates'] = [
        'status' => 'error',
        'message' => 'SSL certificates not accessible'
    ];
}

// Check 2: Nginx is running
exec('systemctl is-active nginx 2>&1', $output, $returnCode);
$nginx_running = ($returnCode === 0 && trim($output[0]) === 'active');

if ($nginx_running) {
    $response['checks']['nginx'] = [
        'status' => 'ok',
        'message' => 'Nginx is running'
    ];
} else {
    $response['checks']['nginx'] = [
        'status' => 'error',
        'message' => 'Nginx is not running'
    ];
    $response['errors'][] = 'Nginx service is not active';
    $response['healthy'] = false;
}

// Check 3: Nginx can reload (test configuration)
exec('sudo nginx -t 2>&1', $output, $returnCode);
$nginx_config_valid = ($returnCode === 0);

if ($nginx_config_valid) {
    $response['checks']['nginx_config'] = [
        'status' => 'ok',
        'message' => 'Nginx configuration is valid'
    ];
} else {
    $response['checks']['nginx_config'] = [
        'status' => 'error',
        'message' => 'Nginx configuration has errors'
    ];
    $response['errors'][] = 'Nginx configuration test failed: ' . implode(' ', $output);
    $response['healthy'] = false;
}

// Check 4: Tunnel script exists and is executable
$tunnel_script = __DIR__ . '/../scripts/manage_nginx_tunnel_proxy.php';
if (file_exists($tunnel_script) && is_readable($tunnel_script)) {
    $response['checks']['tunnel_script'] = [
        'status' => 'ok',
        'message' => 'Tunnel management script accessible'
    ];
} else {
    $response['checks']['tunnel_script'] = [
        'status' => 'error',
        'message' => 'Tunnel management script not found'
    ];
    $response['errors'][] = "Script not accessible: $tunnel_script";
    $response['healthy'] = false;
}

// Check 5: Available ports (at least one port pair available)
$used_ports = [];
$tunnel_configs = glob('/etc/nginx/sites-enabled/tunnel-session-*');
foreach ($tunnel_configs as $config) {
    if (preg_match('/listen (\d+) ssl/', file_get_contents($config), $matches)) {
        $used_ports[] = (int)$matches[1];
    }
}

$available_ports = 0;
for ($port = 8100; $port <= 8198; $port += 2) {
    if (!in_array($port, $used_ports)) {
        $available_ports++;
    }
}

if ($available_ports > 0) {
    $response['checks']['available_ports'] = [
        'status' => 'ok',
        'message' => "$available_ports port pairs available"
    ];
} else {
    $response['checks']['available_ports'] = [
        'status' => 'warning',
        'message' => 'No available port pairs (all 50 slots in use)'
    ];
    $response['errors'][] = 'All tunnel ports (8100-8198) are in use';
    $response['healthy'] = false;
}

// Return response
echo json_encode($response, JSON_PRETTY_PRINT);
