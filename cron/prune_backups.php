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
 *   php cron/prune_backups.php --grace=24   override the fileless grace window
 *   php cron/prune_backups.php --no-reap    skip the fileless pass
 *
 * Two passes run: the retention window above, and a reap of rows whose upload
 * never arrived. A row is created when the upload is queued, so one with no file
 * after the grace window (default 48h, `backup_fileless_grace_hours`) is never
 * getting one - the firewall was offline, or the upload was rejected. Left alone
 * those rows accumulate and inflate the backup count, which is the same false
 * confidence that let a broken uploader run unnoticed for months (3.20.3).
 *
 * Reports by default. Deleting configuration backups is not reversible, so the
 * destructive mode is opt-in even here.
 *
 * @since 3.20.0
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/backup_storage.php';

$apply  = in_array('--apply', $argv, true);
$noReap = in_array('--no-reap', $argv, true);
$days   = null;
$floor  = null;
$grace  = null;

foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m))  { $days  = (int)$m[1]; }
    if (preg_match('/^--floor=(\d+)$/', $arg, $m)) { $floor = (int)$m[1]; }
    if (preg_match('/^--grace=(\d+)$/', $arg, $m)) { $grace = (int)$m[1]; }
}

function prune_log(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

/**
 * Reap rows whose upload never arrived, then exit with the combined status.
 */
function run_reap_and_exit(bool $apply, bool $noReap, ?int $grace, int $status): void {
    if ($noReap) {
        exit($status);
    }

    $reap = reap_fileless_backups($apply, $grace);

    if ($reap['hours'] <= 0) {
        prune_log('Fileless reaping is disabled (backup_fileless_grace_hours = 0).');
        exit($status);
    }

    // Reaping depends on telling a missing file from an unreadable one. Run as a
    // user that cannot traverse the store and every backup looks missing, so the
    // pass would be deciding blind. It skips rather than guesses, but say so
    // plainly instead of emitting one warning per row.
    if ($reap['unreadable'] > 0 && $reap['candidates'] === 0) {
        prune_log(sprintf(
            'Cannot read %s as %s: %d backup file(s) unreadable, so nothing can be reaped.',
            backup_storage_root(),
            function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : '?',
            $reap['unreadable']
        ));
        prune_log('Re-run as the web server user or root:  sudo -u www-data php cron/prune_backups.php');
        exit(3);
    }

    if ($reap['candidates'] === 0) {
        prune_log(sprintf('No backup rows without a file older than %dh.', $reap['hours']));
        exit($status);
    }

    if (!$apply) {
        prune_log(sprintf(
            'DRY RUN: %d row(s) older than %dh have no file. Re-run with --apply to delete them.',
            $reap['candidates'], $reap['hours']
        ));
        foreach (array_slice($reap['rows'], 0, 10) as $r) {
            prune_log(sprintf('    #%d %s %s (%s)', $r['id'], $r['firewall'], $r['created_at'], $r['reason']));
        }
    } else {
        prune_log(sprintf(
            'Reaped %d of %d backup row(s) with no file (grace %dh).',
            $reap['deleted'], $reap['candidates'], $reap['hours']
        ));
    }

    foreach (array_slice($reap['errors'], 0, 5) as $err) {
        prune_log('WARNING: ' . $err);
    }
    if (count($reap['errors']) > 5) {
        prune_log(sprintf('WARNING: ... and %d more', count($reap['errors']) - 5));
    }

    exit($status !== 0 || !empty($reap['errors']) ? 1 : 0);
}

$report = prune_expired_backups($apply, $days, $floor);

if ($report['days'] <= 0) {
    prune_log('Backup retention is disabled (backup_retention_days = 0); nothing pruned.');
    run_reap_and_exit($apply, $noReap, $grace, 0);
}

prune_log(sprintf(
    'Retention: %d days, keeping at least %d newest per firewall',
    $report['days'], $report['floor']
));

if ($report['candidates'] === 0) {
    prune_log('No backups outside the retention window.');
    run_reap_and_exit($apply, $noReap, $grace, 0);
}

if (!$apply) {
    prune_log(sprintf(
        'DRY RUN: %d backup(s) totalling %.1f MB are outside the window. Re-run with --apply to delete them.',
        $report['candidates'], $report['bytes'] / 1048576
    ));
    run_reap_and_exit($apply, $noReap, $grace, 0);
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

run_reap_and_exit($apply, $noReap, $grace, empty($report['errors']) ? 0 : 1);
