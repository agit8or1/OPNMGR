<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/logging.php';
requireLogin();
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$firewall_id = (int)($input['firewall_id'] ?? 0);
$csrf_token = $input['csrf'] ?? '';

// Verify CSRF token
if (!csrf_verify($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

if (!$firewall_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid firewall ID']);
    exit;
}

try {
    // Get firewall details
    $stmt = db()->prepare("SELECT hostname FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch();

    if (!$firewall) {
        echo json_encode(['success' => false, 'message' => 'Firewall not found']);
        exit;
    }

    // Force a fresh update check on the next agent check-in
    // by nullifying last_update_check (the 5-hour timer check in agent_checkin.php)
    $stmt = db()->prepare("UPDATE firewalls SET last_update_check = NULL WHERE id = ?");
    $stmt->execute([$firewall_id]);

    log_info('firewall', "Manual update check triggered for firewall {$firewall['hostname']}",
        $_SESSION['user_id'] ?? null, $firewall_id, [
            'action' => 'check_updates_triggered'
        ]);

    echo json_encode([
        'success' => true,
        'message' => 'Update check will run on next agent check-in (within ~2 minutes)'
    ]);

} catch (Exception $e) {
    log_error('firewall', "Failed to trigger update check for firewall ID $firewall_id: " . $e->getMessage(),
        $_SESSION['user_id'] ?? null, $firewall_id);

    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}
?>
