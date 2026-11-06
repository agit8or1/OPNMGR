<?php
/**
 * VPN topology data for the map, table and findings.
 *
 * Read-only: builds the model from stored configuration snapshots and agent
 * telemetry. It contacts no firewall. Scoped to the caller's customers.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/vpn/vpn.php';

requireLogin();
require_permission('vpn.view');
header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    $live = vpn_topology_live(false);
    $findings = vpn_diagnose($live['topology'], $live['devices']);
    $scoped = vpn_scope_filter($live['topology'], $findings, vpn_allowed_firewall_ids());
    echo json_encode(['success' => true] + vpn_payload($scoped['topology'], $scoped['findings'], $scoped['hidden_findings']),
        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('OPNMGR vpn_topology: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not build the topology.']);
}
