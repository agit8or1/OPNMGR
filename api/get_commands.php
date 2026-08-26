<?php
/**
 * Get Commands API
 *
 * Agents poll this endpoint for work queued against them. Commands are always
 * scoped to the authenticated firewall - the firewall_id in the request is only
 * an identity claim, never an authorization decision.
 */
require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/agent_auth.php';

header('Content-Type: application/json');

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$is_post = $_SERVER['REQUEST_METHOD'] === 'POST';
$source  = $is_post ? ($_POST ?: agent_request_input()) : $_GET;

// Centralised agent authentication (identity, hardware_id, API key, signature).
$firewall    = authenticateAgentRequest(is_array($source) ? $source : []);
$firewall_id = (int)$firewall['id'];

// POST is the agent's normal work-fetch path: only commands already dispatched.
// GET is the diagnostic view and shows every status.
$status_filter = $is_post ? 'sent' : null;

$limit = $is_post ? 50 : (int)($_GET['limit'] ?? 10);
$limit = max(1, min(200, $limit)); // clamp; LIMIT is interpolated below

try {
    if ($status_filter !== null) {
        $stmt = db()->prepare(
            "SELECT id, command, command_type, description, status, created_at
               FROM firewall_commands
              WHERE firewall_id = ? AND status = ?
              ORDER BY created_at DESC
              LIMIT {$limit}"
        );
        $stmt->execute([$firewall_id, $status_filter]);
    } else {
        $stmt = db()->prepare(
            "SELECT id, command, command_type, description, status, created_at
               FROM firewall_commands
              WHERE firewall_id = ?
              ORDER BY created_at DESC
              LIMIT {$limit}"
        );
        $stmt->execute([$firewall_id]);
    }

    echo json_encode(['success' => true, 'commands' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (PDOException $e) {
    error_log('get_commands.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
