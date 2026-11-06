#!/usr/bin/env php
<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);

/**
 * Policy deployment reconciler.
 *
 * Advances in-flight template applications on evidence only: the apply
 * marker, a read-back backup taken after the apply, an agent check-in since,
 * then the confirm marker. Starts the next target of a running deployment
 * when the previous one succeeded, and stops the deployment on any failure.
 * It never writes to a firewall except through queued apply / confirm /
 * rollback commands for already-approved plans.
 *
 * Schedule every 2 minutes (the recovery window defaults to 15):
 *   *\/2 * * * * www-data php /var/www/opnsense/cron/policy_reconcile.php >> /var/log/opnmgr/policy_reconcile.log 2>&1
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/cron_runs.php';
cron_run_begin('policy_reconcile');
require_once __DIR__ . '/../inc/policy/policy.php';

$r = policy_reconcile();
$msg = sprintf('advanced %d, succeeded %d, failed %d', $r['advanced'], $r['succeeded'], $r['failed']);
echo date('c') . ' ' . $msg . "\n";
cron_run_finish('policy_reconcile', true, $msg);
