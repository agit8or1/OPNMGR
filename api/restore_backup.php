<?php
/**
 * Restore Backup API.
 *
 * Validates, takes a pre-restore snapshot, and queues an agent-authenticated
 * restore. See inc/config_restore.php for why each step exists.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/config_restore.php';

header('Content-Type: application/json');

require_permission('backup.restore');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

if (!csrf_verify($input['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF verification failed']);
    exit;
}

$backupId = (int)($input['backup_id'] ?? 0);
if ($backupId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing backup ID']);
    exit;
}

// A dry run lets the UI show what would happen, and surfaces validation
// failures before anybody types a confirmation.
if (!empty($input['validate_only'])) {
    $check = restore_validate($backupId);
    echo json_encode([
        'success'  => $check['ok'],
        'message'  => $check['ok'] ? 'Backup is valid and the firewall is reachable' : $check['error'],
        'firewall' => $check['ok'] ? $check['firewall']['hostname'] : null,
        'confirm_with' => $check['ok'] ? $check['firewall']['hostname'] : null,
    ]);
    exit;
}

$result = restore_start(
    $backupId,
    (string)($input['confirm'] ?? ''),
    (string)($input['reason'] ?? '')
);

if (!$result['ok']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $result['error']]);
    exit;
}

echo json_encode([
    'success'    => true,
    'restore_id' => $result['restore_id'],
    'message'    => 'Pre-restore snapshot and restore queued. '
                  . 'Success is confirmed only once the agent checks in again.',
]);
