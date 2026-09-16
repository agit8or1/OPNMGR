<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);

/**
 * Prune telemetry and log tables that nothing has ever pruned.
 *
 * On the maintainer's installation - two firewalls, nine months - the database
 * had reached 174 MB, and four tables were 96% of it:
 *
 *   system_logs             404,516 rows   80.6 MB
 *   firewall_traffic_stats  299,045 rows   34.6 MB
 *   firewall_system_stats   323,294 rows   28.1 MB
 *   firewall_latency        324,265 rows   19.0 MB
 *
 * That is roughly 1,800 rows per firewall per day, growing without limit. For a
 * tool positioned at fleets it is the wrong shape: fifty firewalls would add
 * about 33 million rows a year, and nothing would ever remove one.
 *
 * A retention setting existed and did nothing. log_retention_days has sat at 90
 * with no code reading it, while the only caller of cleanup_old_logs() is a
 * manual endpoint that passes a hardcoded 30 and is in no crontab. So logs were
 * pruned exactly when an administrator remembered to click something, at a
 * retention nobody chose.
 *
 * Reports by default and deletes only with --apply, matching prune_backups.php,
 * because the first run on an installation like this one would remove hundreds
 * of thousands of rows and that should be a decision rather than a surprise.
 *
 * Usage:
 *   php cron/prune_telemetry.php                 report only
 *   php cron/prune_telemetry.php --apply         delete
 *   php cron/prune_telemetry.php --days=30       override telemetry retention
 *   php cron/prune_telemetry.php --log-days=90   override log retention
 *
 * @since 3.45.0
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/cron_runs.php';

cron_run_begin('prune_telemetry');

$apply   = in_array('--apply', $argv, true);
$days    = null;
$logDays = null;

foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m))     { $days    = (int) $m[1]; }
    if (preg_match('/^--log-days=(\d+)$/', $arg, $m)) { $logDays = (int) $m[1]; }
}

/** A setting, or its default, clamped to something sane. */
function telemetry_setting(string $name, int $default, int $min = 7, int $max = 3650): int
{
    try {
        $stmt = db()->prepare('SELECT `value` FROM settings WHERE `name` = ?');
        $stmt->execute([$name]);
        $v = $stmt->fetchColumn();
        if ($v !== false && ctype_digit((string) $v)) {
            $n = (int) $v;
            if ($n >= $min && $n <= $max) {
                return $n;
            }
        }
    } catch (Throwable $e) {
        error_log('OPNMGR: could not read ' . $name . ': ' . $e->getMessage());
    }
    return $default;
}

$days    = $days    ?? telemetry_setting('telemetry_retention_days', 90);
$logDays = $logDays ?? telemetry_setting('log_retention_days', 90);

// table => [timestamp column, retention days]
$targets = [
    'firewall_system_stats'  => ['recorded_at', $days],
    'firewall_latency'       => ['measured_at', $days],
    'firewall_traffic_stats' => ['recorded_at', $days],
    'system_logs'            => ['timestamp',   $logDays],
];

printf("Telemetry retention: %d days   Log retention: %d days   Mode: %s\n\n",
    $days, $logDays, $apply ? 'APPLY' : 'report only');

$totalDeleted = 0;
$failures = 0;

foreach ($targets as $table => [$column, $retain]) {
    try {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->execute([$retain]);
        $old = (int) $stmt->fetchColumn();

        $stmt = db()->query("SELECT COUNT(*) FROM `{$table}`");
        $total = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        printf("  %-24s ERROR: %s\n", $table, $e->getMessage());
        $failures++;
        continue;
    }

    if ($old === 0) {
        printf("  %-24s %8d rows, nothing older than %d days\n", $table, $total, $retain);
        continue;
    }

    if (!$apply) {
        printf("  %-24s %8d rows, %d older than %d days (would delete)\n",
            $table, $total, $old, $retain);
        continue;
    }

    // Deleted in chunks. A single DELETE of several hundred thousand rows holds
    // locks long enough to stall check-ins, which write to these same tables
    // every couple of minutes.
    $deleted = 0;
    try {
        do {
            $stmt = db()->prepare(
                "DELETE FROM `{$table}` WHERE `{$column}` < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 5000"
            );
            $stmt->execute([$retain]);
            $n = $stmt->rowCount();
            $deleted += $n;
            if ($n > 0) {
                usleep(50000); // let other writers in
            }
        } while ($n > 0);
    } catch (Throwable $e) {
        printf("  %-24s ERROR after %d rows: %s\n", $table, $deleted, $e->getMessage());
        $failures++;
        continue;
    }

    $totalDeleted += $deleted;
    printf("  %-24s %8d rows, deleted %d older than %d days\n", $table, $total, $deleted, $retain);
}

printf("\n%s\n", $apply
    ? sprintf('Deleted %d row(s).%s', $totalDeleted,
        $failures ? " {$failures} table(s) failed." : '')
    : 'Report only. Re-run with --apply to delete.');

if ($failures > 0) {
    cron_run_finish('prune_telemetry', false, "{$failures} table(s) failed");
    exit(1);
}
exit(0);
