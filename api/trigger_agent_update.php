<?php
/**
 * Trigger a verified agent update for a firewall.
 *
 * The queued command now verifies a signed manifest and the artifact's SHA-256
 * before installing, installs atomically, and rolls back to the previous agent
 * if the new one does not come up. See inc/agent_update.php.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/agent_update.php';
require_once __DIR__ . '/../inc/agent_commands.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Agent updates run as root on the firewall: administrators only.
requireAdmin();

$input = json_decode(file_get_contents('php://input'), true);

// CSRF validation
$csrf_token = $input['csrf'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!csrf_verify($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

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

// Resolve the newest agent package from the signed manifest rather than
// hardcoding a version that drifts out of date.
$target = latest_agent_artifact();
if (!$target['ok']) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => $target['error']]);
    exit;
}

$built = build_verified_agent_update_command($target['artifact'], $target['version']);
if (!$built['ok']) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => $built['error']]);
    exit;
}

$queued = queue_firewall_command(
    $firewall_id,
    $built['command'],
    'Verified agent update to v' . $target['version'],
    [
        'is_raw'     => false,
        'action'     => 'agent_update',
        'parameters' => ['artifact' => $target['artifact'], 'version' => $target['version']],
        'risk'       => 'HIGH',
    ]
);

if (!$queued['ok']) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => $queued['error']]);
    exit;
}

db()->prepare('UPDATE firewall_commands SET is_update_command = 1 WHERE id = ?')
    ->execute([$queued['command_id']]);

echo json_encode([
    'success'    => true,
    'message'    => 'Verified agent update to v' . $target['version'] . ' queued for ' . $firewall['hostname'],
    'command_id' => $queued['command_id'],
    'version'    => $target['version'],
    'artifact'   => $target['artifact'],
]);
