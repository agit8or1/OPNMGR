<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);

/**
 * Keep the VPN topology inputs fresh.
 *
 *   - re-parses a firewall's VPN configuration when its latest backup changed
 *     (vpn_config_cache), so the topology page never parses on a request
 *   - records when each tunnel's byte counters last moved (vpn_activity), the
 *     only history that separates a quiet tunnel from a dead one
 *   - settles probe runs whose command finished, and expires ones the agent
 *     never collected
 *   - prunes diagnostic runs older than 90 days
 *
 * It reads stored data only and never contacts a firewall.
 *
 * Crontab (as the application user):
 *   *\/5 * * * * php /var/www/opnsense/cron/vpn_topology_refresh.php >> /var/log/opnmgr/vpn_topology_refresh.log 2>&1
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/cron_runs.php';
require_once __DIR__ . '/../inc/vpn/vpn.php';

cron_run_begin('vpn_topology_refresh');

$parsed = $cached = $failed = $observed = 0;
foreach (db()->query('SELECT id FROM firewalls')->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $id = (int)$id;
    try {
        $r = vpn_config_slice($id);
        if ($r['ok']) { $r['cached'] ? $cached++ : $parsed++; } else { $failed++; }
        $observed += vpn_activity_observe($id);
    } catch (Throwable $e) {
        $failed++;
        error_log("vpn_topology_refresh: firewall $id: " . $e->getMessage());
    }
}

$groups = db()->query("SELECT DISTINCT run_group FROM vpn_diagnostic_runs WHERE status = 'queued'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($groups as $g) { vpn_probe_sync((string)$g); }
$pruned = db()->exec("DELETE FROM vpn_diagnostic_runs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");

printf("[%s] vpn_topology_refresh: parsed=%d cached=%d no_config=%d tunnels_observed=%d runs_settled=%d pruned=%d\n",
    date('c'), $parsed, $cached, $failed, $observed, count($groups), (int)$pruned);
