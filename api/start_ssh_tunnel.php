<?php
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Validate CSRF token
if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$firewall_id = (int)($_POST['firewall_id'] ?? 0);

if ($firewall_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid firewall ID']);
    exit;
}

try {
    // Get firewall details
    $stmt = db()->prepare("SELECT * FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$firewall) {
        echo json_encode(['success' => false, 'error' => 'Firewall not found']);
        exit;
    }
    
    if (!$firewall['proxy_port']) {
        echo json_encode(['success' => false, 'error' => 'Proxy not configured']);
        exit;
    }
    
    // Validate IP addresses before using in shell commands
    $wan_ip_val = $firewall['wan_ip'];
    if (!empty($wan_ip_val) && !filter_var($wan_ip_val, FILTER_VALIDATE_IP)) {
        echo json_encode(['success' => false, 'error' => 'Invalid WAN IP address in database']);
        exit;
    }
    $ip_addr_val = $firewall['ip_address'];
    if (!empty($ip_addr_val) && !filter_var($ip_addr_val, FILTER_VALIDATE_IP)) {
        echo json_encode(['success' => false, 'error' => 'Invalid IP address in database']);
        exit;
    }

    // Check if tunnel is already running
    $tunnel_check = shell_exec("ps aux | grep " . escapeshellarg('ssh.*8103.*' . $wan_ip_val) . " | grep -v grep");
    
    if ($tunnel_check) {
        echo json_encode([
            'success' => true,
            'message' => 'SSH tunnel already active',
            'already_running' => true
        ]);
        exit;
    }
    
    // Start SSH tunnel in background
    // Tunnel from localhost:8103 to firewall:443
    $target_ip = $firewall['wan_ip'] ?: $firewall['ip_address'];
    $tunnel_port = $firewall['proxy_port'] + 1; // Use proxy_port + 1 for SSH tunnel
    
    // Build SSH command
    // ssh -f -N -L 8103:localhost:443 root@firewall_ip
    $ssh_key = '/var/www/.ssh/opnsense_key';
    $ssh_cmd = sprintf(
        'ssh -f -N -i %s -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ServerAliveInterval=60 -L 127.0.0.1:%d:127.0.0.1:443 root@%s 2>&1',
        escapeshellarg($ssh_key),
        $tunnel_port,
        escapeshellarg($target_ip)
    );
    
    // Execute SSH tunnel command
    $output = shell_exec($ssh_cmd);
    
    // Wait a moment and check if tunnel is up
    sleep(1);
    $tunnel_check = shell_exec("ps aux | grep " . escapeshellarg("ssh.*{$tunnel_port}.*{$target_ip}") . " | grep -v grep");
    
    if ($tunnel_check) {
        echo json_encode([
            'success' => true,
            'message' => 'SSH tunnel established successfully',
            'tunnel_port' => $tunnel_port
        ]);
    } else {
        error_log("SSH tunnel failed for firewall_id=$firewall_id target=$target_ip output=" . ($output ?: 'none'));
        echo json_encode([
            'success' => false,
            'error' => 'Failed to establish SSH tunnel'
        ]);
    }

} catch (Exception $e) {
    error_log("SSH tunnel error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
