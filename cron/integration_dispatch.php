#!/usr/bin/env php
<?php
require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);

/**
 * Integration dispatcher.
 *
 * Drains the outbox into per-destination deliveries, fires escalation routes,
 * recovers deliveries whose worker died, and sends what is due (retries,
 * backoff, Retry-After and per-destination concurrency are handled by
 * inc/integrations/delivery.php). Safe to run concurrently: rows are leased.
 *
 * Crontab (not installed automatically); logs go under /var/log/opnmgr,
 * which the cron user can write:
 *   * * * * * www-data php /var/www/opnsense/cron/integration_dispatch.php >> /var/log/opnmgr/integration_dispatch.log 2>&1
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/cron_runs.php';
cron_run_begin('integration_dispatch');
require_once __DIR__ . '/../inc/alerting.php';
require_once __DIR__ . '/../inc/integrations/delivery.php';

$stats = integration_run_cycle(50);
echo date('c') . ' ' . json_encode($stats) . "\n";
exit(0);
