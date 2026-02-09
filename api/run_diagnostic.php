<?php
/**
 * Run Diagnostic Tools API
 * Executes network diagnostic commands via SSH
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';

header('Content-Type: application/json');

requireLogin();

if (!csrf_verify($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

$firewall_id = (int)($input['firewall_id'] ?? 0);
$tool = $input['tool'] ?? '';
$params = $input['params'] ?? [];

if (!$firewall_id || !$tool) {
    die(json_encode(['success' => false, 'error' => 'Missing firewall_id or tool']));
}

// Get firewall details
$stmt = $DB->prepare("SELECT hostname, ip_address, wan_ip, api_key, api_secret FROM firewalls WHERE id = ?");
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch();

if (!$firewall) {
    die(json_encode(['success' => false, 'error' => 'Firewall not found']));
}

// SSH key path
$key_path = "/var/www/opnsense/keys/id_firewall_{$firewall_id}";

if (!file_exists($key_path)) {
    die(json_encode(['success' => false, 'error' => 'SSH key not found']));
}

// Use WAN IP for SSH connection, fallback to ip_address, then hostname
$ssh_host = $firewall['wan_ip'] ?: ($firewall['ip_address'] ?: $firewall['hostname']);

// Build command based on tool
$command = '';

switch ($tool) {
    case 'ping':
        $target = escapeshellarg($params['target'] ?? '');
        $count = (int)($params['count'] ?? 4);
        $command = "ping -c {$count} {$target}";
        break;
        
    case 'traceroute':
        $target = escapeshellarg($params['target'] ?? '');
        $hops = (int)($params['hops'] ?? 30);
        $command = "traceroute -m {$hops} {$target}";
        break;
        
    case 'dns':
        $target = escapeshellarg($params['target'] ?? '');
        $type = escapeshellarg($params['type'] ?? 'A');
        $command = "drill {$target} {$type}";
        break;
        
    case 'port':
        $host = escapeshellarg($params['host'] ?? '');
        $port = (int)($params['port'] ?? 80);
        $command = "nc -z -v -w5 {$host} {$port} 2>&1 || echo 'Port closed or unreachable'";
        break;
        
    case 'log':
        $file = $params['file'] ?? '/var/log/system.log';
        // Whitelist log files for security
        $allowed_logs = [
            '/var/log/filter.log',
            '/var/log/system.log',
            '/var/log/lighttpd.log',
            '/var/log/dhcpd.log'
        ];
        if (!in_array($file, $allowed_logs)) {
            die(json_encode(['success' => false, 'error' => 'Invalid log file']));
        }
        $lines = (int)($params['lines'] ?? 50);
        $command = "tail -n {$lines} {$file}";
        break;
        
    case 'tcpdump':
        $iface = $params['interface'] ?? 'any';
        // Whitelist interfaces for security
        $allowed_ifaces = ['any', 'igc0', 'igc1', 'igc2', 'igc3', 'wg0'];
        if (!in_array($iface, $allowed_ifaces)) {
            $iface = 'any';
        }
        $count = (int)($params['count'] ?? 20);
        $filter = $params['filter'] ?? '';
        
        if ($filter) {
            // Basic filter validation (prevent command injection)
            if (preg_match('/[;&|`$]/', $filter)) {
                die(json_encode(['success' => false, 'error' => 'Invalid filter characters']));
            }
            $command = "tcpdump -i {$iface} -c {$count} -n {$filter} 2>&1";
        } else {
            $command = "tcpdump -i {$iface} -c {$count} -n 2>&1";
        }
        break;
        
    default:
        die(json_encode(['success' => false, 'error' => 'Unknown tool']));
}

// Execute command via SSH with connection cleanup
$ssh_command = sprintf(
    'ssh -i %s -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1 root@%s "%s; exit" 2>&1',
    escapeshellarg($key_path),
    escapeshellarg($ssh_host),
    $command
);

$output = shell_exec($ssh_command);

if ($output === null || $output === false) {
    die(json_encode(['success' => false, 'error' => 'Command execution failed']));
}

// Clean up output
$output = trim($output);

// Remove SSH warning messages
$output = preg_replace('/Warning: Permanently added.*\n?/', '', $output);

echo json_encode([
    'success' => true,
    'output' => $output,
    'command' => $command
]);
