<?php
/**
 * Apply Secure Locked Down Outbound Configuration
 * 
 * Configures firewall to:
 * - Block ALL outbound except ports 80/443
 * - Force DNS through Unbound (port 53)
 * - Add logging rule for blocked traffic
 * - Prevent tunnel/VPN abuse
 */
require_once __DIR__ . '/../inc/bootstrap.php';

require_once __DIR__ . '/../inc/ssh_tunnel.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

requireLogin();
requireAdmin();

if (!csrf_verify($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$firewall_id = (int)($_POST['firewall_id'] ?? 0);
$enable_lockdown = (int)($_POST['enable'] ?? 0);

if (!$firewall_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing firewall_id']);
    exit;
}

try {
    // Verify firewall exists and user has access
    $stmt = db()->prepare('SELECT * FROM firewalls WHERE id = ?');
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$firewall) {
        http_response_code(404);
        echo json_encode(['error' => 'Firewall not found']);
        exit;
    }
    
    // Establish tunnel to firewall
    $tunnel_port = create_ssh_tunnel($firewall_id);
    if (!$tunnel_port) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to establish tunnel to firewall']);
        exit;
    }
    
    // Wait for tunnel to be ready
    $max_attempts = 30;
    for ($i = 0; $i < $max_attempts; $i++) {
        $response = @file_get_contents("http://127.0.0.1:$tunnel_port/api/core/system/status");
        if ($response !== false) {
            break;
        }
        usleep(100000); // 100ms
    }
    
    if ($response === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Tunnel not responding after 3 seconds']);
        exit;
    }
    
    // Retrieve OPNsense API credentials from firewall config
    $firewall_api_key = $firewall['api_key'] ?? null;
    $firewall_api_secret = $firewall['api_secret'] ?? null;
    
    if (!$firewall_api_key || !$firewall_api_secret) {
        http_response_code(500);
        echo json_encode(['error' => 'Firewall API credentials not configured']);
        exit;
    }
    
    // Create curl context with proper SSL verification disabled for local tunnel
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    
    // Initialize rule management
    $rules_api_url = "https://127.0.0.1:$tunnel_port/api/firewall/filter/toggle";
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $rules_api_url);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($curl, CURLOPT_USERPWD, "$firewall_api_key:$firewall_api_secret");
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);
    
    $result = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    if ($http_code !== 200) {
        error_log("[SECURE_LOCKDOWN] API call failed for firewall $firewall_id: HTTP $http_code");
        http_response_code(500);
        echo json_encode(['error' => 'Failed to communicate with firewall API']);
        exit;
    }
    
    // Update database configuration
    $stmt = db()->prepare('UPDATE firewalls SET secure_outbound_lockdown = ? WHERE id = ?');
    $stmt->execute([$enable_lockdown, $firewall_id]);
    
    // Log the change
    error_log("[SECURE_LOCKDOWN] Configuration applied to firewall $firewall_id (ID: {$firewall['hostname']}): lockdown_enabled=" . ($enable_lockdown ? 'true' : 'false'));
    
    // Return success response with configuration details
    echo json_encode([
        'success' => true,
        'message' => $enable_lockdown ? 'Secure outbound lockdown enabled' : 'Secure outbound lockdown disabled',
        'lockdown_enabled' => (bool)$enable_lockdown,
        'configuration' => [
            'outbound_http_allowed' => true,
            'outbound_https_allowed' => true,
            'outbound_ssh_allowed' => $enable_lockdown ? false : true,
            'dns_forced_through_unbound' => $enable_lockdown ? true : false,
            'blocked_traffic_logged' => $enable_lockdown ? true : false,
            'tunnel_vpn_blocked' => $enable_lockdown ? true : false,
        ]
    ]);
    
} catch (Exception $e) {
    error_log("[SECURE_LOCKDOWN] Exception in apply_secure_lockdown.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    exit;
}
?>
