<?php
/**
 * Command Result API
 *
 * Agents report the outcome of a queued command here.
 *
 * The previous implementation authenticated the agent but then ran
 *   UPDATE firewall_commands SET status=?, result=? WHERE id = ?
 * with no firewall scoping, so any authenticated agent could finalise - and
 * write arbitrary output into - a command belonging to any other firewall.
 * Ownership, terminal-state and status validation now live in
 * inc/command_results.php and are shared with agent_checkin.php.
 */
require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/agent_auth.php';
require_once __DIR__ . '/../inc/command_results.php';
require_once __DIR__ . '/../inc/logging.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = agent_request_input();
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit;
}

$firewall    = authenticateAgentRequest($input);
$firewall_id = (int)$firewall['id'];

$command_id = (int)($input['command_id'] ?? 0);
$result     = (string)($input['result'] ?? '');

if ($command_id <= 0 || $result === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    $accepted = record_agent_command_result(
        $firewall_id,
        $command_id,
        $result,                                   // 'success' | 'failed' | ...
        (string)($input['output'] ?? ''),
        [
            'result_is_base64' => true,
            'error_output'     => (string)($input['error_output'] ?? ''),
        ]
    );

    if (!$accepted['ok']) {
        http_response_code($accepted['status']);
        echo json_encode(['success' => false, 'message' => $accepted['message']]);
        exit;
    }

    $command = $accepted['command'];
    $log_message = sprintf(
        'Command completed for firewall %s: %s - Status: %s',
        $firewall['hostname'] ?? $firewall_id,
        $command['description'] ?? ('#' . $command_id),
        $accepted['normalized_status']
    );
    if ($accepted['result'] !== '') {
        $log_message .= ' - Output: ' . substr($accepted['result'], 0, 200);
    }

    if ($accepted['normalized_status'] === 'failed') {
        log_error('command', $log_message, null, $firewall_id);
    } else {
        log_info('command', $log_message, null, $firewall_id);
    }

    echo json_encode(['success' => true, 'message' => 'Command result updated']);
} catch (Exception $e) {
    error_log('command_result.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
