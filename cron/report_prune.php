#!/usr/bin/env php
<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);

/**
 * Delete generated reports older than settings.report_retention_days
 * (default 400, floor 30). Reports only by default; --apply deletes.
 *
 * Suggested crontab: 30 3 * * * php /var/www/opnsense/cron/report_prune.php --apply >> /var/log/opnmgr/report_prune.log 2>&1
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/cron_runs.php';
cron_run_begin('report_prune');
require_once __DIR__ . '/../inc/reports/jobs.php';

$apply = in_array('--apply', $argv, true);
$days = null;
foreach ($argv as $a) { if (preg_match('/^--days=(\d+)$/', $a, $m)) { $days = (int)$m[1]; } }
$r = report_prune($apply, $days);
printf("Retention %d days: %d report(s) past retention, %d deleted (%s)\n", $r['days'], $r['candidates'], $r['deleted'], $apply ? 'APPLY' : 'report only');
