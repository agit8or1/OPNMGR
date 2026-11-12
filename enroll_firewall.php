<?php
require_once __DIR__ . '/inc/bootstrap_agent.php';

// Handle script download requests. This branch used to build its own
// installer, pasting the unvalidated token and the client's Host header into
// shell; every installer endpoint now renders the one validated script.
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    require_once __DIR__ . '/inc/enrollment_script.php';
    enrollment_serve_installer();
}

header('Content-Type: application/json');

// Get the JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit;
}

$hostname = trim($input['hostname'] ?? '');
$hardware_id = trim($input['hardware_id'] ?? '');
$ip_address = trim($input['ip_address'] ?? '');
$wan_ip = trim($input['wan_ip'] ?? '');
$token = trim($input['token'] ?? '');

// Validate inputs
if (empty($hostname) || empty($ip_address) || empty($token)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Validate IP address
if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
    echo json_encode(['success' => false, 'message' => 'Invalid IP address']);
    exit;
}

// Claim the enrollment token: right format, live, and not used before. The
// conditional UPDATE is the claim, so concurrent requests cannot both pass
// (this used to SELECT here and mark the token used only after the work).
require_once __DIR__ . '/inc/enrollment_script.php';
if (!enrollment_token_claim($token, $hardware_id !== '' ? $hardware_id : null)) {
    echo json_encode(['success' => false, 'message' => 'Invalid or expired enrollment token']);
    exit;
}

try {
    // Check if firewall already exists by hardware_id (preferred) or hostname/ip
    $existing = null;
    
    if (!empty($hardware_id)) {
        $stmt = db()->prepare('SELECT id FROM firewalls WHERE hardware_id = ?');
        $stmt->execute([$hardware_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$existing) {
        $stmt = db()->prepare('SELECT id FROM firewalls WHERE hostname = ? OR ip_address = ?');
        $stmt->execute([$hostname, $ip_address]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($existing) {
        // Never rewrite an enrolled firewall from here: anyone holding a token
        // could repoint its address and hardware_id (matched by hostname, IP or
        // the non-secret hardware_id) and lock its real agent out.
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This firewall is already enrolled. Use Reset Agent on its page in OPNManager to re-enrol it.']);
        exit;
    } else {
        // Add new firewall
        $customer_name = 'AGIT8OR'; // Default customer name
        $stmt = db()->prepare('INSERT INTO firewalls (hostname, hardware_id, ip_address, wan_ip, customer_name, status, last_checkin) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$hostname, $hardware_id, $ip_address, $wan_ip, $customer_name, 'online']);
        $new_id = (int)db()->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => 'Firewall enrolled successfully',
            'firewall_id' => $new_id
        ]);
    }

} catch (Exception $e) {
    error_log("enroll_firewall.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
?>
