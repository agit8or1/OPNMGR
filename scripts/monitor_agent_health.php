<?php
/**
 * Agent Health Monitor
 * Alerts if commands are stuck or firewalls become unreachable
 *
 * Add to crontab (runs every 5 minutes):
 * star-slash-5 * * * * php /var/www/opnsense/scripts/monitor_agent_health.php
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/logging.php';

echo "=== OPNManager Agent Health Monitor ===\n";
echo "Run time: " . date('Y-m-d H:i:s') . "\n\n";

// Check for commands stuck in 'sent' status for >15 minutes
$stmt = db()->query("
    SELECT f.hostname, fc.id, fc.description, fc.sent_at,
           TIMESTAMPDIFF(MINUTE, fc.sent_at, NOW()) as stuck_minutes
    FROM firewall_commands fc
    JOIN firewalls f ON fc.firewall_id = f.id
    WHERE fc.status = 'sent'
    AND fc.sent_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
");

$stuck_commands = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($stuck_commands) > 0) {
    $alert = "ALERT: " . count($stuck_commands) . " commands stuck in 'sent' status:\n\n";

    foreach ($stuck_commands as $cmd) {
        $alert .= "- {$cmd['hostname']}: Command {$cmd['id']} ({$cmd['description']}) stuck for {$cmd['stuck_minutes']} minutes\n";
    }

    // Log error
    log_error('agent', $alert);
    echo $alert . "\n";

    // Auto-reset commands stuck >30 minutes
    $reset_stmt = db()->query("UPDATE firewall_commands SET status = 'pending', sent_at = NULL WHERE status = 'sent' AND sent_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
    $reset_count = $reset_stmt->rowCount();

    if ($reset_count > 0) {
        echo "✓ Auto-reset $reset_count commands back to pending\n\n";
        log_info('agent', "Auto-reset $reset_count stuck commands back to pending");
    }
} else {
    echo "✓ No stuck commands\n\n";
}

// Check for firewalls that haven't checked in for >10 minutes
$stmt = db()->query("
    SELECT id, hostname, last_checkin,
           TIMESTAMPDIFF(MINUTE, last_checkin, NOW()) as offline_minutes
    FROM firewalls
    WHERE status = 'online'
    AND last_checkin < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
");

$offline_firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($offline_firewalls) > 0) {
    echo "⚠ " . count($offline_firewalls) . " firewalls offline:\n";

    foreach ($offline_firewalls as $fw) {
        echo "- {$fw['hostname']}: offline for {$fw['offline_minutes']} minutes\n";

        log_error('firewall', "Firewall {$fw['hostname']} hasn't checked in for {$fw['offline_minutes']} minutes (last checkin: {$fw['last_checkin']})", null, $fw['id']);

        // Update status
        db()->prepare("UPDATE firewalls SET status = 'offline' WHERE id = ?")->execute([$fw['id']]);
    }
    echo "\n";
} else {
    echo "✓ All firewalls online\n\n";
}

// Check for missing dependencies (iperf3, curl, python3)
$required_packages = ['iperf3', 'curl', 'python3'];
$missing_deps = [];

$stmt = db()->query("SELECT id, hostname FROM firewalls WHERE status IN ('online', 'offline')");
$firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($firewalls as $fw) {
    // Check recent command results for package checks
    $dep_stmt = db()->prepare("
        SELECT description, result
        FROM firewall_commands
        WHERE firewall_id = ?
        AND description LIKE 'Check for % package'
        AND status = 'completed'
        AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ORDER BY created_at DESC
    ");
    $dep_stmt->execute([$fw['id']]);
    $checks = $dep_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($checks as $check) {
        if (strpos($check['result'], 'MISSING') !== false) {
            foreach ($required_packages as $pkg) {
                if (strpos($check['description'], $pkg) !== false) {
                    $missing_deps[] = "{$fw['hostname']}: missing $pkg";
                }
            }
        }
    }
}

if (count($missing_deps) > 0) {
    echo "⚠ Missing dependencies:\n";
    foreach ($missing_deps as $dep) {
        echo "- $dep\n";
    }
    echo "\n";
} else {
    echo "✓ Dependency checks passed (or not run recently)\n\n";
}

// Summary
$summary_stmt = db()->query("
    SELECT
        (SELECT COUNT(*) FROM firewalls WHERE status = 'online') as online_count,
        (SELECT COUNT(*) FROM firewalls WHERE status = 'offline') as offline_count,
        (SELECT COUNT(*) FROM firewall_commands WHERE status = 'pending') as pending_commands,
        (SELECT COUNT(*) FROM firewall_commands WHERE status = 'sent') as sent_commands,
        (SELECT COUNT(*) FROM firewall_commands WHERE status = 'sent' AND sent_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)) as stuck_commands
");
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

echo "=== Summary ===\n";
echo "Firewalls online: {$summary['online_count']}\n";
echo "Firewalls offline: {$summary['offline_count']}\n";
echo "Pending commands: {$summary['pending_commands']}\n";
echo "Sent commands: {$summary['sent_commands']}\n";
echo "Stuck commands (>15min): {$summary['stuck_commands']}\n";

echo "\n✓ Health check complete\n";
?>
