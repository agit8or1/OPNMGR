<?php
/**
 * VPN topology: gather what is known about each firewall's VPNs.
 *
 * Two sources, never blended without saying so:
 *
 *   config snapshot     the VPN-relevant slice of the latest backup, parsed by
 *                       the platform layer. Says what is configured. Cached in
 *                       vpn_config_cache against the backup it came from.
 *   runtime telemetry   firewall_vpn_tunnels as ingested from agent check-ins.
 *                       Says what the agent last observed.
 *
 * Reading never contacts a firewall. Private keys and pre-shared keys are not
 * in the normalized model; vpn_scrub() removes anything key-shaped again
 * before a slice is cached or returned.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/../platform/platform.php';
require_once __DIR__ . '/../config_drift.php';
require_once __DIR__ . '/../scope.php';

if (!function_exists('vpn_slice_from_model')) {
    /** The parts of a normalized model the topology needs. */
    function vpn_slice_from_model(array $m): array {
        $aliases = [];
        foreach (($m['aliases'] ?? []) as $a) {
            if (!empty($a['name'])) { $aliases[] = (string)$a['name']; }
        }
        return vpn_scrub([
            'platform'     => $m['platform'] ?? null,
            'hostname'     => $m['system']['hostname'] ?? null,
            'interfaces'   => $m['interfaces'] ?? [],
            'routes'       => $m['routes'] ?? [],
            'gateways'     => $m['gateways'] ?? [],
            'nat'          => $m['nat'] ?? [],
            'filter_rules' => $m['filter_rules'] ?? [],
            'alias_names'  => $aliases,
            'vpn'          => $m['vpn'] ?? [],
        ]);
    }
}

if (!function_exists('vpn_config_slice')) {
    /**
     * VPN slice for one firewall from its latest readable backup.
     *
     * @return array{ok:bool, error:string, slice:?array, backup_id:?int, config_time:?string, collected_at:?string, cached:bool}
     */
    function vpn_config_slice(int $firewallId, bool $force = false): array {
        $fail = fn(string $e) => ['ok' => false, 'error' => $e, 'slice' => null, 'backup_id' => null,
                                  'config_time' => null, 'collected_at' => null, 'cached' => false];
        $fw = platform_firewall($firewallId);
        if (!$fw) { return $fail('Firewall not found'); }

        $backup = drift_latest_backup($firewallId);
        if (!$backup) { return $fail('No readable configuration backup exists for this firewall.'); }

        $s = db()->prepare('SELECT * FROM vpn_config_cache WHERE firewall_id = ?');
        $s->execute([$firewallId]);
        $cache = $s->fetch(PDO::FETCH_ASSOC);
        $cfgTime = $backup['uploaded_at'] ?? $backup['created_at'] ?? null;
        if ($cache && !$force && (int)$cache['backup_id'] === (int)$backup['id']) {
            $slice = json_decode((string)$cache['slice'], true);
            if (is_array($slice)) {
                return ['ok' => true, 'error' => '', 'slice' => $slice, 'backup_id' => (int)$backup['id'],
                        'config_time' => $cache['config_time'], 'collected_at' => $cache['collected_at'], 'cached' => true];
            }
        }

        $path = resolve_backup_path($backup);
        $xml = $path ? @file_get_contents($path) : false;
        if ($xml === false || $xml === '') { return $fail('The backup file could not be read.'); }
        $model = platform_normalize_config($xml, $fw['platform_family'] ?: null);
        if (empty($model['ok'])) { return $fail((string)($model['error'] ?? 'Configuration could not be parsed')); }

        $slice = vpn_slice_from_model($model);
        $now = date('Y-m-d H:i:s');
        db()->prepare(
            'INSERT INTO vpn_config_cache (firewall_id, backup_id, fingerprint, config_time, collected_at, slice)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE backup_id = VALUES(backup_id), fingerprint = VALUES(fingerprint),
                config_time = VALUES(config_time), collected_at = VALUES(collected_at), slice = VALUES(slice)'
        )->execute([$firewallId, $backup['id'], $model['fingerprint'] ?? null, $cfgTime, $now,
                    json_encode($slice, JSON_UNESCAPED_SLASHES)]);
        return ['ok' => true, 'error' => '', 'slice' => $slice, 'backup_id' => (int)$backup['id'],
                'config_time' => $cfgTime, 'collected_at' => $now, 'cached' => false];
    }
}

