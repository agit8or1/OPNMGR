<?php
/**
 * Reboot Firewall API
 * Queues a reboot command for the specified firewall via agent
 */
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

// Accept JSON or form data
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$csrf_token = $input['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!csrf_verify($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$firewall_id = (int)($input['firewall_id'] ?? $_GET['id'] ?? 0);

if ($firewall_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid firewall ID']);
    exit;
}

try {
    // Verify firewall exists
    $stmt = db()->prepare("SELECT hostname FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$firewall) {
        echo json_encode(['success' => false, 'message' => 'Firewall not found']);
        exit;
    }

    // Check if a reboot command is already queued
    $stmt = db()->prepare("SELECT id FROM firewall_commands WHERE firewall_id = ? AND command LIKE '%shutdown -r%' AND status IN ('pending', 'sent') LIMIT 1");
    $stmt->execute([$firewall_id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Reboot already queued for this firewall']);
        exit;
    }

    // Queue reboot command (shutdown -r in 1 minute to allow response)
    $reboot_command = '/sbin/shutdown -r +1 "Reboot initiated from OPNManager"';

    $stmt = db()->prepare("
        INSERT INTO firewall_commands (firewall_id, command, description, status, created_at)
        VALUES (?, ?, 'Reboot firewall', 'pending', NOW())
    ");
    $stmt->execute([$firewall_id, $reboot_command]);

    $command_id = db()->lastInsertId();

    // Clear reboot_required flag since we're rebooting
    $stmt = db()->prepare("UPDATE firewalls SET reboot_required = 0 WHERE id = ?");
    $stmt->execute([$firewall_id]);

    log_info('firewall', "Reboot queued for {$firewall['hostname']} via web interface",
        $_SESSION['user_id'] ?? null, $firewall_id, [
            'action' => 'reboot_requested',
            'command_id' => $command_id
        ]);

    echo json_encode([
        'success' => true,
        'command_id' => $command_id,
        'message' => 'Reboot queued. Firewall will reboot within ~3 minutes.'
    ]);

} catch (Exception $e) {
    error_log("reboot_firewall error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
