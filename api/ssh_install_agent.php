<?php
/**
 * SSH-Based Agent Installation API
 * Installs agent on a new firewall via SSH credentials
 */
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');

// Verify authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// CSRF validation
if (!csrf_verify($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$host = trim($_POST['host'] ?? '');
$port = (int)($_POST['port'] ?? 22);
$user = trim($_POST['user'] ?? 'root');
$password = $_POST['password'] ?? '';

if (empty($host) || empty($password)) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields (host and password)']);
    exit;
}

// Validate host (basic sanitation)
if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]+[a-zA-Z0-9]$/', $host)) {
    echo json_encode(['success' => false, 'error' => 'Invalid hostname or IP address']);
    exit;
}

// Validate port
if ($port < 1 || $port > 65535) {
    echo json_encode(['success' => false, 'error' => 'Invalid port number']);
    exit;
}

// Check if sshpass is available
$sshpass_check = shell_exec('which sshpass 2>/dev/null');
if (empty(trim($sshpass_check ?? ''))) {
    // Try to install it
    exec('apt-get update && apt-get install -y sshpass 2>&1', $install_output, $install_code);
    if ($install_code !== 0) {
        echo json_encode(['success' => false, 'error' => 'sshpass is not installed and could not be installed automatically']);
        exit;
    }
}

$output_lines = [];
require_once __DIR__ . '/../inc/agent_version.php';
$plugin_download_url = opnmgr_agent_download_url();
if ($plugin_download_url === '') {
    echo json_encode([
        'success' => false,
        'error'   => "This manager's URL is not configured, so the firewall cannot be told "
                   . 'where to download the agent. Set the server_url setting or APP_URL in .env.',
    ]);
    exit;
}
$plugin_install_cmd = 'fetch -o - ' . escapeshellarg($plugin_download_url)
    . ' | env OPNMGR_BASE_URL=' . escapeshellarg(opnmgr_server_url()) . ' sh';

// Build SSH command with timeout
$ssh_options = "-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=15 -o ServerAliveInterval=5 -o ServerAliveCountMax=3";

// Step 1: Test SSH connectivity
$output_lines[] = "Testing SSH connection to {$host}:{$port}...";

$test_cmd = sprintf(
    'timeout 20 sshpass -p %s ssh %s -p %d %s@%s "echo SSH_OK" 2>&1',
    escapeshellarg($password),
    $ssh_options,
    $port,
    escapeshellarg($user),
    escapeshellarg($host)
);

$test_result = shell_exec($test_cmd);

if (strpos($test_result, 'SSH_OK') === false) {
    // Determine error type
    $error_msg = 'SSH connection failed';
    if (strpos($test_result, 'Permission denied') !== false) {
        $error_msg = 'Authentication failed - check username and password';
    } elseif (strpos($test_result, 'Connection refused') !== false) {
        $error_msg = 'Connection refused - SSH may not be enabled or port is wrong';
    } elseif (strpos($test_result, 'Connection timed out') !== false || strpos($test_result, 'timed out') !== false) {
        $error_msg = 'Connection timed out - check if firewall is reachable and SSH port is open';
    } elseif (strpos($test_result, 'No route to host') !== false) {
        $error_msg = 'No route to host - check network connectivity';
    } elseif (strpos($test_result, 'Host key verification failed') !== false) {
        $error_msg = 'Host key verification failed';
    }

    echo json_encode([
        'success' => false,
        'error' => $error_msg,
        'output' => trim($test_result)
    ]);
    exit;
}

$output_lines[] = "✓ SSH connection successful";

// Step 2: Install the plugin
$output_lines[] = "Installing OPNManager Agent plugin...";

$install_cmd = sprintf(
    'timeout 120 sshpass -p %s ssh %s -p %d %s@%s %s 2>&1',
    escapeshellarg($password),
    $ssh_options,
    $port,
    escapeshellarg($user),
    escapeshellarg($host),
    escapeshellarg($plugin_install_cmd)
);

$install_result = shell_exec($install_cmd);
$output_lines[] = $install_result;

// Check for success indicators
if (strpos($install_result, 'Installation complete') !== false) {
    $output_lines[] = "✓ Plugin installed successfully";

    // Try to get the hardware ID
    $hw_id = null;
    $hw_cmd = sprintf(
        'timeout 10 sshpass -p %s ssh %s -p %d %s@%s "cat /usr/local/etc/opnmanager_hardware_id 2>/dev/null || echo NOID" 2>&1',
        escapeshellarg($password),
        $ssh_options,
        $port,
        escapeshellarg($user),
        escapeshellarg($host)
    );
    $hw_result = trim(shell_exec($hw_cmd));
    if ($hw_result && $hw_result !== 'NOID' && strlen($hw_result) === 32) {
        $hw_id = $hw_result;
        $output_lines[] = "Hardware ID: {$hw_id}";
    }

    // Log activity.
    //
    // This wrote to activity_log, a table that does not exist in this schema and
    // appears in no migration, so a successful install ended in a 500 at the
    // last line - the same fatal that repair_agent_ssh.php hit. audit_log is
    // the table this project keeps.
    audit_log('agent.install.ssh', [
        'object_type' => 'firewall',
        'message'     => "SSH plugin installation completed for {$host}",
        'metadata'    => ['host' => $host, 'hardware_id' => $hw_id ?: null],
    ]);

    echo json_encode([
        'success' => true,
        'output' => implode("\n", $output_lines),
        'hardware_id' => $hw_id
    ]);
} else {
    // Check for common error patterns
    $error_detail = 'Installation may have failed or completed with warnings';
    if (strpos($install_result, 'ERROR:') !== false) {
        preg_match('/ERROR: (.+)/', $install_result, $matches);
        if (!empty($matches[1])) {
            $error_detail = $matches[1];
        }
    }

    echo json_encode([
        'success' => false,
        'error' => $error_detail,
        'output' => implode("\n", $output_lines)
    ]);
}
