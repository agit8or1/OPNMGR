#!/usr/bin/env php
<?php
require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);

/**
 * Integration poller and housekeeping.
 *
 * For ticketing destinations with a status-poll URL configured, asks the
 * helpdesk for the state of each live ticket (for installs that cannot
 * receive webhooks) and applies acknowledgement / closure / assignment the
 * same way an inbound webhook would. Also prunes nonces and old history.
 *
 * Crontab (not installed automatically):
 *   *\/5 * * * * www-data php /var/www/opnsense/cron/integration_poll.php >> /var/log/opnmgr/integration_poll.log 2>&1
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/cron_runs.php';
cron_run_begin('integration_poll');
require_once __DIR__ . '/../inc/alerting.php';
require_once __DIR__ . '/../inc/integrations/delivery.php';
require_once __DIR__ . '/../inc/integrations/inbound.php';

$changes = 0;
foreach (db()->query("SELECT id FROM integration_destinations WHERE enabled = 1 AND type = 'ticket_webhook'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $dest = integration_destination_load((int)$id);
    if ($dest) { $changes += integration_poll_destination($dest); }
}
integration_prune();
echo date('c') . " poll changes={$changes}\n";
exit(0);
