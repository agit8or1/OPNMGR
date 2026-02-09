<?php
// Remove reverse proxy for firewall access
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
    $stmt = $DB->prepare("SELECT hostname, proxy_port FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$firewall) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Firewall not found']);
        exit;
    }
    
    $proxy_port = $firewall['proxy_port'];
    
    if ($proxy_port) {
        // Remove nginx configuration files
        $config_file = "/etc/nginx/sites-available/firewall-proxy-{$firewall_id}";
        $link_file = "/etc/nginx/sites-enabled/firewall-proxy-{$firewall_id}";
        
        // Remove files using sudo
        shell_exec("sudo rm -f " . escapeshellarg($link_file) . " " . escapeshellarg($config_file) . " 2>&1");
        
        // Reload nginx
        shell_exec('sudo systemctl reload nginx 2>&1');
    }
    
    // Update database
    $stmt = $DB->prepare("UPDATE firewalls SET proxy_port = NULL, proxy_enabled = 0 WHERE id = ?");
    $stmt->execute([$firewall_id]);
    
    // Log the action
    log_action("Removed reverse proxy for firewall {$firewall['hostname']}");
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => 'Reverse proxy removed successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Error removing reverse proxy: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
}
?>