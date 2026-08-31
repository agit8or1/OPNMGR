<?php
require_once __DIR__ . '/../inc/bootstrap.php';

require_once __DIR__ . '/../inc/logging.php';
require_once __DIR__ . '/../inc/agent_commands.php';
require_permission('update.install');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Handle both JSON and form data
if (!$input) {
    $input = $_POST;
}

$firewall_id = (int)($input['firewall_id'] ?? 0);
$update_type = trim($input['update_type'] ?? 'full'); // New: support for update type
$csrf_token = $input['csrf'] ?? '';

// Verify CSRF token
if (!csrf_verify($csrf_token)) {
    if (isset($_POST['action'])) {
        // Form submission - redirect back with error
        header('Location: /firewalls.php?error=' . urlencode('Invalid CSRF token'));
        exit;
    } else {
        // JSON request
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
}

if (!$firewall_id) {
    if (isset($_POST['action'])) {
        header('Location: /firewalls.php?error=' . urlencode('Invalid firewall ID'));
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid firewall ID']);
        exit;
    }
}

try {
    // Validate update type
    $allowed_update_types = ['full', 'firmware', 'packages'];
    if (!in_array($update_type, $allowed_update_types, true)) {
        $update_type = 'full';
    }

    $stmt = db()->prepare('SELECT hostname, status, updates_available FROM firewalls WHERE id = ?');
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$firewall) {
        echo json_encode(['success' => false, 'message' => 'Firewall not found']);
        exit;
    }

    // Don't queue a second update while one is already outstanding. Without
    // this, an operator who sees no feedback clicks again - which is exactly
    // what happened before this endpoint reported anything useful.
    $pending = db()->prepare(
        "SELECT id, status, created_at FROM firewall_commands
          WHERE firewall_id = ? AND action = 'install_updates'
            AND status IN ('pending','sent')
          ORDER BY id DESC LIMIT 1"
    );
    $pending->execute([$firewall_id]);
    if ($existing = $pending->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode([
            'success'    => true,
            'already'    => true,
            'command_id' => (int)$existing['id'],
            'message'    => sprintf(
                'An update is already queued for %s (command #%d, %s since %s). '
                . 'Watch its progress in the command history rather than queueing another.',
                $firewall['hostname'], (int)$existing['id'], $existing['status'], $existing['created_at']
            ),
        ]);
        exit;
    }

    // Queue a tracked command instead of setting a fire-and-forget flag.
    //
    // The previous implementation set firewalls.update_requested = 1 and
    // returned success. agent_checkin.php then cleared that flag the moment it
    // read it - before the agent had done anything - and optimistically set
    // updates_available = 0 and reboot_required = 1. So the request left no
    // record anywhere, the agent ran it with nohup and never reported a result,
    // and the UI had nothing to show. The update worked; there was simply no
    // evidence of it, which is indistinguishable from failure.
    $built = build_structured_command('install_updates', []);
    if (!$built['ok']) {
        echo json_encode(['success' => false, 'message' => $built['error']]);
        exit;
    }

    $queued = queue_firewall_command(
        $firewall_id,
        $built['command'],
        sprintf('OPNsense update (%s) requested from the firewall list', $update_type),
        [
            'is_raw'     => false,
            'action'     => 'install_updates',
            'parameters' => ['update_type' => $update_type],
            'risk'       => $built['risk'],
        ]
    );

    if (!$queued['ok']) {
        echo json_encode(['success' => false, 'message' => $queued['error']]);
        exit;
    }

    db()->prepare('UPDATE firewalls SET last_update_attempt_at = NOW(), last_update_result = "dispatched" WHERE id = ?')
        ->execute([$firewall_id]);

    log_info('firewall', "OPNsense update queued for {$firewall['hostname']} (type: {$update_type}, command #{$queued['command_id']})",
        $_SESSION['user_id'] ?? null, $firewall_id, [
            'action'     => 'update_queued',
            'admin_user' => $_SESSION['username'] ?? 'unknown',
            'update_type'=> $update_type,
            'command_id' => $queued['command_id'],
        ]);

    echo json_encode([
        'success'    => true,
        'command_id' => $queued['command_id'],
        'message'    => sprintf(
            'Update queued for %s as command #%d. The agent picks it up on its next check-in '
            . '(usually within 2 minutes) and the result appears in the command history.',
            $queued['hostname'], $queued['command_id']
        ),
    ]);

} catch (Exception $e) {
    log_error('firewall', "Failed to queue update for firewall ID $firewall_id: " . $e->getMessage(),
        $_SESSION['user_id'] ?? null, $firewall_id);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}

// Note: two curl-based helper functions (triggerOPNsenseUpdate, triggerAgentUpdate)
// were removed here in 3.19.0. Neither was ever called; updates go through the
// tracked command queue above.
