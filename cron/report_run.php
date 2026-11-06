#!/usr/bin/env php
<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);

/**
 * Report worker: queue due schedules, build queued reports, send deliveries.
 *
 * Safe to install before anything is enabled: schedules are skipped unless
 * settings.report_schedules_enabled = 1 (and the individual schedule is
 * enabled), and email is skipped unless report_delivery_enabled = 1. On-demand
 * jobs queued by the web UI are always processed here.
 *
 * Suggested crontab (not installed automatically), as the web user, e.g.:
 *   *\/10 * * * * php /var/www/opnsense/cron/report_run.php >> /var/log/opnmgr/report_run.log 2>&1
 *
 * Usage: php cron/report_run.php [--verbose]
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/cron_runs.php';
cron_run_begin('report_run');
require_once __DIR__ . '/../inc/reports/jobs.php';

$verbose = in_array('--verbose', $argv, true);
$sched = report_schedule_tick();
$work  = report_worker_run(10);
$deliv = report_delivery_run();

$line = sprintf('schedules=%s queued=%d deduped=%d | jobs processed=%d ok=%d retry=%d failed=%d | deliveries sent=%d retry=%d failed=%d',
    $sched['enabled'] ? 'on' : 'off', $sched['queued'], $sched['deduped'], $work['processed'], $work['succeeded'], $work['retry'], $work['failed'],
    $deliv['sent'], $deliv['retry'], $deliv['failed']);
if ($verbose || $work['failed'] || $deliv['failed']) { echo date('Y-m-d H:i:s') . '  ' . $line . "\n"; }
// A permanently failed job must be visible on the scheduled-jobs page, not only in the reports list.
if ($work['failed'] > 0 || $deliv['failed'] > 0) {
    // Registered after cron_run_begin's handler, so it runs last and its "failed" wins.
    register_shutdown_function(static function () use ($line): void { cron_run_finish('report_run', false, $line); });
}
