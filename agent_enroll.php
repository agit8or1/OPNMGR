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

// One token enrols one agent. The token row is locked and claimed with a
// conditional UPDATE before the firewall is touched, all in one transaction, so
// two requests carrying the same token cannot both bind a hardware_id; if the
// firewall update fails, the claim is rolled back and the token stays usable.
$pdo = db();
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('
        SELECT id, firewall_id, used_at, used, (expires_at > NOW()) AS live
        FROM enrollment_tokens
        WHERE token = ?
        FOR UPDATE
    ');
    $stmt->execute([$token]);
    $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);

    $refuse = function (int $status, string $message) use ($pdo) {
        $pdo->rollBack();
        http_response_code($status);
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    };

    if (!$enrollment) {
        $refuse(404, 'Invalid enrollment token');
    }
    if ($enrollment['used'] || $enrollment['used_at']) {
        $refuse(400, 'Enrollment token has already been used');
    }
    if (!(int)$enrollment['live']) {
        $refuse(400, 'Enrollment token has expired');
    }

    $claim = $pdo->prepare('
        UPDATE enrollment_tokens
        SET used = 1,
            used_at = NOW(),
            hardware_id = ?
        WHERE id = ? AND used = 0 AND used_at IS NULL AND expires_at > NOW()
    ');
    $claim->execute([$hardware_id, $enrollment['id']]);
    if ($claim->rowCount() !== 1) {
        $refuse(400, 'Enrollment token has already been used');
    }

    $firewall_id = $enrollment['firewall_id'];

    // Update the firewall with the hardware_id and mark it as enrolled
    $stmt = $pdo->prepare('
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

    $pdo->commit();

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

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Enrollment error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
