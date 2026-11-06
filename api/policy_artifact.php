<?php
/**
 * Serve a policy artifact (the full target configuration) to the agent
 * applying it.
 *
 * Authenticated as the agent, plus a single-use token issued when the apply
 * was queued, and bound to the firewall that token was issued for. The
 * artifact is never stored: it is re-rendered from the approved base backup
 * and served only if its sha256 equals the approved one, so the bytes the
 * device installs are exactly the bytes whose diff was approved. The device
 * checks the same sha256 again before installing.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/agent_auth.php';
require_once __DIR__ . '/../inc/policy/policy.php';

$deny = function (int $code, string $msg) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $deny(405, 'Method not allowed');
}

$input = $_POST ?: agent_request_input();
$firewall = authenticateAgentRequest(is_array($input) ? $input : []);
$firewallId = (int)$firewall['id'];
$targetId = (int)($input['target_id'] ?? 0);
$token = (string)($input['token'] ?? '');

if ($targetId <= 0 || strlen($token) !== 64 || !ctype_xdigit($token)) {
    $deny(400, 'Invalid request');
}

try {
    $t = policy_target_get($targetId);
    if (!$t || (int)$t['firewall_id'] !== $firewallId || $t['status'] !== 'applying'
        || $t['fetch_token'] === null || !hash_equals((string)$t['fetch_token'], $token)) {
        audit_log_agent('policy.artifact.denied', $firewallId, ['success' => false,
            'message' => 'Rejected policy artifact fetch', 'metadata' => ['target_id' => $targetId]]);
        $deny(403, 'Invalid request');
    }
    if ($t['token_used_at'] !== null) {
        $deny(409, 'This token has already been used');
    }

    $loaded = drift_load_backup_xml((int)$t['base_backup_id']);
    if (!$loaded['ok'] || !hash_equals((string)$t['base_fingerprint'], hash('sha256', $loaded['xml']))) {
        $deny(409, 'The approved base configuration is no longer available');
    }
    $fw = platform_firewall($firewallId);
    $c = policy_context($fw, (int)$t['revision_id']);
    $plan = $c['ok'] ? policy_plan($loaded['xml'], $c['ctx']) : null;
    if ($plan === null || $plan['xml'] === null || !hash_equals((string)$t['artifact_sha256'], (string)$plan['artifact_sha256'])
        || !hash_equals((string)$t['approved_plan_hash'], (string)$plan['plan_hash'])) {
        audit_log_agent('policy.artifact.mismatch', $firewallId, ['success' => false,
            'message' => 'Re-rendered artifact does not match the approved plan; not served',
            'metadata' => ['target_id' => $targetId]]);
        $deny(409, 'The artifact no longer matches the approved plan');
    }

    // Burn the token before sending a byte.
    db()->prepare('UPDATE policy_deployment_targets SET token_used_at = NOW(), fetch_token = NULL WHERE id = ? AND fetch_token = ?')
        ->execute([$targetId, $token]);
    audit_log_agent('policy.artifact.fetched', $firewallId, ['message' => 'Agent fetched the policy artifact',
        'metadata' => ['target_id' => $targetId, 'artifact_sha256' => $plan['artifact_sha256']]]);

    header('Content-Type: application/xml');
    header('Content-Length: ' . strlen($plan['xml']));
    echo $plan['xml'];
} catch (Throwable $e) {
    error_log('policy_artifact.php: ' . $e->getMessage());
    $deny(500, 'Internal server error');
}
