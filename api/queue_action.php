<?php
/**
 * Queue a structured operation against a firewall.
 *
 * The safe counterpart to api/queue_command.php: the caller sends an action id
 * and typed parameters, and the shell text is built server-side from a
 * template. Nothing the caller sends reaches the command line unescaped, and
 * unknown actions or parameters are rejected rather than passed through.
 *
 *   POST { "firewall_id": 48, "action": "service_restart",
 *          "parameters": { "service": "unbound" }, "csrf_token": "..." }
 *
 * GET returns the catalogue so the UI can render the available operations.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/agent_commands.php';

header('Content-Type: application/json');

requireLogin();

// --- catalogue -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    require_permission('command.view');
    $catalog = [];
    foreach (agent_command_catalog() as $id => $entry) {
        $catalog[$id] = [
            'label'    => $entry['label'],
            'category' => $entry['category'],
            'risk'     => $entry['risk'],
            'params'   => array_map(
                fn($r) => array_intersect_key($r, array_flip(['type', 'required', 'min', 'max', 'values'])),
                $entry['params']
            ),
        ];
    }
    echo json_encode([
        'success'  => true,
        'actions'  => $catalog,
        'services' => agent_service_allowlist(),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true) ?: $_POST;

$csrf = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!csrf_verify($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$firewall_id = (int)($input['firewall_id'] ?? 0);
$action      = trim((string)($input['action'] ?? ''));
$parameters  = $input['parameters'] ?? [];

if (!is_array($parameters)) {
    $parameters = [];
}

if ($firewall_id <= 0 || $action === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing firewall_id or action']);
    exit;
}

$built = build_structured_command($action, $parameters, platform_firewall($firewall_id));
if (!$built['ok']) {
    audit_log('command.action', [
        'success'     => false,
        'firewall_id' => $firewall_id,
        'message'     => 'Rejected structured action: ' . $built['error'],
        'metadata'    => ['action' => $action, 'parameters' => $parameters],
    ]);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $built['error']]);
    exit;
}

// The catalogue says how dangerous an action is; the permission matrix says
// who may run something that dangerous. LOW/MEDIUM need an operator role
// (technician or admin), HIGH/CRITICAL need admin. A readonly user can queue
// nothing. Before 3.80.1 only HIGH/CRITICAL were checked, so a readonly
// account could start, restart and re-run services and the agent.
require_permission(risk_to_capability((string)$built['risk']), [
    'firewall_id' => $firewall_id,
    'object_type' => 'firewall',
    'object_id'   => (string)$firewall_id,
]);

try {
    $queued = queue_firewall_command(
        $firewall_id,
        $built['command'],
        $built['label'],
        [
            'is_raw'     => false,
            'action'     => $action,
            'parameters' => $built['params'],
            'risk'       => $built['risk'],
        ]
    );

    if (!$queued['ok']) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => $queued['error']]);
        exit;
    }

    echo json_encode([
        'success'    => true,
        'message'    => sprintf('%s queued for %s', $built['label'], $queued['hostname']),
        'command_id' => $queued['command_id'],
        'action'     => $action,
        'risk'       => $built['risk'],
    ]);
} catch (Throwable $e) {
    error_log('queue_action.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
