<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);
/**
 * Nightly configuration backup, second pass.
 *
 * This job used to build its own upload command by hand:
 *
 *     curl -k -X POST -F "backup=@$BACKUP_FILE" -F "firewall_id=NN" \
 *          https://opn.agit8or.net/api/upload_backup.php
 *
 * That command carries no agent credentials, and api/upload_backup.php has
 * required them since 3.12.0 (`authenticateAgentRequest`). Every upload it
 * queued was therefore rejected. The command also never checked curl's exit
 * code, so the firewall reported the command as completed, and the job created
 * no `backups` row - so a rejected upload left no trace anywhere. It ran nightly
 * in that state for months.
 *
 * It now uses build_backup_upload_command(), the same builder behind manual
 * backups, bulk operations and pre-restore snapshots, which reads the agent's
 * credential files on the firewall and fails loudly on a non-zero curl exit.
 *
 * scripts/automated_backup.php already performs this run at 01:00. This job is
 * a second pass at 02:00: it skips any firewall that already has a backup row
 * for today, so it produces nothing when the earlier run worked, and takes a
 * real backup when it did not.
 *
 * Usage:
 *   php cron/nightly_backups.php            queue for firewalls with no backup today
 *   php cron/nightly_backups.php --force    queue regardless of today's backups
 *   php cron/nightly_backups.php --dry-run  report what would be queued
 *
 * @since 3.20.3
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/backup_storage.php';
require_once __DIR__ . '/../inc/agent_commands.php';

$force  = in_array('--force', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);

$logfile = __DIR__ . '/../logs/nightly_backups.log';

function log_message(string $message): void {
    global $logfile;
    $line = '[' . date('Y-m-d H:i:s') . "] {$message}\n";
    @file_put_contents($logfile, $line, FILE_APPEND);
    echo $line;
}

log_message('=== Nightly backup, second pass ===' . ($dryRun ? ' (dry run)' : ''));

try {
    // Only firewalls whose agent is actually reachable; a queued command for an
    // offline firewall just sits in the queue until it times out.
    $firewalls = db()->query("
        SELECT f.id, f.hostname
          FROM firewalls f
          LEFT JOIN firewall_agents fa
                 ON fa.firewall_id = f.id AND fa.agent_type = 'primary'
         WHERE COALESCE(fa.last_checkin, f.last_checkin) > DATE_SUB(NOW(), INTERVAL 6 HOUR)
         ORDER BY f.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $total = count($firewalls);
    log_message("Reachable firewalls: {$total}");

    $queued = $skipped = $failed = 0;

    $hasBackupToday = db()->prepare(
        'SELECT COUNT(*) FROM backups WHERE firewall_id = ? AND DATE(created_at) = CURDATE()'
    );
    $commandPending = db()->prepare(
        "SELECT COUNT(*) FROM firewall_commands
          WHERE firewall_id = ? AND action = 'backup_upload'
            AND status IN ('pending','sent') AND DATE(created_at) = CURDATE()"
    );

    foreach ($firewalls as $fw) {
        $id   = (int)$fw['id'];
        $name = $fw['hostname'];

        if (!$force) {
            $hasBackupToday->execute([$id]);
            if ((int)$hasBackupToday->fetchColumn() > 0) {
                log_message("  skip {$name}: already has a backup row today");
                $skipped++;
                continue;
            }
        }

        $commandPending->execute([$id]);
        if ((int)$commandPending->fetchColumn() > 0) {
            log_message("  skip {$name}: a backup command is already queued today");
            $skipped++;
            continue;
        }

        $filename = sprintf('automated-backup-%d-%s.xml', $id, date('Y-m-d_H-i-s'));

        if ($dryRun) {
            log_message("  would queue {$name}: {$filename}");
            $queued++;
            continue;
        }

        // The row must exist first: the builder embeds its id so the upload can
        // be matched back to it, and a rejected upload is recorded against it.
        $ins = db()->prepare(
            "INSERT INTO backups (firewall_id, backup_file, backup_type, created_at)
             VALUES (?, ?, 'automated', NOW())"
        );
        $ins->execute([$id, $filename]);
        $backupId = (int)db()->lastInsertId();

        $res = queue_firewall_command(
            $id,
            build_backup_upload_command($id, $backupId, $filename),
            'Automated nightly configuration backup (second pass)',
            ['action' => 'backup_upload', 'parameters' => ['backup_id' => $backupId],
             'is_raw' => false, 'risk' => 'LOW']
        );

        if ($res['ok']) {
            log_message("  queued {$name}: {$filename} (backup {$backupId}, command {$res['command_id']})");
            $queued++;
        } else {
            // Leaving the row behind would claim a backup that was never attempted.
            db()->prepare('DELETE FROM backups WHERE id = ?')->execute([$backupId]);
            log_message("  FAILED {$name}: {$res['error']}");
            $failed++;
        }
    }

    log_message("Done. queued={$queued} skipped={$skipped} failed={$failed} of {$total}");

    if (!$dryRun) {
        db()->prepare(
            "INSERT INTO system_logs (category, message, level, timestamp)
             VALUES ('backup', ?, ?, NOW())"
        )->execute([
            "Nightly backup second pass: {$queued} queued, {$skipped} skipped, {$failed} failed of {$total}",
            $failed > 0 ? 'WARNING' : 'INFO',
        ]);
    }

    exit($failed > 0 ? 1 : 0);

} catch (Throwable $e) {
    log_message('FATAL: ' . $e->getMessage());
    exit(1);
}
