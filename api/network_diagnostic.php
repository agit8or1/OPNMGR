<?php
/**
 * Network Diagnostics API
 * Runs ping or traceroute commands from firewall.
 *
 * SECURITY (3.80.1): this endpoint used to interpolate `target` into a shell
 * script inside double quotes, validated only by FILTER_VALIDATE_DOMAIN -
 * which, without FILTER_FLAG_HOSTNAME, accepts `$(...)`, `;` and quotes. Any
 * signed-in user, readonly included, could run arbitrary commands as root on
 * a firewall. It now delegates to the structured catalogue (strict host
 * validation, escapeshellarg, platform gate, audit) and requires
 * command.diagnostic.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/agent_commands.php';

require_permission('command.diagnostic');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!csrf_verify($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$firewall_id = (int)($_POST['firewall_id'] ?? 0);
$command_type = (string)($_POST['command_type'] ?? '');
$target = trim((string)($_POST['target'] ?? ''));

if (!$firewall_id || $command_type === '' || $target === '') {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

if (!in_array($command_type, ['ping', 'traceroute'], true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid command type']);
    exit;
}

// Verify firewall exists and is online
$stmt = db()->prepare("SELECT id, hostname, status FROM firewalls WHERE id = ?");
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$firewall) {
    echo json_encode(['success' => false, 'error' => 'Firewall not found']);
    exit;
}

if ($firewall['status'] !== 'online') {
    echo json_encode(['success' => false, 'error' => 'Firewall is not online']);
    exit;
}

try {
    $built = build_structured_command($command_type, ['target' => $target], platform_firewall($firewall_id));
    if (!$built['ok']) {
        echo json_encode(['success' => false, 'error' => $built['error']]);
        exit;
    }

    $description = ucfirst($command_type) . ' diagnostic to ' . $target;
    $queued = queue_firewall_command($firewall_id, $built['command'], $description, [
        'is_raw'     => false,
        'action'     => $command_type,
        'parameters' => $built['params'],
        'risk'       => $built['risk'],
    ]);
    if (!$queued['ok']) {
        echo json_encode(['success' => false, 'error' => $queued['error']]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'command_id' => $queued['command_id'],
        'message' => $description . ' queued for execution'
    ]);
    
} catch (Exception $e) {
    error_log("network_diagnostic error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}