if (!function_exists('vpn_collect_device')) {
    /**
     * Everything the topology needs about one firewall row.
     * @param array $fw firewalls row joined with customer_name/site_name
     */
    function vpn_collect_device(array $fw, bool $forceParse = false): array {
        $id = (int)$fw['id'];
        $cfg = vpn_config_slice($id, $forceParse);

        $q = db()->prepare('SELECT * FROM firewall_vpn_tunnels WHERE firewall_id = ? ORDER BY vpn_type, name');
        $q->execute([$id]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);

        $act = [];
        try {
            $a = db()->prepare('SELECT * FROM vpn_activity WHERE firewall_id = ?');
            $a->execute([$id]);
            foreach ($a->fetchAll(PDO::FETCH_ASSOC) as $r) { $act[$r['vpn_type'] . '|' . $r['name']] = $r; }
        } catch (Throwable $e) {}

        $gw = [];
        try {
            $g = db()->prepare('SELECT * FROM firewall_gateways WHERE firewall_id = ?');
            $g->execute([$id]);
            $gw = $g->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        $family = platform_adapter($fw)->family();
        return [
            'id' => $id, 'hostname' => (string)$fw['hostname'],
            'customer_id' => isset($fw['customer_id']) ? (int)$fw['customer_id'] ?: null : null,
            'customer_name' => $fw['customer_name'] ?? null,
            'site_id' => isset($fw['site_id']) ? (int)$fw['site_id'] ?: null : null,
            'site_name' => $fw['site_name'] ?? null,
            'platform' => $family, 'platform_label' => platform_summary($fw),
            'wan_ip' => $fw['wan_ip'] ?? null, 'ip_address' => $fw['ip_address'] ?? null,
            'last_checkin' => $fw['last_checkin'] ?? null,
            // Rows are replaced wholesale on each check-in that carries VPN
            // telemetry, so the check-in time is when they were last true.
            'telemetry_at' => $fw['last_checkin'] ?? null,
            'config' => $cfg['slice'], 'config_time' => $cfg['config_time'], 'config_error' => $cfg['ok'] ? null : $cfg['error'],
            'config_collected_at' => $cfg['collected_at'],
            'runtime' => $rows, 'activity' => $act, 'gateways_rt' => $gw,
        ];
    }
}

if (!function_exists('vpn_collect_devices')) {
    /**
     * @param int[]|null $onlyIds restrict to these firewalls
     * @return array[] devices (unscoped: matching needs the whole fleet)
     */
    function vpn_collect_devices(?array $onlyIds = null, bool $forceParse = false): array {
        $sql = 'SELECT f.*, c.name AS customer_name, s.name AS site_name
                  FROM firewalls f
                  LEFT JOIN customers c ON c.id = f.customer_id
                  LEFT JOIN sites s ON s.id = f.site_id';
        $params = [];
        if ($onlyIds !== null) {
            if (!$onlyIds) { return []; }
            $sql .= ' WHERE f.id IN (' . implode(',', array_fill(0, count($onlyIds), '?')) . ')';
            $params = array_map('intval', $onlyIds);
        }
        $sql .= ' ORDER BY f.hostname';
        $st = db()->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $fw) { $out[] = vpn_collect_device($fw, $forceParse); }
        return $out;
    }
}

