<?php
/**
 * On-Demand Speedtest Trigger API
 * Queues a speedtest command for the agent to execute via iperf3
 * Results are stored in bandwidth_tests when agent reports back
 *
 * Usage: POST /api/trigger_speedtest.php?firewall_id=48
 */
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');

// Verify the user is logged in
if (!isset($_SESSION)) {
}
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// CSRF validation
$csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!csrf_verify($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

try {
    $firewall_id = (int)($_GET['firewall_id'] ?? 0);

    if (!$firewall_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing firewall_id']);
        exit;
    }

    // Verify firewall exists and is online
    $verify_stmt = db()->prepare('SELECT id, hostname, status, last_checkin FROM firewalls WHERE id = ?');
    $verify_stmt->execute([$firewall_id]);
    $firewall = $verify_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$firewall) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Firewall not found']);
        exit;
    }

    if ($firewall['status'] !== 'online') {
        echo json_encode(['success' => false, 'error' => 'Firewall is not online']);
        exit;
    }

    // Check if there's already a pending/sent speedtest command (prevent spam)
    $existing = db()->prepare("SELECT id FROM firewall_commands WHERE firewall_id = ? AND (command = 'run_speedtest' OR command LIKE 'run_speedtest %') AND status IN ('pending', 'sent') AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    $existing->execute([$firewall_id]);
    if ($existing->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Speedtest already queued, please wait for it to complete']);
        exit;
    }

    // Queue the speedtest command for the agent
    // Parameters (servers, streams, duration) come from inc/speedtest.php and
    // reach only agents that understand them.
    require_once __DIR__ . '/../inc/speedtest.php';
    require_once __DIR__ . '/../inc/platform/platform.php';
    $stmt = db()->prepare("INSERT INTO firewall_commands (firewall_id, command, description, command_type, status, queued_by_user_id, queued_by_username) VALUES (?, ?, 'On-demand bandwidth test via iperf3', 'speedtest', 'pending', ?, ?)");
    $stmt->execute([$firewall_id, speedtest_command_for(platform_firewall((int)$firewall_id) ?? []),
                    $_SESSION['user_id'] ?? null, $_SESSION['username'] ?? null]);

    echo json_encode([
        'success' => true,
        'message' => 'Speedtest queued - agent will run iperf3 test on next check-in (~2 min)',
        'firewall_id' => $firewall_id,
        'hostname' => $firewall['hostname']
    ]);

} catch (Exception $e) {
    error_log("trigger_speedtest.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
?>
