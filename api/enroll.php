<?php
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Handle both JSON and form data
$data = [];
if (!empty($_POST)) {
    $data = $_POST;
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $data = $input ?: [];
}

$token = trim($data['token'] ?? '');
$hostname = trim($data['hostname'] ?? '');
$wan_ip = trim($data['wan_ip'] ?? '');
$lan_ip = trim($data['lan_ip'] ?? '');
$ipv6_address = trim($data['ipv6_address'] ?? '');
$opnsense_version = trim($data['opnsense_version'] ?? '');

if (empty($token) || empty($hostname)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    // Verify token is valid
    $stmt = db()->prepare("SELECT * FROM enrollment_tokens WHERE token = ? AND expires_at > NOW()");
    $stmt->execute([$token]);
    $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tokenData) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
        exit;
    }
    
    // Check if firewall already exists
    $stmt = db()->prepare("SELECT id FROM firewalls WHERE hostname = ?");
    $stmt->execute([$hostname]);
    $existingFirewall = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingFirewall) {
        $firewall_id = $existingFirewall['id'];
        
        // Update existing firewall
        $stmt = db()->prepare("UPDATE firewalls SET wan_ip = ?, ipv6_address = ?, version = ?, last_checkin = NOW() WHERE id = ?");
        $stmt->execute([$wan_ip, $ipv6_address, $opnsense_version, $firewall_id]);
    } else {
        // Create new firewall
        $stmt = db()->prepare("INSERT INTO firewalls (hostname, ip_address, wan_ip, ipv6_address, version, enrolled_at, last_checkin) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([$hostname, $lan_ip, $wan_ip, $ipv6_address, $opnsense_version]);
        $firewall_id = db()->lastInsertId();
    }
    
    // Check if agent record exists
    $stmt = db()->prepare("SELECT id FROM firewall_agents WHERE firewall_id = ?");
    $stmt->execute([$firewall_id]);
    $existingAgent = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingAgent) {
        // Update existing agent
        $stmt = db()->prepare("UPDATE firewall_agents SET wan_ip = ?, lan_ip = ?, ipv6_address = ?, opnsense_version = ?, last_checkin = NOW(), status = 'online' WHERE firewall_id = ?");
        $stmt->execute([$wan_ip, $lan_ip, $ipv6_address, $opnsense_version, $firewall_id]);
    } else {
        // Create new agent record
        $stmt = db()->prepare("INSERT INTO firewall_agents (firewall_id, agent_version, wan_ip, lan_ip, ipv6_address, opnsense_version, last_checkin, status) VALUES (?, '1.0.7', ?, ?, ?, ?, NOW(), 'online')");
        $stmt->execute([$firewall_id, $wan_ip, $lan_ip, $ipv6_address, $opnsense_version]);
    }
    
    // Mark token as used by deleting it
    $stmt = db()->prepare("DELETE FROM enrollment_tokens WHERE token = ?");
    $stmt->execute([$token]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Enrollment successful',
        'firewall_id' => intval($firewall_id)
    ]);
    
} catch (Exception $e) {
    error_log("Enrollment error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
