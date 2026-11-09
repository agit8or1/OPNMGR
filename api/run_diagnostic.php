<?php
/**
 * Run Diagnostic Tools API
 * Executes network diagnostic commands via SSH
 */
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');

// SECURITY (3.80.1): was requireLogin() only, and the remote command was put
// inside a double-quoted local shell string, so `$(...)` in a ping target was
// expanded on THIS server as www-data (escapeshellarg's single quotes are
// literal inside double quotes). www-data can read every firewall's SSH key.
// Now: operator role, strict parameter validation, and the remote command is
// passed to ssh as one escaped argument.
require_permission('command.diagnostic');

// Get POST data (must parse JSON body before CSRF check since token is in body)
$input = json_decode(file_get_contents('php://input'), true);

$csrf_token = $input['csrf'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!csrf_verify($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$firewall_id = (int)($input['firewall_id'] ?? 0);
$tool = $input['tool'] ?? '';
$params = $input['params'] ?? [];

if (!$firewall_id || !$tool) {
    die(json_encode(['success' => false, 'error' => 'Missing firewall_id or tool']));
}

// Get firewall details
$stmt = db()->prepare("SELECT hostname, ip_address, wan_ip, api_key, api_secret FROM firewalls WHERE id = ?");
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch();

if (!$firewall) {
    die(json_encode(['success' => false, 'error' => 'Firewall not found']));
}

// SSH key path
$key_path = "/etc/opnmgr/keys/id_firewall_{$firewall_id}";

if (!file_exists($key_path)) {
    die(json_encode(['success' => false, 'error' => 'SSH key not found']));
}

// Use WAN IP for SSH connection, fallback to ip_address, then hostname
$ssh_host = $firewall['wan_ip'] ?: ($firewall['ip_address'] ?: $firewall['hostname']);


/** IP address or RFC-1123 hostname, nothing else. */
function diag_host(string $v): string {
    $v = trim($v);
    $ok = filter_var($v, FILTER_VALIDATE_IP) !== false
        || (strlen($v) <= 253 && preg_match('/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))*$/', $v) === 1);
    if (!$ok) {
        die(json_encode(['success' => false, 'error' => 'Invalid host or IP address']));
    }
    return $v;
}
function diag_int($v, int $min, int $max, int $default): int {
    $n = is_numeric($v) ? (int)$v : $default;
    return max($min, min($max, $n));
}

// Build command based on tool
$command = '';

switch ($tool) {
    case 'ping':
        $target = escapeshellarg(diag_host((string)($params['target'] ?? '')));
        $count = diag_int($params['count'] ?? 4, 1, 20, 4);
        $command = "ping -c {$count} {$target}";
        break;
        
    case 'traceroute':
        $target = escapeshellarg(diag_host((string)($params['target'] ?? '')));
        $hops = diag_int($params['hops'] ?? 30, 1, 64, 30);
        $command = "traceroute -m {$hops} {$target}";
        break;
        
    case 'dns':
        $target = escapeshellarg(diag_host((string)($params['target'] ?? '')));
        $type = strtoupper((string)($params['type'] ?? 'A'));
        if (!in_array($type, ['A', 'AAAA', 'MX', 'NS', 'TXT', 'CNAME', 'SOA', 'PTR', 'SRV', 'ANY'], true)) {
            die(json_encode(['success' => false, 'error' => 'Invalid record type']));
        }
        $command = "drill {$target} {$type}";
        break;
        
    case 'port':
        $host = escapeshellarg(diag_host((string)($params['host'] ?? '')));
        $port = diag_int($params['port'] ?? 80, 1, 65535, 80);
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
        $lines = diag_int($params['lines'] ?? 50, 1, 1000, 50);
        $command = "tail -n {$lines} {$file}";
        break;
        
    case 'tcpdump':
        $iface = $params['interface'] ?? 'any';
        // Whitelist interfaces for security
        $allowed_ifaces = ['any', 'igc0', 'igc1', 'igc2', 'igc3', 'wg0'];
        if (!in_array($iface, $allowed_ifaces)) {
            $iface = 'any';
        }
        $count = diag_int($params['count'] ?? 20, 1, 500, 20);
        $filter = $params['filter'] ?? '';
        
        if ($filter) {
            // Strict filter validation — only allow safe BPF syntax characters
            if (!preg_match('/^[a-zA-Z0-9 .\-_:\/()]+$/', $filter)) {
                die(json_encode(['success' => false, 'error' => 'Invalid filter characters']));
            }
            $command = "tcpdump -i {$iface} -c {$count} -n " . escapeshellarg($filter) . " 2>&1";
        } else {
            $command = "tcpdump -i {$iface} -c {$count} -n 2>&1";
        }
        break;
        
    default:
        die(json_encode(['success' => false, 'error' => 'Unknown tool']));
}

// Execute command via SSH with connection cleanup
$ssh_command = sprintf(
    'ssh -i %s -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/etc/opnmgr/known_hosts -o UserKnownHostsFile=/dev/null -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=1 root@%s %s 2>&1',
    escapeshellarg($key_path),
    escapeshellarg($ssh_host),
    escapeshellarg($command)
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