if (!function_exists('vpn_pairings_load')) {
    function vpn_pairings_load(): array {
        try {
            return db()->query('SELECT * FROM vpn_peer_pairings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('vpn_recent_probes')) {
    /** endpoint key => {outcome, at} for the most recent finished firewall-originated probe. */
    function vpn_recent_probes(): array {
        $out = [];
        try {
            $rows = db()->query(
                "SELECT tunnel_key, outcome, completed_at FROM vpn_diagnostic_runs
                  WHERE status = 'completed' AND outcome IN ('reachable','unreachable') AND tunnel_key IS NOT NULL
                    AND completed_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
                  ORDER BY completed_at ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) { $out[$r['tunnel_key']] = ['outcome' => $r['outcome'], 'at' => $r['completed_at']]; }
        } catch (Throwable $e) {}
        return $out;
    }
}

if (!function_exists('vpn_topology_live')) {
    /**
     * Build the current topology for the whole fleet.
     * @return array{topology:array, devices:array}
     */
    function vpn_topology_live(bool $forceParse = false): array {
        $devices = vpn_collect_devices(null, $forceParse);
        $topology = vpn_build_topology($devices, vpn_pairings_load(), ['probes' => vpn_recent_probes()]);
        return ['topology' => $topology, 'devices' => $devices];
    }
}

if (!function_exists('vpn_activity_observe')) {
    /**
     * Record when each tunnel's byte counters last moved. Called by the cron
     * job; the agent overwrites rx/tx each check-in, so this is the only
     * history of whether a quiet tunnel is quiet or dead.
     * @return int rows observed
     */
    function vpn_activity_observe(int $firewallId, ?int $now = null): int {
        $now = $now ?? time();
        $ts = date('Y-m-d H:i:s', $now);
        $q = db()->prepare('SELECT vpn_type, name, rx_bytes, tx_bytes FROM firewall_vpn_tunnels WHERE firewall_id = ?');
        $q->execute([$firewallId]);
        $n = 0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $s = db()->prepare('SELECT * FROM vpn_activity WHERE firewall_id = ? AND vpn_type = ? AND name = ?');
            $s->execute([$firewallId, $r['vpn_type'], $r['name']]);
            $prev = $s->fetch(PDO::FETCH_ASSOC);
            $rx = $r['rx_bytes'] === null ? null : (int)$r['rx_bytes'];
            $tx = $r['tx_bytes'] === null ? null : (int)$r['tx_bytes'];
            if (!$prev) {
                db()->prepare('INSERT INTO vpn_activity (firewall_id, vpn_type, name, last_rx, last_tx, first_seen_at, last_change_at, observed_at)
                               VALUES (?,?,?,?,?,?,NULL,?)')->execute([$firewallId, $r['vpn_type'], $r['name'], $rx, $tx, $ts, $ts]);
            } else {
                // A drop means the interface was reset; that is a change too.
                $moved = ($rx !== null && $rx !== (int)$prev['last_rx']) || ($tx !== null && $tx !== (int)$prev['last_tx']);
                db()->prepare('UPDATE vpn_activity SET last_rx = ?, last_tx = ?, observed_at = ?,
                                  last_change_at = ' . ($moved ? '?' : 'last_change_at') . '
                                WHERE firewall_id = ? AND vpn_type = ? AND name = ?')
                    ->execute(array_merge([$rx, $tx, $ts], $moved ? [$ts] : [], [$firewallId, $r['vpn_type'], $r['name']]));
            }
            $n++;
        }
        // Forget tunnels that no longer exist.
        db()->prepare('DELETE a FROM vpn_activity a
                        LEFT JOIN firewall_vpn_tunnels t ON t.firewall_id = a.firewall_id AND t.vpn_type = a.vpn_type AND t.name = a.name
                       WHERE a.firewall_id = ? AND t.id IS NULL')->execute([$firewallId]);
        return $n;
    }
}

if (!function_exists('vpn_scope_filter')) {
    /**
     * Restrict a topology to the firewalls a staff user may see, without
     * leaking what is on the far side. A tunnel whose other end is outside the
     * scope is shown as a tunnel to an external peer; findings that touch an
     * out-of-scope firewall are withheld (and counted).
     *
     * @param int[]|null $allowed firewall ids, or null for unrestricted
     */
    function vpn_scope_filter(array $topology, array $findings, ?array $allowed): array {
        if ($allowed === null) { return ['topology' => $topology, 'findings' => $findings, 'hidden_findings' => 0]; }
        $ok = array_flip($allowed);
        $tunnels = [];
        foreach ($topology['tunnels'] as $t) {
            $ids = $t['firewall_ids'];
            $in = array_values(array_filter($ids, fn($i) => isset($ok[$i])));
            if (!$in) { continue; }
            if (count($in) < count($ids)) {
                if (!isset($ok[$t['a']['firewall_id']])) { [$t['a'], $t['b']] = [$t['b'], $t['a']]; [$t['state_a'], $t['state_b']] = [$t['state_b'], $t['state_a']]; }
                $t['b'] = null; $t['state_b'] = null; $t['state'] = $t['state_a']['state'];
                $t['kind'] = 'external'; $t['partial'] = true; $t['relationship'] = 'inferred'; $t['match_score'] = 0;
                $t['evidence'] = ['The other end is a managed firewall outside your access scope; its details are withheld.'];
                $t['name'] = $t['a']['firewall_name'] . ' / ' . $t['a']['name'];
                $t['firewall_ids'] = [$t['a']['firewall_id']];
                $t['id'] = substr(sha1($t['id'] . ':scoped'), 0, 12);
                $t['a']['remote_ids'] = ['host' => null];
            }
            if ($t['kind'] === 'ambiguous') {
                foreach ($topology['ambiguous'][$t['a']['key']] ?? [] as $c) {
                    $cf = $topology['endpoints'][$c['key']]['firewall_id'] ?? null;
                    if ($cf !== null && !isset($ok[$cf])) {
                        $t['evidence'] = ['Several endpoints could be the other end, including firewalls outside your access scope; details withheld.'];
                        break;
                    }
                }
            }
            unset($t['collected']['b']);
            $tunnels[] = $t;
        }
        $topology['tunnels'] = $tunnels;
        $topology['devices'] = array_values(array_filter($topology['devices'], fn($d) => isset($ok[$d['id']])));
        $topology['endpoints'] = array_filter($topology['endpoints'], fn($e) => isset($ok[$e['firewall_id']]));
        $topology['ambiguous'] = [];
        $keep = []; $hidden = 0;
        foreach ($findings as $f) {
            $fw = $f['affected']['firewalls'] ?? [];
            if (array_diff($fw, $allowed)) { $hidden++; continue; }
            $keep[] = $f;
        }
        return ['topology' => $topology, 'findings' => $keep, 'hidden_findings' => $hidden];
    }
}

if (!function_exists('vpn_allowed_firewall_ids')) {
    /** Firewall ids the current user may see; null = unrestricted. */
    function vpn_allowed_firewall_ids(): ?array {
        if (scope_customer_ids() === null) { return null; }
        [$w, $p] = scope_sql('customer_id');
        $s = db()->prepare("SELECT id FROM firewalls WHERE $w");
        $s->execute($p);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }
}
