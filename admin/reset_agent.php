<?php
/**
 * Manual Agent Reset Interface
 *
 * Web interface for manually triggering agent resets for stale firewalls
 */

// Session and authentication
session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/logging.php';

// Require authentication
requireLogin();

$success_message = '';
$error_message = '';
$reset_output = '';

// Handle reset request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['firewall_id'])) {
    $firewall_id = (int)$_POST['firewall_id'];
    $user_id = $_SESSION['user_id'] ?? null;

    // Get firewall info
    $stmt = $DB->prepare("SELECT hostname, wan_ip FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch();

    if ($firewall) {
        // Log the manual reset attempt
        log_info('admin', "Manual agent reset triggered for firewall $firewall_id by user $user_id", $user_id, $firewall_id);

        // Run the auto-reset script for this specific firewall
        $command = "/usr/bin/php /var/www/opnsense/scripts/auto_reset_stale_agents.php --force-firewall-id=$firewall_id --verbose 2>&1";
        $output = [];
        exec($command, $output, $return_code);

        $reset_output = implode("\n", $output);

        if ($return_code === 0) {
            $success_message = "Agent reset initiated for {$firewall['hostname']}. Check the output below for details.";
        } else {
            $error_message = "Agent reset completed with warnings. Check the output below.";
        }
    } else {
        $error_message = "Firewall not found.";
    }
}

// Get list of all firewalls with their status
$sql = "SELECT id, hostname, wan_ip, status, last_checkin, agent_version,
               TIMESTAMPDIFF(HOUR, last_checkin, NOW()) as hours_since_checkin
        FROM firewalls
        ORDER BY
            CASE
                WHEN last_checkin < DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1
                WHEN last_checkin < DATE_SUB(NOW(), INTERVAL 2 HOUR) THEN 2
                ELSE 3
            END,
            last_checkin ASC";
$stmt = $DB->query($sql);
$firewalls = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual Agent Reset - OPNManager</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .stale-critical { background-color: #f8d7da; }
        .stale-warning { background-color: #fff3cd; }
        .status-online { color: #28a745; }
        .status-offline { color: #dc3545; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../inc/nav.php'; ?>

    <div class="container mt-4">
        <h2><i class="fas fa-sync-alt"></i> Manual Agent Reset</h2>
        <p class="text-muted">Manually trigger agent resets for firewalls that haven't checked in recently.</p>

        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-warning alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($reset_output): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <strong>Reset Output</strong>
                </div>
                <div class="card-body">
                    <pre class="mb-0" style="max-height: 400px; overflow-y: auto;"><?= htmlspecialchars($reset_output) ?></pre>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <strong>Firewall Status</strong>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Hostname</th>
                                <th>WAN IP</th>
                                <th>Status</th>
                                <th>Last Check-In</th>
                                <th>Hours Stale</th>
                                <th>Agent Version</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($firewalls as $fw): ?>
                                <?php
                                    $row_class = '';
                                    if ($fw['hours_since_checkin'] >= 24) {
                                        $row_class = 'stale-critical';
                                    } elseif ($fw['hours_since_checkin'] >= 2) {
                                        $row_class = 'stale-warning';
                                    }

                                    $status_class = $fw['status'] === 'online' ? 'status-online' : 'status-offline';
                                ?>
                                <tr class="<?= $row_class ?>">
                                    <td><?= htmlspecialchars($fw['id']) ?></td>
                                    <td><?= htmlspecialchars($fw['hostname']) ?></td>
                                    <td><?= htmlspecialchars($fw['wan_ip'] ?: 'N/A') ?></td>
                                    <td class="<?= $status_class ?>">
                                        <i class="fas fa-circle"></i> <?= htmlspecialchars($fw['status']) ?>
                                    </td>
                                    <td><?= htmlspecialchars($fw['last_checkin']) ?></td>
                                    <td>
                                        <?php if ($fw['hours_since_checkin'] >= 24): ?>
                                            <span class="badge bg-danger"><?= $fw['hours_since_checkin'] ?>h</span>
                                        <?php elseif ($fw['hours_since_checkin'] >= 2): ?>
                                            <span class="badge bg-warning"><?= $fw['hours_since_checkin'] ?>h</span>
                                        <?php else: ?>
                                            <span class="badge bg-success"><?= $fw['hours_since_checkin'] ?>h</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($fw['agent_version'] ?: 'Unknown') ?></td>
                                    <td>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Reset agent for <?= htmlspecialchars($fw['hostname']) ?>?');">
                                            <input type="hidden" name="firewall_id" value="<?= $fw['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-warning">
                                                <i class="fas fa-redo"></i> Reset
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">
                <strong>About Auto-Reset</strong>
            </div>
            <div class="card-body">
                <p>The automatic agent reset system monitors all firewalls and performs the following actions:</p>
                <ul>
                    <li><strong>Every hour:</strong> Checks for agents that haven't checked in for 2+ hours</li>
                    <li><strong>Marks offline:</strong> Firewalls with no check-in for 24+ hours are marked offline</li>
                    <li><strong>Queues reset:</strong> Emergency reset commands are queued for stale agents</li>
                    <li><strong>SSH reset:</strong> Attempts to SSH into the firewall and restart the agent</li>
                </ul>
                <p class="mb-0">
                    <strong>Manual Reset:</strong> Use the "Reset" button above to immediately trigger an agent reset for a specific firewall.
                    This will attempt SSH-based reset and queue an emergency command.
                </p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
