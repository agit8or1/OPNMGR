<?php
require_once __DIR__ . '/inc/bootstrap_agent.php';
require_once __DIR__ . '/inc/enrollment_script.php';

// Handle script download requests. Like enroll_firewall.php, this renders the
// one validated installer instead of building its own from request values.
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    enrollment_serve_installer();
}

// Handle enrollment callback (POST from firewall)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
        exit;
    }
    
    $hostname = $input['hostname'] ?? null;
    $hardware_id = $input['hardware_id'] ?? null;
    $ip_address = $input['ip_address'] ?? null;
    $wan_ip = $input['wan_ip'] ?? null;
    $token = $input['token'] ?? null;
    
    if (!$hostname || !$hardware_id || !$ip_address || !$token) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }
    
    $pdo = db();
    try {
        // Claim the token before writing anything: a conditional UPDATE inside
        // a transaction, so a token enrols exactly one firewall even when two
        // requests carry it at once, and a failed write releases it again.
        $pdo->beginTransaction();
        if (!enrollment_token_claim((string)$token, (string)$hardware_id)) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Invalid or expired enrollment token']);
            exit;
        }
        
        // Check if firewall exists
        $stmt = db()->prepare("SELECT id FROM firewalls WHERE hardware_id = ? OR ip_address = ?");
        $stmt->execute([$hardware_id, $ip_address]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Update existing
            $stmt = db()->prepare("UPDATE firewalls SET hostname = ?, wan_ip = ?, status = 'online', last_checkin = NOW() WHERE id = ?");
            $stmt->execute([$hostname, $wan_ip, $existing['id']]);
            $firewall_id = $existing['id'];
        } else {
            // Create new
            $stmt = db()->prepare("INSERT INTO firewalls (hostname, hardware_id, ip_address, wan_ip, status, customer_name, last_checkin) VALUES (?, ?, ?, ?, 'online', 'AGIT8OR', NOW())");
            $stmt->execute([$hostname, $hardware_id, $ip_address, $wan_ip]);
            $firewall_id = db()->lastInsertId();
        }
        
        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Firewall enrolled successfully',
            'firewall_id' => $firewall_id
        ]);
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("enroll_firewall_new.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Internal server error']);
    }
    exit;
}

// Show usage page if accessed directly
?>
<!DOCTYPE html>
<html>
<head>
    <title>OPNsense Enrollment</title>
</head>
<body>
    <h1>OPNsense Firewall Enrollment</h1>
    <p>This endpoint handles OPNsense firewall enrollment.</p>
    <p>Use: <code>enroll_firewall.php?action=download&token=YOUR_TOKEN</code> to download the enrollment script.</p>
</body>
</html>
