<?php
/**
 * Inbound integration webhook.
 *
 *   POST /api/integration_inbound.php?destination=<id>
 *   X-OPNManager-Timestamp: <unix seconds>
 *   X-OPNManager-Signature: v1=<hex HMAC-SHA256(inbound_secret, timestamp "." raw body)>
 *   X-OPNManager-Nonce:     <unique per request>   (or "event_id" in the body)
 *   {"type":"ticket.acknowledged|ticket.comment|ticket.assigned|ticket.closed|ticket.reopened|ticket.updated",
 *    "external_id":"T-123","comment":"...","assignee":"...","author":"..."}
 *
 * No session: the per-destination inbound secret is the authorisation. Every
 * authentication failure answers the same 401. See docs/INTEGRATIONS.md.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/integrations/inbound.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    exit;
}

// Read at most 64 KiB + 1 so an oversized body is detected without buffering it all.
$raw = (string)file_get_contents('php://input', false, null, 0, 65537);
$res = integration_inbound_handle(
    (int)($_GET['destination'] ?? 0),
    $raw,
    [
        'timestamp' => $_SERVER['HTTP_X_OPNMANAGER_TIMESTAMP'] ?? '',
        'signature' => $_SERVER['HTTP_X_OPNMANAGER_SIGNATURE'] ?? '',
        'nonce'     => $_SERVER['HTTP_X_OPNMANAGER_NONCE'] ?? '',
    ],
    $_SERVER['REMOTE_ADDR'] ?? null
);
http_response_code($res['code']);
echo json_encode($res['body']);
