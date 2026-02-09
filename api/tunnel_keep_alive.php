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

// Handle empty input for GET requests
if (!$input) {
    $input = [];
}

// Get firewall ID from input or try to identify by IP
$firewall_id = (int)($input['firewall_id'] ?? 0);
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

$action = trim($input['action'] ?? 'keep_alive');

// Validate inputs
if (!$firewall_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => "Missing firewall_id and couldn't identify by IP: $client_ip"]);
    exit;
}

try {
    // Verify firewall exists and is tunnel-enabled
    $stmt = $DB->prepare('SELECT id, tunnel_active, agent_version FROM firewalls WHERE id = ?');
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$firewall) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Firewall not found']);
        exit;
    }

    if (!$firewall['tunnel_active']) {
        echo json_encode(['success' => false, 'message' => 'Tunnel not active']);
        exit;
    }

    // Update last keep-alive time
    $stmt = $DB->prepare('UPDATE firewalls SET tunnel_established = NOW() WHERE id = ?');
    $stmt->execute([$firewall_id]);

    // Check if agent needs to be updated (if agent_version is old)
    $response = [
        'success' => true,
        'message' => 'Keep-alive received',
        'timestamp' => date('c')
    ];
    
    // If agent version is old (like 2.0.9), return failure to force restart
    if (isset($firewall['agent_version']) && version_compare($firewall['agent_version'], '2.1.0', '<')) {
        $response = [
            'success' => false,
            'message' => 'Agent version outdated, restart required',
            'agent_restart_required' => true,
            'restart_command' => 'pkill -f tunnel_agent; fetch -o /tmp/new_agent.sh https://opn.agit8or.net/download/tunnel_agent.sh && chmod +x /tmp/new_agent.sh && /tmp/new_agent.sh &'
        ];
    }

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    error_log("tunnel_keep_alive.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
?>