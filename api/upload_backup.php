<?php
/**
 * Upload Backup API
 *
 * Receives OPNsense configuration backups from authenticated agents.
 *
 * Security note: this endpoint previously wrote the agent-supplied filename
 * straight into /var/www/opnsense/backups, which is inside the document root
 * and matched by the nginx `location ~ \.php$` block. Any caller able to pass
 * agent authentication could therefore upload x.php and execute code as
 * www-data. Backups now land outside the document root under a server-generated
 * name, and the agent-supplied name is only ever used as a database label.
 */
require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/agent_auth.php';
require_once __DIR__ . '/../inc/backup_storage.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Multipart upload: credentials arrive as form fields, not a JSON body.
$firewall    = authenticateAgentRequest($_POST);
$firewall_id = (int)$firewall['id'];

// Queued backup commands have historically used both field names ('backup' in
// the shell-script variant, 'backup_file' in the cp/curl variant). Accept
// either rather than silently discarding half the fleet's uploads.
$uploaded = null;
foreach (['backup_file', 'backup', 'config', 'file'] as $field) {
    if (isset($_FILES[$field]) && is_array($_FILES[$field])) {
        $uploaded = $_FILES[$field];
        break;
    }
}

// Pre-created row this upload belongs to, when the queueing code supplied one.
$backup_id = (int)($_POST['backup_id'] ?? 0);

if ($uploaded === null) {
    record_backup_failure($firewall_id, 'no backup file in request', $backup_id);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No backup file uploaded']);
    exit;
}

if (($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    record_backup_failure($firewall_id, 'upload error code ' . (int)($uploaded['error'] ?? -1), $backup_id);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Upload failed']);
    exit;
}

// Reject anything that is not a plausible OPNsense config before it is stored.
$validation = validate_backup_upload($uploaded['tmp_name'], (int)$uploaded['size']);
if (!$validation['ok']) {
    record_backup_failure($firewall_id, $validation['reason'], $backup_id);
    audit_log_agent('backup.upload.rejected', $firewall_id, [
        'success'  => false,
        'message'  => 'Rejected backup upload: ' . $validation['reason'],
        'metadata' => ['size' => (int)$uploaded['size']],
    ]);
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Backup rejected: ' . $validation['reason']]);
    exit;
}

try {
    // store_firewall_backup() picks the on-disk path itself (outside the
    // document root, server-generated name) so nothing the agent sends can
    // influence where the bytes land or what extension they get.
    $stored = store_firewall_backup($firewall_id, $uploaded['tmp_name'], $uploaded['name'] ?? '', $backup_id);

    audit_log_agent('backup.upload', $firewall_id, [
        'object_type' => 'backup',
        'object_id'   => (string)$stored['backup_id'],
        'message'     => 'Configuration backup received from agent',
        'metadata'    => [
            'size'     => $stored['size'],
            'checksum' => $stored['checksum'],
        ],
    ]);

    echo json_encode([
        'success'  => true,
        'message'  => 'Backup uploaded successfully',
        'checksum' => $stored['checksum'],
        'size'     => $stored['size'],
    ]);
} catch (Throwable $e) {
    error_log('upload_backup.php error: ' . $e->getMessage());
    record_backup_failure($firewall_id, 'server error while storing backup', $backup_id);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to store backup']);
}
