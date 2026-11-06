#!/usr/bin/env php
<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);

/**
 * Template compliance evaluation.
 *
 * Re-plans every effective template against each firewall's newest backup and
 * records compliant / pending / drifted / exception / conflict / invalid.
 * Read-only: drift is reported, never corrected automatically.
 *
 * Schedule hourly:
 *   17 * * * * www-data php /var/www/opnsense/cron/policy_compliance.php >> /var/log/opnmgr/policy_compliance.log 2>&1
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/cron_runs.php';
cron_run_begin('policy_compliance');
require_once __DIR__ . '/../inc/policy/policy.php';

$counts = [];
foreach (db()->query('SELECT id FROM firewalls')->fetchAll(PDO::FETCH_COLUMN) as $id) {
    foreach (policy_evaluate_compliance((int)$id) as $row) {
        $counts[$row['compliance']] = ($counts[$row['compliance']] ?? 0) + 1;
    }
}
ksort($counts);
$msg = $counts ? implode(', ', array_map(fn($k, $v) => "{$k} {$v}", array_keys($counts), $counts)) : 'no template assignments';
echo date('c') . ' ' . $msg . "\n";
cron_run_finish('policy_compliance', true, $msg);
