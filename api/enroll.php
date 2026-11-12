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

require_once __DIR__ . '/../inc/enrollment_script.php';

if (!enrollment_token_format_ok($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
    exit;
}

try {
    // Claim the token before doing anything with it. The conditional UPDATE is
    // the single-use guarantee: it also refuses a token another path already
    // marked used (this endpoint used to check only the expiry, and deleted
    // the row after the work, so two concurrent runs could both enroll).
    db()->beginTransaction();
    if (!enrollment_token_claim($token, trim((string)($data['hardware_id'] ?? '')) ?: null)) {
        db()->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
        exit;
    }

    // Check if firewall already exists
    $stmt = db()->prepare("SELECT id FROM firewalls WHERE hostname = ?");
    $stmt->execute([$hostname]);
    $existingFirewall = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingFirewall) {
        // A token enrols a new firewall; it never rewrites one that exists.
        // Matching by hostname let anyone holding a token repoint an enrolled
        // firewall's addresses (and this token) at a host of their choosing.
        db()->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This firewall is already enrolled. Use Reset Agent on its page in OPNManager to re-enrol it.']);
        exit;
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
    
    // Record which firewall the token was redeemed for. The agent's first
    // check-in may bind its hardware_id only while this is fresh
    // (agent_first_claim_allowed, 3.80.1).
    db()->prepare('UPDATE enrollment_tokens SET firewall_id = ? WHERE token = ?')
        ->execute([(int)$firewall_id, $token]);
    // The installer's hardware_id is what the first check-in must present
    // (agent_first_claim_allowed()), so pin it on the firewall now.
    $enrollHw = trim((string)($data['hardware_id'] ?? ''));
    if ($enrollHw !== '') {
        db()->prepare('UPDATE firewalls SET hardware_id = ? WHERE id = ? AND (hardware_id IS NULL OR hardware_id = "")')
            ->execute([$enrollHw, (int)$firewall_id]);
    }

    // The token was claimed above; committing keeps the claim. A failure
    // before this point rolls back and leaves the token usable.
    db()->commit();

    echo json_encode([
        'success' => true, 
        'message' => 'Enrollment successful',
        'firewall_id' => intval($firewall_id)
    ]);
    
} catch (Exception $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    error_log("Enrollment error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
