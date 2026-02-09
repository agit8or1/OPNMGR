<?php
require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json');

// Accept both GET and POST methods
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get data (handle both GET, POST form-encoded and JSON)
$input = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $input = $_GET;
} elseif (isset($_POST['firewall_id'])) {
    $input = $_POST;
} else {
    $input = json_decode(file_get_contents('php://input'), true);
}

// If no input or missing firewall_id, try to identify by IP address
$firewall_id = (int)($input['firewall_id'] ?? 0);
$hardware_id = trim($input['hardware_id'] ?? '');
$client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// If no firewall_id provided, try to find it by WAN IP
if (!$firewall_id && $client_ip !== 'unknown') {
    $stmt = $DB->prepare('SELECT id FROM firewalls WHERE wan_ip = ?');
    $stmt->execute([$client_ip]);
    $firewall = $stmt->fetch();
    if ($firewall) {
        $firewall_id = $firewall['id'];
    }
}

if (!$firewall_id || empty($hardware_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing authentication']);
    exit;
}

// Validate agent identity
$auth_stmt = $DB->prepare('SELECT hardware_id FROM firewalls WHERE id = ?');
$auth_stmt->execute([$firewall_id]);
$auth_fw = $auth_stmt->fetch(PDO::FETCH_ASSOC);
if (!$auth_fw || (
    !empty($auth_fw['hardware_id']) && !hash_equals($auth_fw['hardware_id'], $hardware_id)
)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Authentication failed']);
    exit;
}

$tunnel_port = (int)($input['tunnel_port'] ?? 443);
$auth_token = trim($input['auth_token'] ?? '');
$tunnel_type = trim($input['tunnel_type'] ?? 'request_queue');

// Validate inputs
if (!$firewall_id || !$tunnel_port) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    // Verify firewall exists
    $stmt = $DB->prepare('SELECT id, hostname, ip_address FROM firewalls WHERE id = ?');
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$firewall) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Firewall not found']);
        exit;
    }

    // For SSH tunnels, use fixed port 8102, for others find available port
    $available_port = 8102;
    if ($tunnel_type !== 'ssh') {
        // Find an available port for non-SSH tunnels (starting from 8101)
        $available_port = 8101;
        for ($port = 8101; $port <= 8200; $port++) {
            $stmt = $DB->prepare('SELECT id FROM firewalls WHERE tunnel_port = ? AND tunnel_active = 1 AND id != ?');
            $stmt->execute([$port, $firewall_id]);
            if (!$stmt->fetch()) {
                $available_port = $port;
                break;
            }
        }
    }

    // Update firewall tunnel status
    $stmt = $DB->prepare('UPDATE firewalls SET tunnel_active = 1, tunnel_port = ?, tunnel_established = NOW(), tunnel_client_ip = ?, tunnel_type = ? WHERE id = ?');
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt->execute([$available_port, $client_ip, $tunnel_type, $firewall_id]);

    // Log the tunnel connection attempt
    error_log("Tunnel connection established for firewall {$firewall_id} on port {$available_port} from {$client_ip} (type: {$tunnel_type})");
    
    echo json_encode([
        'success' => true,
        'message' => 'Tunnel connection registered',
        'tunnel_port' => $available_port,
        'tunnel_type' => $tunnel_type,
        'firewall_id' => $firewall_id,
        'client_ip' => $client_ip
    ]);
} catch (Exception $e) {
    error_log("tunnel_connect.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
?>