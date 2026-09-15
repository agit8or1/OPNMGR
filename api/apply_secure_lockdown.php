<?php
/**
 * Apply or remove the Secure Outbound Lockdown policy on a firewall.
 *
 * Previously this required inc/ssh_tunnel.php - a file that has never existed -
 * so it was fatal on load, and the toggle in firewall_details.php did nothing.
 * The design it sketched (open an SSH tunnel, drive the OPNsense API) needed
 * per-firewall API credentials and a tunnel for every change.
 *
 * It now queues a policy script through the agent instead, which is the path
 * every other firewall change already uses: the agent is authenticated, the
 * command is audited, and it works with the deployed agent with no new
 * credentials and nothing listening on the firewall.
 *
 * The change is not applied here. It is queued, and takes effect on the
 * firewall's next check-in.
 *
 * @since 3.28.0
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/agent_commands.php';
require_once __DIR__ . '/../inc/firewall_policy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!csrf_verify($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Changing a customer's outbound policy is not something a read-only or
// technician account should be able to do from a toggle.
if (function_exists('requireAdmin')) {
    requireAdmin();
}

$firewall_id = (int) ($_POST['firewall_id'] ?? 0);
$enable      = (int) ($_POST['enable'] ?? 0) === 1;

if ($firewall_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing firewall_id']);
    exit;
}

try {
    $stmt = db()->prepare('SELECT id, hostname FROM firewalls WHERE id = ?');
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$firewall) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Firewall not found']);
        exit;
    }

    // Enabling this blocks everything outbound from LAN except web and DNS. It
    // will break mail clients, VPNs, NTP and anything else on a customer
    // network, so it needs a deliberate confirmation rather than a toggle click.
    if ($enable) {
        $expected = 'RESTRICT ' . $firewall['hostname'];
        $typed    = trim((string) ($_POST['confirm'] ?? ''));
        if ($typed !== $expected) {
            http_response_code(428);
            echo json_encode([
                'success'      => false,
                'error'        => 'Confirmation required',
                'confirm_with' => $expected,
                'warning'      => 'This blocks all outbound traffic from LAN except HTTP, HTTPS '
                                . 'and DNS to the firewall. Mail, VPN, NTP and similar will stop '
                                . 'working until the policy is removed.',
            ]);
            exit;
        }
    }

    $script = policy_outbound_lockdown_script($enable);

    $queued = queue_firewall_command(
        $firewall_id,
        $script,
        $enable ? 'Apply secure outbound lockdown' : 'Remove secure outbound lockdown',
        [
            'is_raw'     => true,
            'risk'       => 'CRITICAL',
            'action'     => 'policy.outbound_lockdown',
            'parameters' => ['enable' => $enable],
        ]
    );

    if (!($queued['ok'] ?? false)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $queued['error'] ?? 'Could not queue the policy']);
        exit;
    }

    // Record intent now; the firewall reports the result on its next check-in.
    db()->prepare('UPDATE firewalls SET secure_outbound_lockdown = ? WHERE id = ?')
        ->execute([$enable ? 1 : 0, $firewall_id]);

    echo json_encode([
        'success'    => true,
        'queued'     => true,
        'command_id' => $queued['command_id'] ?? 0,
        'message'    => $enable
            ? 'Outbound lockdown queued. It applies on the next agent check-in.'
            : 'Removal queued. The restriction lifts on the next agent check-in.',
    ]);
} catch (Throwable $e) {
    error_log('apply_secure_lockdown.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
