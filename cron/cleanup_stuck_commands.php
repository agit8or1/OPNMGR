#!/usr/bin/env php
<?php
/**
 * Command Queue Cleanup & Purge
 * Run hourly via cron: 30 * * * * php /var/www/opnsense/cron/cleanup_stuck_commands.php
 *
 * Phase 1 - Stuck command recovery:
 *  - "pending" commands older than 1 hour → failed
 *  - "sent" commands older than 30 minutes → failed
 *  - Commands for deleted firewalls → cancelled
 *  - Commands for firewalls offline > 24h → cancelled
 *
 * Phase 2 - Old record purge (data retention):
 *  - Completed commands older than 7 days → deleted
 *  - Failed commands older than 14 days → deleted
 *  - Cancelled commands older than 14 days → deleted
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';

$counts = [
    'stale_pending' => 0, 'stale_sent' => 0, 'orphaned' => 0, 'offline' => 0,
    'purged_completed' => 0, 'purged_failed' => 0, 'purged_cancelled' => 0
];

try {
    // =========================================================================
    // Phase 1: Mark stuck/dead commands
    // =========================================================================

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

    // =========================================================================
    // Phase 2: Purge old completed/failed/cancelled records
    // =========================================================================

    // 5. Delete completed commands older than 7 days
    $stmt = db()->prepare("
        DELETE FROM firewall_commands
        WHERE status = 'completed' AND completed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stmt->execute();
    $counts['purged_completed'] = $stmt->rowCount();

    // 6. Delete failed commands older than 14 days
    $stmt = db()->prepare("
        DELETE FROM firewall_commands
        WHERE status = 'failed' AND COALESCE(completed_at, created_at) < DATE_SUB(NOW(), INTERVAL 14 DAY)
    ");
    $stmt->execute();
    $counts['purged_failed'] = $stmt->rowCount();

    // 7. Delete cancelled commands older than 14 days
    $stmt = db()->prepare("
        DELETE FROM firewall_commands
        WHERE status = 'cancelled' AND COALESCE(completed_at, created_at) < DATE_SUB(NOW(), INTERVAL 14 DAY)
    ");
    $stmt->execute();
    $counts['purged_cancelled'] = $stmt->rowCount();

    // =========================================================================
    // Logging
    // =========================================================================
    $stuck_total = $counts['stale_pending'] + $counts['stale_sent'] + $counts['orphaned'] + $counts['offline'];
    $purge_total = $counts['purged_completed'] + $counts['purged_failed'] + $counts['purged_cancelled'];

    if ($stuck_total > 0) {
        error_log("[Queue Cleanup] Fixed $stuck_total stuck commands: {$counts['stale_pending']} stale pending, {$counts['stale_sent']} stale sent, {$counts['orphaned']} orphaned, {$counts['offline']} offline-firewall");
    }
    if ($purge_total > 0) {
        error_log("[Queue Cleanup] Purged $purge_total old records: {$counts['purged_completed']} completed (>7d), {$counts['purged_failed']} failed (>14d), {$counts['purged_cancelled']} cancelled (>14d)");
    }

} catch (Exception $e) {
    error_log("[Queue Cleanup] Error: " . $e->getMessage());
    exit(1);
}
