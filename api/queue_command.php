<?php
/**
 * Queue a RAW SHELL command for a firewall to execute on next check-in.
 *
 * This is the privileged "Advanced / Raw Command" path. Prefer
 * api/queue_action.php, which exposes the structured operation catalogue with
 * validated parameters; this endpoint exists because an MSP administrator
 * genuinely needs an escape hatch, not as the normal way to do things.
 *
 * Requirements enforced here:
 *   - administrator role (configurable via raw_command_admin_only)
 *   - the capability can be switched off entirely (raw_command_enabled)
 *   - valid CSRF token
 *   - explicit confirmation flag from the caller
 *   - the command, the user, the source IP, the target firewall and the
 *     timestamp are all written to the audit log before the agent ever sees it
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/agent_commands.php';

header('Content-Type: application/json');

// Authentication: require logged-in admin user
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Validate CSRF token (from JSON body or header)
$input_raw = file_get_contents('php://input');
$input_check = json_decode($input_raw, true);
$csrf = $input_check['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!csrf_verify($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$input = json_decode($input_raw, true);
$firewall_id = (int)($input['firewall_id'] ?? 0);
$command = trim($input['command'] ?? '');
$description = trim($input['description'] ?? '');
$confirmed = !empty($input['confirm_raw']);

// The capability can be disabled fleet-wide without disabling structured
// operations. Checked after authentication so it cannot be probed anonymously.
if (!raw_commands_enabled()) {
    audit_log('command.raw', [
        'success'     => false,
        'firewall_id' => $firewall_id ?: null,
        'message'     => 'Raw command rejected: the raw shell capability is disabled',
    ]);
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Raw shell commands are disabled. Use the structured operations instead.',
    ]);
    exit;
}

if (!$firewall_id || !$command) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing firewall_id or command']);
    exit;
}

// Explicit acknowledgement that this is the advanced, unvalidated path. The UI
// sets this from its confirmation dialog; a stray API call without it fails.
if (!$confirmed) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Raw shell execution requires explicit confirmation (confirm_raw).',
        'hint'    => 'Structured operations are available at api/queue_action.php and do not require this.',
    ]);
    exit;
}

try {
    // queue_firewall_command() verifies the firewall exists, records the acting
    // user / source IP / timestamp against the row, and writes the audit entry
    // including the command text.
    $queued = queue_firewall_command(
        $firewall_id,
        $command,
        $description !== '' ? $description : 'Raw shell command',
        ['is_raw' => true, 'risk' => 'CRITICAL']
    );

    if (!$queued['ok']) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => $queued['error']]);
        exit;
    }

    echo json_encode([
        'success'    => true,
        'message'    => "Command queued for firewall {$queued['hostname']}",
        'command_id' => $queued['command_id'],
        'raw'        => true,
    ]);

} catch (Exception $e) {
    error_log("queue_command.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
?>