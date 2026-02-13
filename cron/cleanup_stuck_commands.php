#!/usr/bin/env php
<?php
/**
 * Cleanup stuck commands in the firewall_commands queue
 * Run hourly via cron: 30 * * * * php /var/www/opnsense/cron/cleanup_stuck_commands.php
 *
 * Marks as failed:
 *  - "pending" commands older than 1 hour
 *  - "sent" commands older than 30 minutes with no completion
 * Also cancels commands for firewalls that no longer exist or are offline > 24h
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';

$counts = ['stale_pending' => 0, 'stale_sent' => 0, 'orphaned' => 0, 'offline' => 0];

try {
    // 1. Fail pending commands older than 1 hour
    $stmt = db()->prepare("
        UPDATE firewall_commands
        SET status = 'failed', completed_at = NOW(), result = 'Auto-cleanup: stuck in pending for over 1 hour'
        WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute();
    $counts['stale_pending'] = $stmt->rowCount();

    // 2. Fail sent commands older than 30 minutes with no completion
    $stmt = db()->prepare("
        UPDATE firewall_commands
        SET status = 'failed', completed_at = NOW(), result = 'Auto-cleanup: stuck in sent for over 30 minutes'
        WHERE status = 'sent' AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
    ");
    $stmt->execute();
    $counts['stale_sent'] = $stmt->rowCount();

    // 3. Cancel commands for firewalls that no longer exist
    $stmt = db()->prepare("
        UPDATE firewall_commands fc
        LEFT JOIN firewalls f ON fc.firewall_id = f.id
        SET fc.status = 'cancelled', fc.completed_at = NOW(), fc.result = 'Auto-cleanup: firewall no longer exists'
        WHERE fc.status IN ('pending', 'sent') AND f.id IS NULL
    ");
    $stmt->execute();
    $counts['orphaned'] = $stmt->rowCount();

    // 4. Cancel commands for firewalls offline > 24 hours
    $stmt = db()->prepare("
        UPDATE firewall_commands fc
        JOIN firewalls f ON fc.firewall_id = f.id
        SET fc.status = 'cancelled', fc.completed_at = NOW(), fc.result = 'Auto-cleanup: firewall offline for over 24 hours'
        WHERE fc.status IN ('pending', 'sent')
          AND f.status != 'online'
          AND f.last_checkin < DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute();
    $counts['offline'] = $stmt->rowCount();

    $total = array_sum($counts);
    if ($total > 0) {
        error_log("[Queue Cleanup] Cleaned $total stuck commands: {$counts['stale_pending']} stale pending, {$counts['stale_sent']} stale sent, {$counts['orphaned']} orphaned, {$counts['offline']} offline-firewall");
    }

} catch (Exception $e) {
    error_log("[Queue Cleanup] Error: " . $e->getMessage());
    exit(1);
}
