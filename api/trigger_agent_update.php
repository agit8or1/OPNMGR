<?php
// Trigger agent update for a firewall
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Verify authentication
verify_session();

$input = json_decode(file_get_contents('php://input'), true);
$firewall_id = (int)($input['firewall_id'] ?? 0);

if (!$firewall_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Firewall ID required']);
    exit;
}

// Verify firewall exists
$stmt = db()->prepare('SELECT id, hostname FROM firewalls WHERE id = ?');
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$firewall) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Firewall not found']);
    exit;
}

// Queue the update command
$update_command = 'fetch -o - https://opn.agit8or.net/downloads/plugins/install_opnmanager_agent.sh | sh';

$stmt = db()->prepare('
    INSERT INTO firewall_commands
    (firewall_id, command, command_type, description, is_update_command, status, created_at)
    VALUES (?, ?, ?, ?, 1, "pending", NOW())
');

$stmt->execute([
    $firewall_id,
    $update_command,
    'shell',
    'Agent update to v1.1.7 via ' . ($_SESSION['user_email'] ?? 'system')
]);

echo json_encode([
    'success' => true,
    'message' => 'Agent update queued for ' . $firewall['hostname'],
    'command_id' => db()->lastInsertId()
]);
