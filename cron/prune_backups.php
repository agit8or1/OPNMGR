<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);
/**
 * Enforce the backup retention window.
 *
 * Retention has been configurable since 3.12.0 and enforced by nothing: no code
 * anywhere read `backup_retention_days`, so backups accumulated indefinitely.
 * This is the job that applies it.
 *
 * Usage:
 *   php cron/prune_backups.php              report what would be deleted
 *   php cron/prune_backups.php --apply      delete it
 *   php cron/prune_backups.php --days=30    override the configured window
 *
 * Reports by default. Deleting configuration backups is not reversible, so the
 * destructive mode is opt-in even here.
 *
 * @since 3.20.0
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/backup_storage.php';

$apply = in_array('--apply', $argv, true);
$days  = null;
$floor = null;

foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m))  { $days  = (int)$m[1]; }
    if (preg_match('/^--floor=(\d+)$/', $arg, $m)) { $floor = (int)$m[1]; }
}

function prune_log(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

$report = prune_expired_backups($apply, $days, $floor);

if ($report['days'] <= 0) {
    prune_log('Backup retention is disabled (backup_retention_days = 0); nothing pruned.');
    exit(0);
}

prune_log(sprintf(
    'Retention: %d days, keeping at least %d newest per firewall',
    $report['days'], $report['floor']
));

if ($report['candidates'] === 0) {
    prune_log('No backups outside the retention window.');
    exit(0);
}

if (!$apply) {
    prune_log(sprintf(
        'DRY RUN: %d backup(s) totalling %.1f MB are outside the window. Re-run with --apply to delete them.',
        $report['candidates'], $report['bytes'] / 1048576
    ));
    exit(0);
}

prune_log(sprintf(
    'Deleted %d of %d expired backup(s): %d file(s) removed, %d row(s) had no file, %.1f MB reclaimed.',
    $report['deleted'], $report['candidates'],
    $report['files_removed'], $report['files_missing'],
    $report['bytes'] / 1048576
));

foreach ($report['errors'] as $err) {
    prune_log('WARNING: ' . $err);
}

exit(empty($report['errors']) ? 0 : 1);
