<?php
/**
 * Serve a configuration to an agent performing a restore.
 *
 * Authenticated as the agent (not as an operator's browser session, which the
 * firewall obviously does not have), and additionally gated by a single-use
 * token issued when the restore was queued.
 *
 * The token is burned on first use, so a leaked command line cannot be replayed
 * to pull the configuration again later.
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/agent_auth.php';
require_once __DIR__ . '/../inc/backup_storage.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input    = $_POST ?: agent_request_input();
$firewall = authenticateAgentRequest(is_array($input) ? $input : []);
$firewallId = (int)$firewall['id'];

$restoreId = (int)($input['restore_id'] ?? 0);
$token     = (string)($input['token'] ?? '');

if ($restoreId <= 0 || strlen($token) !== 64 || !ctype_xdigit($token)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid restore request']);
    exit;
}

try {
    $stmt = db()->prepare(
        'SELECT * FROM config_restores WHERE id = ? AND firewall_id = ? AND status = "dispatched"'
    );
    $stmt->execute([$restoreId, $firewallId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    // Constant-time comparison, and the job must belong to the authenticated
    // firewall - a valid token for another firewall's restore is no use here.
    if (!$job || $job['fetch_token'] === null || !hash_equals((string)$job['fetch_token'], $token)) {
        error_log(sprintf('OPNMGR: restore config fetch REJECTED for firewall %d, restore %d', $firewallId, $restoreId));
        audit_log_agent('backup.restore.fetch_denied', $firewallId, [
            'success' => false,
            'message' => 'Rejected restore configuration fetch',
            'metadata' => ['restore_id' => $restoreId],
        ]);
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid restore request']);
        exit;
    }

    if ($job['token_used_at'] !== null) {
        http_response_code(409);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'This restore token has already been used']);
        exit;
    }

    $backupStmt = db()->prepare('SELECT * FROM backups WHERE id = ? AND firewall_id = ?');
    $backupStmt->execute([(int)$job['backup_id'], $firewallId]);
    $backup = $backupStmt->fetch(PDO::FETCH_ASSOC);

    $path = $backup ? resolve_backup_path($backup) : null;
    if ($path === null) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Backup file unavailable']);
        exit;
    }

    // Burn the token before sending a byte.
    db()->prepare('UPDATE config_restores SET token_used_at = NOW(), fetch_token = NULL WHERE id = ?')
        ->execute([$restoreId]);

    audit_log_agent('backup.restore.fetched', $firewallId, [
        'message'  => 'Agent fetched the configuration for restore',
        'metadata' => ['restore_id' => $restoreId, 'backup_id' => (int)$job['backup_id']],
    ]);

    header('Content-Type: application/xml');
    header('Content-Length: ' . filesize($path));
    readfile($path);
} catch (Throwable $e) {
    error_log('download_restore_config.php error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
