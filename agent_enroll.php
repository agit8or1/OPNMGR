<?php
/**
 * Agent Enrollment Endpoint
 *
 * Accepts enrollment requests from OPNManager Agent plugin.
 * The enrollment key contains server_url and a one-time token that maps
 * to a pending firewall entry.
 */

require_once __DIR__ . '/inc/bootstrap_agent.php';
require_once __DIR__ . '/inc/logging.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit;
}

$token = trim($input['token'] ?? '');
$hardware_id = trim($input['hardware_id'] ?? '');
$hostname = trim($input['hostname'] ?? '');
$agent_version = trim($input['agent_version'] ?? '');

// Validate required fields
if (empty($token) || empty($hardware_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields: token and hardware_id']);
    exit;
}

try {
    // Look up the enrollment token
    $stmt = db()->prepare('
        SELECT id, firewall_id, expires_at, used_at, used
        FROM enrollment_tokens
        WHERE token = ?
    ');
    $stmt->execute([$token]);
    $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$enrollment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Invalid enrollment token']);
        exit;
    }

    // Check if token is already used
    if ($enrollment['used'] || $enrollment['used_at']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Enrollment token has already been used']);
        exit;
    }

    // Check if token is expired
    if (strtotime($enrollment['expires_at']) < time()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Enrollment token has expired']);
        exit;
    }

    $firewall_id = $enrollment['firewall_id'];

    // Update the firewall with the hardware_id and mark it as enrolled
    $stmt = db()->prepare('
        UPDATE firewalls
        SET hardware_id = ?,
            hostname = COALESCE(NULLIF(?, ""), hostname),
            agent_version = ?,
            status = "online",
            last_checkin = NOW(),
            enrolled_at = NOW()
        WHERE id = ?
    ');
    $stmt->execute([$hardware_id, $hostname, $agent_version, $firewall_id]);

    // Mark the enrollment token as used
    $stmt = db()->prepare('
        UPDATE enrollment_tokens
        SET used = 1,
            used_at = NOW(),
            hardware_id = ?
        WHERE id = ?
    ');
    $stmt->execute([$hardware_id, $enrollment['id']]);

    // Log the successful enrollment
    log_info('enrollment', "Firewall enrolled successfully via token", null, $firewall_id, [
        'hardware_id' => $hardware_id,
        'hostname' => $hostname,
        'agent_version' => $agent_version
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Enrollment successful',
        'firewall_id' => $firewall_id
    ]);

} catch (Exception $e) {
    error_log("Enrollment error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
