<?php
/**
 * Report data collection: reads existing tables, never writes, and returns a
 * plain-array metric snapshot that records its own provenance (sources, row
 * counts, thresholds, exclusions). The renderer and the AI summary only ever
 * see this snapshot, so a stored report can be explained later without
 * re-querying data that retention may since have removed.
 *
 * Every timestamp is read with UNIX_TIMESTAMP(), which is independent of the
 * connection time zone for TIMESTAMP columns and matches NOW() semantics for
 * the DATETIME maintenance window columns.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/period.php';
require_once __DIR__ . '/metrics.php';

if (!function_exists('report_sections_catalog')) {
    /** key => label, in report order. */
    function report_sections_catalog(): array {
        return [
            'executive_summary' => 'Executive summary',
            'inventory'         => 'Firewall inventory',
            'coverage'          => 'Monitoring coverage',
            'availability'      => 'Observed availability',
            'latency'           => 'WAN latency, packet loss and outages',
            'vpn'               => 'VPN health and incidents',
            'maintenance'       => 'Maintenance and updates',
            'backups'           => 'Backup freshness and failures',
            'templates'         => 'Template compliance and exceptions',
            'config_changes'    => 'Configuration changes',
            'findings'          => 'Unresolved findings',
            'recommendations'   => 'Recommended follow-up actions',
        ];
    }
}
if (!function_exists('report_sections_normalize')) {
    /** Known keys only, catalog order, so the same selection always hashes the same. */
    function report_sections_normalize($sections): array {
        $cat = array_keys(report_sections_catalog());
        if (!is_array($sections) || !$sections) { return $cat; }
        return array_values(array_intersect($cat, array_map('strval', $sections)));
    }
}

