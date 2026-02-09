<?php
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/logging.php';
requireLogin();
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Handle both JSON and form data
if (!$input) {
    $input = $_POST;
}

$firewall_id = (int)($input['firewall_id'] ?? 0);
$update_type = trim($input['update_type'] ?? 'full'); // New: support for update type
$csrf_token = $input['csrf'] ?? '';

// Verify CSRF token
if (!csrf_verify($csrf_token)) {
    if (isset($_POST['action'])) {
        // Form submission - redirect back with error
        header('Location: /firewalls.php?error=' . urlencode('Invalid CSRF token'));
        exit;
    } else {
        // JSON request
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
}

if (!$firewall_id) {
    if (isset($_POST['action'])) {
        header('Location: /firewalls.php?error=' . urlencode('Invalid firewall ID'));
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid firewall ID']);
        exit;
    }
}

try {
    // Validate update type
    $allowed_update_types = ['full', 'firmware', 'packages'];
    if (!in_array($update_type, $allowed_update_types)) {
        $update_type = 'full';
    }
    
    // Get firewall details
    $stmt = $DB->prepare("SELECT hostname, ip_address, wan_ip, api_key, api_secret, updates_available FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch();
    
    if (!$firewall) {
        echo json_encode(['success' => false, 'message' => 'Firewall not found']);
        exit;
    }
    
    // Remove the updates_available check - allow manual updates even if no updates detected
    // This gives administrators flexibility to force updates when needed
    
    // Determine firewall IP address and port to use
    $firewall_ip = $firewall['wan_ip'] ?: $firewall['ip_address'];
    $firewall_port = 8443; // Default OPNsense port
    
    // Check if we have external hostname and port configured
    $stmt = $DB->prepare("SELECT external_hostname, external_port FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $external_config = $stmt->fetch();
    
    if ($external_config && $external_config['external_hostname']) {
        $firewall_ip = $external_config['external_hostname'];
        $firewall_port = $external_config['external_port'] ?: 8443;
    }
    
    if (!$firewall_ip) {
        echo json_encode(['success' => false, 'message' => 'No IP address available for firewall']);
        exit;
    }
    
    // Flag the firewall for update - the standalone updater will handle it
    // This ensures updates work even if the main agent fails
    $stmt = $DB->prepare("UPDATE firewalls SET 
        update_requested = 1,
        update_requested_at = NOW(),
        update_type = ?,
        status = 'update_pending'
        WHERE id = ?");
    
    $stmt->execute([$update_type, $firewall_id]);
    
    // Log the action
    log_info('firewall', "Update requested for firewall {$firewall['hostname']} via web interface (type: {$update_type})", 
        $_SESSION['user_id'] ?? null, $firewall_id, [
            'action' => 'update_requested',
            'admin_user' => $_SESSION['username'] ?? 'unknown',
            'update_type' => $update_type,
            'method' => 'standalone_updater'
        ]);
    
    echo json_encode([
        'success' => true,
        'message' => "Update request queued (type: {$update_type}). The standalone updater will process this within 1 minute.",
        'update_type' => $update_type
    ]);
    
    // If this was a form submission, redirect back
    if (isset($_POST['action'])) {
        header('Location: /firewalls.php?success=' . urlencode('Update request queued successfully'));
        exit;
    }

} catch (Exception $e) {
    log_error('firewall', "Failed to initiate update for firewall ID $firewall_id: " . $e->getMessage(),
        $_SESSION['user_id'] ?? null, $firewall_id);
    
    if (isset($_POST['action'])) {
        header('Location: /firewalls.php?error=' . urlencode('Failed to initiate update'));
        exit;
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Internal server error'
        ]);
    }
}

/**
 * Trigger update using OPNsense API
 */
function triggerOPNsenseUpdate($firewall_ip, $api_key, $api_secret) {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://$firewall_ip:8443/api/core/firmware/status",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERPWD => "$api_key:$api_secret",
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        return ['success' => false, 'message' => "Connection failed: $curl_error"];
    }
    
    if ($http_code !== 200) {
        return ['success' => false, 'message' => "API call failed with HTTP $http_code"];
    }
    
    // If status check succeeded, trigger the update
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://$firewall_ip:8443/api/core/firmware/update",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERPWD => "$api_key:$api_secret",
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    
    $update_response = curl_exec($ch);
    $update_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($update_http_code === 200) {
        return ['success' => true, 'message' => 'Update command sent via OPNsense API'];
    } else {
        return ['success' => false, 'message' => "Update API call failed with HTTP $update_http_code"];
    }
}

/**
 * Trigger update via our agent
 */
function triggerAgentUpdate($firewall_ip, $firewall_id) {
    $update_command = [
        'action' => 'update_system',
        'firewall_id' => $firewall_id,
        'timestamp' => time(),
        'reboot_after' => true
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://$firewall_ip/opnsense_agent_update.php",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($update_command),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        return ['success' => false, 'message' => "Agent connection failed: $curl_error"];
    }
    
    if ($http_code === 200) {
        return ['success' => true, 'message' => 'Update command sent to firewall agent'];
    } else {
        return ['success' => false, 'message' => "Agent communication failed with HTTP $http_code"];
    }
}
?>