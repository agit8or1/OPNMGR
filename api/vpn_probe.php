<?php
/**
 * Guided VPN diagnostics.
 *
 *   POST  start a bounded probe (vpn.diagnose): validated, rate-limited, queued
 *         through the structured command path
 *   GET   ?run_group=…  collect the results of a run (vpn.view)
 *
 * A probe runs on the firewall, not as a client; the response says so.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/vpn/vpn.php';

requireLogin();
header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    require_permission('vpn.view');
    $g = (string)($_GET['run_group'] ?? '');
    if (!preg_match('/^[0-9a-f]{32}$/', $g)) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Bad run id']); exit; }
    $legs = vpn_probe_sync($g);
    foreach ($legs as $l) {
        if (!scope_can_firewall((int)$l['firewall_id'])) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'No access']); exit; }
    }
    $legs = array_map(fn($l) => array_intersect_key($l, array_flip(['id', 'leg', 'firewall_id', 'hostname', 'method', 'target', 'port', 'source_address',
        'source_interface', 'source_reason', 'status', 'outcome', 'summary', 'result_excerpt', 'created_at', 'completed_at'])), $legs);
    echo json_encode(['success' => true, 'legs' => $legs, 'disclaimer' => VPN_PROBE_DISCLAIMER]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

require_permission('vpn.diagnose');
$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (!csrf_verify($in['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403); echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']); exit;
}
$req = array_intersect_key($in, array_flip(['firewall_id', 'tunnel_id', 'dest', 'method', 'port', 'size', 'both_ends', 'incident_id']));
if (!scope_can_firewall((int)($req['firewall_id'] ?? 0))) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'No access to that firewall']); exit; }

if (!empty($in['preview'])) {
    // Show the source address and checks that WOULD be used; queue nothing.
    $live = vpn_topology_live();
    $devices = [];
    foreach ($live['devices'] as $d) { $devices[$d['id']] = $d; }
    $plan = vpn_probe_plan($req, ['topology' => $live['topology'], 'devices' => $devices]);
    echo json_encode(['success' => $plan['ok'], 'message' => $plan['error'], 'disclaimer' => VPN_PROBE_DISCLAIMER,
        'warnings' => $plan['warnings'], 'policy' => $plan['policy'],
        'legs' => array_map(fn($l) => ['leg' => $l['leg'], 'firewall_id' => $l['firewall_id'], 'hostname' => $l['hostname'], 'method' => $l['params']['method'],
            'target' => $l['params']['target'], 'port' => $l['params']['port'] ?? null, 'source_address' => $l['params']['source'] ?? null,
            'source_interface' => $l['source']['interface'], 'source_reason' => $l['source']['reason']], $plan['legs'])], JSON_UNESCAPED_SLASHES);
    exit;
}
$r = vpn_probe_run($req, ['user_id' => (int)$_SESSION['user_id'], 'username' => (string)($_SESSION['username'] ?? '')]);
if (!$r['ok']) { http_response_code(isset($r['retry_after']) ? 429 : 400); }
if (isset($r['retry_after'])) { header('Retry-After: ' . (int)$r['retry_after']); }
echo json_encode(['success' => $r['ok'], 'message' => $r['error']] + $r, JSON_UNESCAPED_SLASHES);