if (!function_exists('report_setting')) {
    function report_setting(string $name, $default = '') {
        try {
            $s = db()->prepare('SELECT `value` FROM settings WHERE `name` = ?');
            $s->execute([$name]);
            $v = $s->fetchColumn();
            return ($v === false || $v === null) ? $default : $v;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('report_table_exists')) {
    function report_table_exists(string $t): bool {
        static $c = [];
        if (!isset($c[$t])) {
            $s = db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $s->execute([$t]);
            $c[$t] = (int)$s->fetchColumn() > 0;
        }
        return $c[$t];
    }
}

if (!function_exists('report_scope_resolve')) {
    /**
     * Resolve a report scope to its customer, firewalls and timezone.
     * Firewalls are matched strictly by customer_id / site_id / id, so a
     * customer report cannot contain another customer's firewall.
     *
     * @return array{type:string,id:?int,name:string,customer_id:?int,customer:?array,site:?array,timezone:string,firewalls:array}
     * @throws InvalidArgumentException when the scope does not exist
     */
    function report_scope_resolve(string $type, ?int $id): array {
        $customer = $site = null; $fws = [];
        $cols = 'f.*, UNIX_TIMESTAMP(f.last_checkin) AS last_checkin_ts, UNIX_TIMESTAMP(f.enrolled_at) AS enrolled_ts,
                 UNIX_TIMESTAMP(f.last_backup_at) AS last_backup_ts, UNIX_TIMESTAMP(f.last_update_check) AS last_update_check_ts';
        $fetchC = function (?int $cid) {
            if (!$cid) { return null; }
            $s = db()->prepare('SELECT id, name, code, timezone FROM customers WHERE id = ?');
            $s->execute([$cid]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: null;
        };
        switch ($type) {
            case 'fleet':
                // $cols is a constant column list defined above, never user input.
                $st = db()->prepare("SELECT $cols FROM firewalls f ORDER BY f.hostname, f.id");
                $st->execute();
                $fws = $st->fetchAll(PDO::FETCH_ASSOC);
                $name = 'Fleet';
                break;
            case 'customer':
                $customer = $fetchC($id);
                if (!$customer) { throw new InvalidArgumentException('Customer not found'); }
                $s = db()->prepare("SELECT $cols FROM firewalls f WHERE f.customer_id = ? ORDER BY f.hostname, f.id");
                $s->execute([$id]); $fws = $s->fetchAll(PDO::FETCH_ASSOC);
                $name = $customer['name'];
                break;
            case 'site':
                $s = db()->prepare('SELECT id, customer_id, name, timezone FROM sites WHERE id = ?');
                $s->execute([$id]); $site = $s->fetch(PDO::FETCH_ASSOC);
                if (!$site) { throw new InvalidArgumentException('Site not found'); }
                $customer = $fetchC((int)$site['customer_id']);
                // customer_id filter as well: a firewall must belong to the site's own customer.
                $s = db()->prepare("SELECT $cols FROM firewalls f WHERE f.site_id = ? AND f.customer_id = ? ORDER BY f.hostname, f.id");
                $s->execute([$id, (int)$site['customer_id']]); $fws = $s->fetchAll(PDO::FETCH_ASSOC);
                $name = ($customer['name'] ?? '') . ' / ' . $site['name'];
                break;
            case 'firewall':
                $s = db()->prepare("SELECT $cols FROM firewalls f WHERE f.id = ?");
                $s->execute([$id]); $fws = $s->fetchAll(PDO::FETCH_ASSOC);
                if (!$fws) { throw new InvalidArgumentException('Firewall not found'); }
                $customer = $fetchC($fws[0]['customer_id'] ? (int)$fws[0]['customer_id'] : null);
                if ($fws[0]['site_id']) {
                    $s = db()->prepare('SELECT id, customer_id, name, timezone FROM sites WHERE id = ?');
                    $s->execute([(int)$fws[0]['site_id']]); $site = $s->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                $name = $fws[0]['hostname'];
                break;
            default:
                throw new InvalidArgumentException('Unknown report type');
        }
        $tz = ($site['timezone'] ?? '') ?: ($customer['timezone'] ?? '') ?: date_default_timezone_get();
        try { new DateTimeZone($tz); } catch (Throwable $e) { $tz = 'UTC'; }
        return [
            'type' => $type, 'id' => $id, 'name' => (string)$name,
            'customer_id' => $type === 'fleet' ? null : ($customer ? (int)$customer['id'] : ($fws[0]['customer_id'] ?? null)),
            'customer' => $customer, 'site' => $site, 'timezone' => $tz, 'firewalls' => $fws,
        ];
    }
}

if (!function_exists('report_scope_authorized')) {
    /** Whether the current staff user (or CLI/system) may see this scope. Fleet needs unrestricted staff. */
    function report_scope_authorized(string $type, ?int $id, ?int $userId = null): bool {
        if (!function_exists('scope_customer_ids')) { return true; }
        if ($type === 'fleet') { return scope_customer_ids($userId) === null; }
        try { $sc = report_scope_resolve($type, $id); } catch (Throwable $e) { return false; }
        // A firewall with no customer is visible only to unrestricted users (scope_can_customer(null)).
        return scope_can_customer($sc['customer_id'], $userId);
    }
}

if (!function_exists('report_in_list')) {
    function report_in_list(array $ids): array {
        $ids = array_values(array_map('intval', $ids)) ?: [0];
        return [implode(',', array_fill(0, count($ids), '?')), $ids];
    }
}

if (!function_exists('report_collect_heartbeats')) {
    /**
     * Heartbeats for every firewall in the window (fleet-wide: a monitoring gap
     * is only visible against the whole fleet) plus the data horizon.
     * @return array{per:array<int,int[]>,fleet:int[],horizon:?int,sources:array}
     */
    function report_collect_heartbeats(int $from, int $to, array $keepIds): array {
        $srcs = [['firewall_system_stats', 'recorded_at'], ['firewall_latency', 'measured_at']];
        if (report_table_exists('agent_checkins')) { $srcs[] = ['agent_checkins', 'checkin_time']; }
        $sel = []; $params = [];
        foreach ($srcs as [$t, $c]) {
            $sel[] = "SELECT firewall_id, UNIX_TIMESTAMP(`$c`) AS t FROM `$t` WHERE `$c` >= FROM_UNIXTIME(?) AND `$c` < FROM_UNIXTIME(?)";
            array_push($params, $from, $to);
        }
        $st = db()->prepare(implode(' UNION ', $sel) . ' ORDER BY t');
        $st->execute($params);
        $keep = array_flip($keepIds);
        $per = []; $fleetSet = []; $witness = [];
        while ($r = $st->fetch(PDO::FETCH_NUM)) {
            $fid = (int)$r[0]; $t = (int)$r[1];
            $fleetSet[$t] = true;
            $witness[$fid] = true;
            if (isset($keep[$fid])) { $per[$fid][] = $t; }
        }
        ksort($fleetSet);
        $h = [];
        foreach ($srcs as [$t, $c]) {
            // $t/$c come from the hardcoded source list above.
            $mn = db()->prepare("SELECT UNIX_TIMESTAMP(MIN(`$c`)) FROM `$t`");
            $mn->execute();
            $v = $mn->fetchColumn();
            if ($v) { $h[] = (int)$v; }
        }
        return ['per' => $per, 'fleet' => array_keys($fleetSet), 'witness_ids' => array_keys($witness),
                'horizon' => $h ? min($h) : null, 'sources' => array_map(fn($s) => $s[0] . '.' . $s[1], $srcs)];
    }
}

if (!function_exists('report_gateway_state')) {
    function report_gateway_state(?string $s): ?string {
        $s = strtolower((string)$s);
        if ($s === '') { return null; }
        if (in_array($s, ['down', 'force_down'], true)) { return 'down'; }
        if (in_array($s, ['loss', 'delay'], true)) { return 'degraded'; }
        if (in_array($s, ['online', 'none', 'up'], true)) { return 'up'; }
        return null;
    }
}
if (!function_exists('report_vpn_state')) {
    function report_vpn_state(?string $s): ?string {
        $s = strtolower((string)$s);
        return match ($s) { 'up' => 'up', 'down' => 'down', 'connecting' => 'degraded', default => null };
    }
}

if (!function_exists('report_collect')) {
    /**
     * Build the metric snapshot.
     *
     * @param array $params type, scope_id, period{start,end,timezone,label}, sections, include_ai
     * @param int   $now    generation instant (Unix seconds)
     */
    function report_collect(array $params, int $now): array {
        $scope = report_scope_resolve((string)$params['report_type'], isset($params['scope_id']) ? (int)$params['scope_id'] : null);
        $start = (int)$params['period']['start']; $end = (int)$params['period']['end'];
        $tzName = (string)$params['period']['timezone'];
        $we = max($start, min($end, $now));
        $sections = report_sections_normalize($params['sections'] ?? null);
        $fws = $scope['firewalls'];
        $ids = array_map(fn($f) => (int)$f['id'], $fws);
        $byId = []; foreach ($fws as $f) { $byId[(int)$f['id']] = $f; }
        $exclusions = [];

        // ---- heartbeats / availability --------------------------------------------------
        $lookback = 6 * 3600;
        $hb = report_collect_heartbeats($start - $lookback, $we, $ids);
        $maxI = 180;
        if ($hb['witness_ids']) {
            [$ph, $p] = report_in_list($hb['witness_ids']);
            $st = db()->prepare("SELECT MAX(COALESCE(NULLIF(checkin_interval,0),180)) FROM firewalls WHERE id IN ($ph)");
            $st->execute($p); $maxI = (int)($st->fetchColumn() ?: 180);
        }
        $tMgr = max(300, min(3600, (int)round($maxI * 2.5)));
        $mg = report_manager_gaps($hb['fleet'], $start, $we, $tMgr, count($hb['witness_ids']));

        // maintenance windows overlapping the period
        $mw = db()->prepare("SELECT id, scope, scope_id, UNIX_TIMESTAMP(starts_at) s, UNIX_TIMESTAMP(ends_at) e, reason, status, suppress_alerts
                               FROM maintenance_windows WHERE status <> 'cancelled'
                                AND starts_at < FROM_UNIXTIME(?) AND ends_at > FROM_UNIXTIME(?)");
        $mw->execute([$end, $start]);
        $windows = $mw->fetchAll(PDO::FETCH_ASSOC);

        // firewall.offline incidents (evidence the manager was alive and judged the firewall down)
        $incs = [];
        if ($ids) {
            [$ph, $p] = report_in_list($ids);
            $st = db()->prepare("SELECT id, firewall_id, alert_type, severity, status, title, suppressed, suppressed_reason,
                                        UNIX_TIMESTAMP(first_seen_at) fs, UNIX_TIMESTAMP(resolved_at) rs
                                   FROM alert_incidents
                                  WHERE firewall_id IN ($ph) AND first_seen_at < FROM_UNIXTIME(?)
                                    AND (resolved_at IS NULL OR resolved_at >= FROM_UNIXTIME(?))
                                  ORDER BY first_seen_at");
            $st->execute(array_merge($p, [$end, $start]));
            $incs = $st->fetchAll(PDO::FETCH_ASSOC);
        }

        $perFw = []; $avail = [];
        foreach ($fws as $f) {
            $id = (int)$f['id'];
            $th = report_thresholds((int)($f['checkin_interval'] ?? 180), (int)($f['offline_alert_threshold'] ?? 300));
            $fm = [];
            foreach ($windows as $w) {
                $applies = ($w['scope'] === 'firewall' && (int)$w['scope_id'] === $id)
                        || ($w['scope'] === 'site' && $f['site_id'] && (int)$w['scope_id'] === (int)$f['site_id'])
                        || ($w['scope'] === 'customer' && $f['customer_id'] && (int)$w['scope_id'] === (int)$f['customer_id']);
                if ($applies) { $fm[] = [(int)$w['s'], (int)$w['e']]; }
            }
            $fi = [];
            foreach ($incs as $i) {
                if ((int)$i['firewall_id'] === $id && $i['alert_type'] === 'firewall.offline') {
                    $fi[] = [(int)$i['fs'], $i['rs'] ? (int)$i['rs'] : $now];
                }
            }
            $a = report_availability([
                'start' => $start, 'end' => $end, 'now' => $now,
                'enrolled_at' => $f['enrolled_ts'] ? (int)$f['enrolled_ts'] : null,
                'horizon' => $hb['horizon'],
                'hb' => $hb['per'][$id] ?? [], 'fleet' => $hb['fleet'],
                't_miss' => $th['t_miss'], 't_out' => $th['t_out'],
                'mgr_gaps' => $mg['gaps'], 'mgr_trusted' => $mg['trusted'],
                'maintenance' => $fm, 'incidents' => $fi,
            ]);
            $a['maintenance_scheduled'] = report_iv_sum(report_iv_clip($fm, $start, $we));
            $a['heartbeats'] = count(array_filter($hb['per'][$id] ?? [], fn($t) => $t >= $start));
            $a['thresholds'] = $th;
            $a['events'] = array_slice($a['events'], 0, 200);
            $avail[$id] = $a;
        }
        $aggregate = report_availability_aggregate(array_values($avail));
        $fleetEvents = array_map(fn($g) => ['start' => $g[0], 'end' => $g[1], 'seconds' => $g[1] - $g[0]],
                                 report_iv_clip($mg['gaps'], $start, $we));

        // ---- latency / packet loss / gateways ---------------------------------------------
        $lat = []; $latAll = []; $latDay = [];
        $gw = []; $gwEvents = [];
        if ($ids) {
            [$ph, $p] = report_in_list($ids);
            $st = db()->prepare("SELECT firewall_id, latency_ms, UNIX_TIMESTAMP(measured_at) t FROM firewall_latency
                                  WHERE firewall_id IN ($ph) AND measured_at >= FROM_UNIXTIME(?) AND measured_at < FROM_UNIXTIME(?)");
            $st->execute(array_merge($p, [$start, $we]));
            $vals = [];
            while ($r = $st->fetch(PDO::FETCH_NUM)) {
                $vals[(int)$r[0]][] = (float)$r[1]; $latAll[] = (float)$r[1];
            }
            foreach ($ids as $id) { $lat[$id] = report_stats($vals[$id] ?? []); }

            $gwNames = db()->prepare("SELECT firewall_id, name, status, latency_ms, loss_percent, is_default FROM firewall_gateways WHERE firewall_id IN ($ph) ORDER BY firewall_id, name");
            $gwNames->execute($p);
            $cur = $gwNames->fetchAll(PDO::FETCH_ASSOC);
            $ev = db()->prepare("SELECT firewall_id, gateway_name, from_status, to_status, loss_percent, latency_ms, UNIX_TIMESTAMP(occurred_at) t
                                   FROM firewall_gateway_events WHERE firewall_id IN ($ph) AND occurred_at >= FROM_UNIXTIME(?) AND occurred_at < FROM_UNIXTIME(?) ORDER BY occurred_at, id");
            $ev->execute(array_merge($p, [$start, $we]));
            $evs = $ev->fetchAll(PDO::FETCH_ASSOC);
            $pre = db()->prepare("SELECT e.firewall_id, e.gateway_name, e.to_status FROM firewall_gateway_events e
                                   JOIN (SELECT MAX(id) mid FROM firewall_gateway_events WHERE firewall_id IN ($ph) AND occurred_at < FROM_UNIXTIME(?) GROUP BY firewall_id, gateway_name) m ON e.id = m.mid");
            $pre->execute(array_merge($p, [$start]));
            $before = [];
            foreach ($pre->fetchAll(PDO::FETCH_ASSOC) as $r) { $before[$r['firewall_id'] . '|' . $r['gateway_name']] = report_gateway_state($r['to_status']); }
            $groups = [];
            foreach ($cur as $c) { $groups[$c['firewall_id'] . '|' . $c['name']] = ['cur' => $c, 'ev' => []]; }
            foreach ($evs as $e) { $groups[$e['firewall_id'] . '|' . $e['gateway_name']]['ev'][] = $e; $groups[$e['firewall_id'] . '|' . $e['gateway_name']]['cur'] ??= ['firewall_id' => $e['firewall_id'], 'name' => $e['gateway_name'], 'status' => null]; }
            foreach ($groups as $key => $g) {
                $events = array_map(fn($e) => [(int)$e['t'], report_gateway_state($e['from_status']), report_gateway_state($e['to_status'])], $g['ev']);
                $d = report_state_durations($events, $before[$key] ?? null, $start, $we);
                $loss = array_values(array_filter(array_map(fn($e) => $e['loss_percent'] === null ? null : (float)$e['loss_percent'], $g['ev']), fn($v) => $v !== null));
                $gw[] = ['firewall_id' => (int)$g['cur']['firewall_id'], 'name' => (string)$g['cur']['name'], 'current_status' => $g['cur']['status'] ?? null,
                         'transitions' => count($g['ev']), 'loss_events' => count($loss), 'loss_max' => $loss ? max($loss) : null,
                         'loss_avg' => $loss ? array_sum($loss) / count($loss) : null] + $d;
                foreach ($g['ev'] as $e) {
                    if (report_gateway_state($e['to_status']) === 'down') {
                        $gwEvents[] = ['firewall_id' => (int)$e['firewall_id'], 'name' => $e['gateway_name'], 'at' => (int)$e['t'], 'to' => $e['to_status'], 'loss' => $e['loss_percent']];
                    }
                }
            }
        }

        // ---- VPN -----------------------------------------------------------------------------
        $vpn = []; $vpnCur = [];
        if ($ids) {
            [$ph, $p] = report_in_list($ids);
            $t = db()->prepare("SELECT firewall_id, vpn_type, name, status, enabled FROM firewall_vpn_tunnels WHERE firewall_id IN ($ph) ORDER BY firewall_id, vpn_type, name");
            $t->execute($p); $vpnCur = $t->fetchAll(PDO::FETCH_ASSOC);
            $ev = db()->prepare("SELECT firewall_id, vpn_type, name, from_status, to_status, UNIX_TIMESTAMP(occurred_at) t FROM firewall_vpn_events
                                  WHERE firewall_id IN ($ph) AND occurred_at >= FROM_UNIXTIME(?) AND occurred_at < FROM_UNIXTIME(?) ORDER BY occurred_at, id");
            $ev->execute(array_merge($p, [$start, $we])); $evs = $ev->fetchAll(PDO::FETCH_ASSOC);
            $pre = db()->prepare("SELECT e.firewall_id, e.vpn_type, e.name, e.to_status FROM firewall_vpn_events e
                                   JOIN (SELECT MAX(id) mid FROM firewall_vpn_events WHERE firewall_id IN ($ph) AND occurred_at < FROM_UNIXTIME(?) GROUP BY firewall_id, vpn_type, name) m ON e.id = m.mid");
            $pre->execute(array_merge($p, [$start]));
            $before = [];
            foreach ($pre->fetchAll(PDO::FETCH_ASSOC) as $r) { $before[$r['firewall_id'] . '|' . $r['vpn_type'] . '|' . $r['name']] = report_vpn_state($r['to_status']); }
            $groups = [];
            foreach ($vpnCur as $c) { $groups[$c['firewall_id'] . '|' . $c['vpn_type'] . '|' . $c['name']] = ['c' => $c, 'ev' => []]; }
            foreach ($evs as $e) {
                $k = $e['firewall_id'] . '|' . $e['vpn_type'] . '|' . $e['name'];
                $groups[$k]['c'] ??= ['firewall_id' => $e['firewall_id'], 'vpn_type' => $e['vpn_type'], 'name' => $e['name'], 'status' => null];
                $groups[$k]['ev'][] = $e;
            }
            foreach ($groups as $k => $g) {
                $events = array_map(fn($e) => [(int)$e['t'], report_vpn_state($e['from_status']), report_vpn_state($e['to_status'])], $g['ev']);
                $vpn[] = ['firewall_id' => (int)$g['c']['firewall_id'], 'type' => $g['c']['vpn_type'], 'name' => $g['c']['name'],
                          'current_status' => $g['c']['status'] ?? null, 'transitions' => count($g['ev'])]
                       + report_state_durations($events, $before[$k] ?? null, $start, $we);
            }
        }

        // ---- incidents --------------------------------------------------------------------------
        $incidents = [];
        foreach ($incs as $i) {
            $incidents[] = ['id' => (int)$i['id'], 'firewall_id' => (int)$i['firewall_id'], 'type' => $i['alert_type'], 'severity' => $i['severity'],
                'title' => mb_substr((string)$i['title'], 0, 200), 'first_seen' => (int)$i['fs'], 'resolved_at' => $i['rs'] ? (int)$i['rs'] : null,
                'open_at_end' => !$i['rs'] || (int)$i['rs'] >= $end, 'status' => $i['status'], 'maintenance_suppressed' => (bool)$i['suppressed']];
        }

        // ---- maintenance + updates ----------------------------------------------------------------
        $mwOut = [];
        foreach ($windows as $w) {
            $touch = false;
            foreach ($fws as $f) {
                if (($w['scope'] === 'firewall' && (int)$w['scope_id'] === (int)$f['id'])
                 || ($w['scope'] === 'site' && $f['site_id'] && (int)$w['scope_id'] === (int)$f['site_id'])
                 || ($w['scope'] === 'customer' && $f['customer_id'] && (int)$w['scope_id'] === (int)$f['customer_id'])) { $touch = true; break; }
            }
            if ($touch) {
                $mwOut[] = ['scope' => $w['scope'], 'start' => (int)$w['s'], 'end' => (int)$w['e'], 'reason' => mb_substr((string)$w['reason'], 0, 200), 'status' => $w['status']];
            }
        }
        $updates = [];
        if ($ids && report_table_exists('update_campaign_targets')) {
            [$ph, $p] = report_in_list($ids);
            $st = db()->prepare("SELECT t.firewall_id, c.name campaign, c.operation, t.status, t.version_before, t.version_after, UNIX_TIMESTAMP(t.completed_at) done, UNIX_TIMESTAMP(t.dispatched_at) disp
                                   FROM update_campaign_targets t JOIN update_campaigns c ON c.id = t.campaign_id
                                  WHERE t.firewall_id IN ($ph) AND ((t.dispatched_at >= FROM_UNIXTIME(?) AND t.dispatched_at < FROM_UNIXTIME(?))
                                     OR (t.completed_at >= FROM_UNIXTIME(?) AND t.completed_at < FROM_UNIXTIME(?)))
                                  ORDER BY t.dispatched_at");
            $st->execute(array_merge($p, [$start, $we, $start, $we]));
            $updates = $st->fetchAll(PDO::FETCH_ASSOC);
        }

        // ---- backups --------------------------------------------------------------------------------
        $staleHours = max(1, (int)report_setting('report_backup_stale_hours', 48));
        $backups = [];
        foreach ($fws as $f) {
            $id = (int)$f['id'];
            $b = db()->prepare("SELECT UNIX_TIMESTAMP(uploaded_at) u, validated FROM backups
                                 WHERE firewall_id = ? AND uploaded_at IS NOT NULL AND uploaded_at < FROM_UNIXTIME(?) ORDER BY uploaded_at");
            $b->execute([$id, $we]);
            $rows = $b->fetchAll(PDO::FETCH_ASSOC);
            $valid = []; $invalidInPeriod = 0; $validInPeriod = 0; $lastBefore = null;
            foreach ($rows as $r) {
                $u = (int)$r['u'];
                if ((int)$r['validated'] === 1) {
                    $valid[] = $u;
                    if ($u >= $start) { $validInPeriod++; } else { $lastBefore = $u; }
                } elseif ($u >= $start) { $invalidInPeriod++; }
            }
            $pts = array_merge($lastBefore !== null ? [$lastBefore] : [], array_filter($valid, fn($u) => $u >= $start));
            $maxGap = null;
            if ($pts) {
                $maxGap = 0; $prev = max($pts[0], $start);
                foreach (array_merge(array_slice($pts, 1), [$we]) as $u) { $maxGap = max($maxGap, $u - $prev); $prev = $u; }
            }
            $latest = $valid ? max($valid) : null;
            $failedInc = count(array_filter($incidents, fn($i) => $i['firewall_id'] === $id && $i['type'] === 'backup.failed'));
            $backups[$id] = [
                'latest_valid' => $latest, 'age_seconds' => $latest ? $now - $latest : null,
                'fresh' => $latest ? ($now - $latest) <= $staleHours * 3600 : null,
                'valid_in_period' => $validInPeriod, 'invalid_in_period' => $invalidInPeriod,
                'failure_incidents' => $failedInc, 'longest_gap_seconds' => $maxGap,
                'last_status' => $f['last_backup_status'] ?? null, 'stale_after_hours' => $staleHours,
            ];
        }

        // ---- configuration changes ------------------------------------------------------------------
        $changes = []; $drift = [];
        if ($ids) {
            [$ph, $p] = report_in_list($ids);
            $st = db()->prepare("SELECT firewall_id, action, success, UNIX_TIMESTAMP(occurred_at) t FROM audit_log
                                  WHERE firewall_id IN ($ph) AND occurred_at >= FROM_UNIXTIME(?) AND occurred_at < FROM_UNIXTIME(?)
                                    AND (action LIKE 'backup.restore%' OR action LIKE 'drift.%' OR action LIKE 'update.%' OR action IN ('command.action','command.queue','command.raw','firewall.assign','bulk.execute'))
                                  ORDER BY occurred_at LIMIT 300");
            $st->execute(array_merge($p, [$start, $we]));
            // Actor names and messages are deliberately left out: a customer-facing report does not need staff identities or raw command text.
            $changes = array_map(fn($r) => ['firewall_id' => (int)$r['firewall_id'], 'action' => $r['action'], 'success' => (bool)$r['success'], 'at' => (int)$r['t']], $st->fetchAll(PDO::FETCH_ASSOC));
            if (report_table_exists('config_drift')) {
                $st = db()->prepare("SELECT d.firewall_id, d.status, d.change_count, UNIX_TIMESTAMP(d.first_detected_at) fd, d.acknowledged_at IS NOT NULL ack,
                                            UNIX_TIMESTAMP(b.set_at) baseline_at
                                       FROM config_drift d LEFT JOIN config_baselines b ON b.firewall_id = d.firewall_id AND b.is_current = 1
                                      WHERE d.firewall_id IN ($ph)");
                $st->execute($p);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $drift[(int)$r['firewall_id']] = $r; }
            }
        }

        // ---- security findings (latest AI scan per firewall; informational) -----------------------------
        $scans = [];
        if ($ids && report_table_exists('ai_scan_reports')) {
            [$ph, $p] = report_in_list($ids);
            $st = db()->prepare("SELECT r.firewall_id, r.overall_grade, UNIX_TIMESTAMP(r.created_at) t,
                                        (SELECT COUNT(*) FROM ai_scan_findings f WHERE f.report_id = r.id AND f.severity = 'critical') crit,
                                        (SELECT COUNT(*) FROM ai_scan_findings f WHERE f.report_id = r.id AND f.severity = 'high') high
                                   FROM ai_scan_reports r JOIN (SELECT MAX(id) mid FROM ai_scan_reports WHERE firewall_id IN ($ph) GROUP BY firewall_id) m ON m.mid = r.id");
            $st->execute($p);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $scans[(int)$r['firewall_id']] = $r; }
        }

        // ---- template compliance (optional module) -------------------------------------------------------
        $templates = ['available' => false, 'rows' => [], 'note' => 'Template compliance data is not available on this installation.'];
        if (function_exists('template_compliance_report_rows')) {
            try {
                $rows = template_compliance_report_rows($ids, $start, $end);
                if (is_array($rows)) { $templates = ['available' => true, 'rows' => array_slice($rows, 0, 500), 'note' => '']; }
            } catch (Throwable $e) {
                $templates['note'] = 'Template compliance data could not be read.';
            }
        }

        // ---- inventory --------------------------------------------------------------------------------------
        $inventory = [];
        foreach ($fws as $f) {
            $inventory[] = [
                'id' => (int)$f['id'], 'hostname' => (string)$f['hostname'], 'customer_id' => $f['customer_id'] ? (int)$f['customer_id'] : null,
                'site_id' => $f['site_id'] ? (int)$f['site_id'] : null,
                'platform' => function_exists('platform_summary') ? platform_summary($f) : trim(($f['platform_family'] ?? '') . ' ' . ($f['platform_os_version'] ?? '')),
                'platform_family' => $f['platform_family'] ?? null, 'edition' => $f['platform_edition'] ?? null,
                'os_version' => $f['platform_os_version'] ?? ($f['current_version'] ?? null),
                'agent_version' => $f['agent_version'] ?? null, 'checkin_interval' => (int)($f['checkin_interval'] ?? 0),
                'last_checkin' => $f['last_checkin_ts'] ? (int)$f['last_checkin_ts'] : null, 'status' => $f['status'] ?? null,
                'alerts_enabled' => (bool)($f['alerts_enabled'] ?? 1), 'updates_available' => (bool)($f['updates_available'] ?? 0),
                'available_version' => $f['available_version'] ?? null, 'reboot_required' => (bool)($f['reboot_required'] ?? 0),
                'last_update_check' => $f['last_update_check_ts'] ? (int)$f['last_update_check_ts'] : null,
                'enrolled_at' => $f['enrolled_ts'] ? (int)$f['enrolled_ts'] : null,
            ];
        }

        $retentionDays = (int)report_setting('telemetry_retention_days', 90);
        $coverageNote = ($hb['horizon'] !== null && $hb['horizon'] > $start)
            ? 'Telemetry begins ' . gmdate('Y-m-d H:i', $hb['horizon']) . ' UTC; earlier time is reported as unknown.' : '';
        if ($coverageNote) { $exclusions[] = $coverageNote; }
        if ($we < $end) { $exclusions[] = 'Period not yet complete: time after generation is excluded.'; }
        if (!$mg['trusted']) { $exclusions[] = 'Fewer than two firewalls reported, so manager-wide gaps cannot be told apart from a firewall outage; silences without an alert incident are unknown.'; }

        $snap = [
            'schema' => 1,
            'generator' => ['app_version' => defined('APP_VERSION') ? APP_VERSION : null, 'metric_definitions' => 'v1'],
            'generated_at' => $now,
            'scope' => ['type' => $scope['type'], 'id' => $scope['id'], 'name' => $scope['name'], 'customer_id' => $scope['customer_id'],
                        'customer_name' => $scope['customer']['name'] ?? null, 'firewall_count' => count($fws)],
            'period' => ['start' => $start, 'end' => $end, 'effective_end' => $we, 'timezone' => $tzName, 'label' => $params['period']['label'] ?? '',
                         'hours' => ($end - $start) / 3600.0, 'days' => report_period_days($start, $end, $tzName)],
            'sections' => $sections,
            'provenance' => [
                'heartbeat_sources' => $hb['sources'], 'telemetry_retention_days' => $retentionDays, 'data_horizon' => $hb['horizon'],
                'firewalls_reporting_fleetwide' => count($hb['witness_ids']), 'manager_gap_threshold_seconds' => $tMgr,
                'manager_gaps_trusted' => $mg['trusted'], 'exclusions' => $exclusions,
                'backup_stale_hours' => $staleHours,
                'sla' => 'No contractual SLA calculation is configured; figures are observations, not SLA compliance.',
            ],
            'inventory' => $inventory,
            'availability' => ['per_firewall' => $avail, 'aggregate' => $aggregate, 'manager_gaps' => $fleetEvents],
            'latency' => ['per_firewall' => $lat, 'overall' => report_stats($latAll), 'gateways' => $gw, 'gateway_down_events' => array_slice($gwEvents, 0, 200)],
            'vpn' => ['tunnels' => $vpn],
            'incidents' => $incidents,
            'maintenance' => ['windows' => $mwOut, 'updates' => $updates],
            'backups' => $backups,
            'config_changes' => ['events' => $changes, 'drift' => $drift],
            'scans' => $scans,
            'templates' => $templates,
        ];
        $snap['recommendations'] = report_recommendations($snap, $byId, $now);
        $snap['summary'] = report_summarize($snap);
        return $snap;
    }
}

if (!function_exists('report_summarize')) {
    /** Headline numbers used by the executive summary and (aggregated only) by AI. */
    function report_summarize(array $s): array {
        $a = $s['availability']['aggregate'];
        $open = count(array_filter($s['incidents'], fn($i) => $i['open_at_end']));
        $backupsStale = count(array_filter($s['backups'], fn($b) => $b['fresh'] === false || $b['latest_valid'] === null));
        $drifted = count(array_filter($s['config_changes']['drift'], fn($d) => $d['status'] === 'drifted' && !$d['ack']));
        return [
            'firewalls' => $s['scope']['firewall_count'],
            'availability' => $a['availability'], 'continuity' => $a['continuity'], 'coverage' => $a['coverage'],
            'confirmed_outage_seconds' => $a['confirmed_outage'], 'missed_checkin_seconds' => $a['missed_checkin'],
            'monitoring_gap_seconds' => $a['monitoring_gap'], 'maintenance_seconds' => $a['planned_maintenance'],
            'unknown_seconds' => $a['unknown'],
            'latency_p95_ms' => $s['latency']['overall']['p95'], 'latency_samples' => $s['latency']['overall']['count'],
            'incidents_in_period' => count($s['incidents']), 'incidents_open_at_end' => $open,
            'backups_stale_or_missing' => $backupsStale, 'drifted_unacknowledged' => $drifted,
            'recommendation_count' => count($s['recommendations']),
        ];
    }
}

if (!function_exists('report_recommendations')) {
    /**
     * Deterministic follow-ups. Each rule fires only on evidence in the snapshot
     * and names that evidence; nothing is inferred beyond it.
     */
    function report_recommendations(array $s, array $fwById, int $now): array {
        $out = []; $add = function (string $id, string $sev, string $text, array $fw, string $evidence) use (&$out) {
            $out[] = ['id' => $id, 'severity' => $sev, 'text' => $text, 'firewall_ids' => array_values(array_unique($fw)), 'evidence' => $evidence];
        };
        $name = fn($id) => $fwById[$id]['hostname'] ?? ('#' . $id);
        foreach ($s['availability']['per_firewall'] as $id => $a) {
            if ($a['confirmed_outage'] > 0) {
                $n = count(array_filter($a['events'], fn($e) => $e['class'] === 'confirmed_outage'));
                $add('outage', $a['confirmed_outage'] >= 3600 ? 'high' : 'medium',
                     'Review the cause of confirmed outages on ' . $name($id) . ' and confirm upstream circuit or power stability.', [$id],
                     $n . ' confirmed outage period(s) totalling ' . report_fmt_duration($a['confirmed_outage']));
            }
            if ($a['coverage'] !== null && $a['coverage'] < 0.9) {
                $add('coverage', 'medium', 'Check agent health and check-in connectivity for ' . $name($id) . '; monitoring coverage was low.', [$id],
                     'Coverage ' . report_fmt_pct($a['coverage'], 1));
            }
        }
        foreach ($s['backups'] as $id => $b) {
            if ($b['latest_valid'] === null) {
                $add('backup_none', 'high', 'No validated configuration backup exists for ' . $name($id) . '. Run and verify a backup.', [$id], 'No validated backup recorded');
            } elseif ($b['fresh'] === false) {
                $add('backup_stale', 'medium', 'Latest validated backup for ' . $name($id) . ' is older than ' . $b['stale_after_hours'] . ' hours.', [$id],
                     'Age ' . report_fmt_duration($b['age_seconds']));
            }
            if ($b['invalid_in_period'] > 0 || $b['failure_incidents'] > 0) {
                $add('backup_failures', 'medium', 'Investigate backup failures for ' . $name($id) . '.', [$id],
                     $b['invalid_in_period'] . ' unvalidated upload(s), ' . $b['failure_incidents'] . ' backup failure incident(s)');
            }
        }
        foreach ($s['inventory'] as $f) {
            if ($f['updates_available']) { $add('updates', 'low', 'Schedule firmware/package updates for ' . $f['hostname'] . ($f['available_version'] ? ' (to ' . $f['available_version'] . ')' : '') . '.', [$f['id']], 'Updates reported available'); }
            if ($f['reboot_required']) { $add('reboot', 'low', 'Plan a reboot for ' . $f['hostname'] . ' to finish pending updates.', [$f['id']], 'Reboot required flag set'); }
        }
        foreach ($s['config_changes']['drift'] as $id => $d) {
            if ($d['status'] === 'drifted' && !$d['ack']) {
                $add('drift', 'medium', 'Review or acknowledge unapproved configuration drift on ' . $name($id) . '.', [$id], (int)$d['change_count'] . ' changed item(s) versus baseline');
            }
        }
        foreach ($s['incidents'] as $i) {
            if ($i['open_at_end'] && $i['severity'] === 'critical') {
                $add('incident_open', 'high', 'Resolve the open critical incident on ' . $name($i['firewall_id']) . ': ' . $i['title'], [$i['firewall_id']], 'Incident #' . $i['id'] . ' open since ' . gmdate('Y-m-d', $i['first_seen']));
            }
        }
        foreach ($s['latency']['gateways'] as $g) {
            if ($g['observed'] && $g['episodes'] >= 3) {
                $add('gateway_flap', 'medium', 'Gateway ' . $g['name'] . ' on ' . $name($g['firewall_id']) . ' went down repeatedly; review the circuit.', [$g['firewall_id']], $g['episodes'] . ' down episodes, ' . report_fmt_duration($g['down']) . ' down');
            }
        }
        foreach ($s['vpn']['tunnels'] as $t) {
            if ($t['current_status'] === 'down') {
                $add('vpn_down', 'medium', 'VPN tunnel ' . $t['name'] . ' on ' . $name($t['firewall_id']) . ' is currently down.', [$t['firewall_id']], 'Current status down');
            }
        }
        foreach ($s['latency']['per_firewall'] as $id => $l) {
            if ($l['p95'] !== null && $l['p95'] > 150) {
                $add('latency', 'low', 'WAN latency on ' . $name($id) . ' was high; review the upstream path.', [$id], 'p95 ' . round($l['p95'], 1) . ' ms over ' . $l['count'] . ' samples');
            }
        }
        if ($s['templates']['available']) {
            foreach ($s['templates']['rows'] as $r) {
                if (($r['status'] ?? '') === 'noncompliant') {
                    $add('template', 'medium', 'Remediate or document an exception for template non-compliance on ' . $name((int)($r['firewall_id'] ?? 0)) . '.', [(int)($r['firewall_id'] ?? 0)], 'Template ' . ($r['template'] ?? '?') . ' non-compliant');
                }
            }
        }
        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($out, fn($a, $b) => [$order[$a['severity']], $a['id']] <=> [$order[$b['severity']], $b['id']]);
        return $out;
    }
}
