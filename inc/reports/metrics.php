<?php
/**
 * Report metric engine. Pure functions: no database, no clock, no globals, so
 * every definition can be tested with synthetic timelines.
 *
 * Definitions (also printed in each report's appendix, see formula_text()):
 *
 *  Check-in evidence ("heartbeat"): a timestamp at which the manager stored a
 *  telemetry row for the firewall. Consecutive heartbeats no more than
 *  T_miss apart prove the firewall was reachable between them ("up").
 *
 *  A longer silence between heartbeats is split, in this order of precedence:
 *   1. monitoring gap  - the manager itself recorded no heartbeat from ANY
 *      firewall for longer than T_mgr, with at least two firewalls reporting
 *      around it. The customer's firewall is not blamed.
 *   2. planned maintenance - covered by a non-cancelled maintenance window.
 *   3. of the remainder R: R < T_out  -> missed check-in (not an outage);
 *      R >= T_out and the manager demonstrably was alive (another firewall
 *      reported inside the silence, or a firewall.offline incident overlaps)
 *      -> confirmed outage; otherwise -> unknown status.
 *  Time before the manager held any data, before the firewall was enrolled, or
 *  after the report was generated is "unknown", "not enrolled" or "future" and
 *  never counts as up or down.
 *
 *  measurable   = up + missed_checkin + confirmed_outage
 *  availability = (measurable - confirmed_outage) / measurable, or null (n/a)
 *  Aggregates are device-time-weighted: sum(measurable-outage) / sum(measurable).
 *
 * @since 3.79.0
 */

if (!function_exists('report_iv_merge')) {
    /** Sort and merge overlapping/adjacent intervals. @param array<int,array{0:int,1:int}> $iv */
    function report_iv_merge(array $iv): array {
        $iv = array_values(array_filter($iv, fn($i) => $i[1] > $i[0]));
        usort($iv, fn($a, $b) => $a[0] <=> $b[0]);
        $out = [];
        foreach ($iv as $i) {
            if ($out && $i[0] <= $out[count($out) - 1][1]) {
                $out[count($out) - 1][1] = max($out[count($out) - 1][1], $i[1]);
            } else {
                $out[] = [$i[0], $i[1]];
            }
        }
        return $out;
    }
}
if (!function_exists('report_iv_clip')) {
    function report_iv_clip(array $iv, int $s, int $e): array {
        $out = [];
        foreach ($iv as $i) {
            $a = max($i[0], $s); $b = min($i[1], $e);
            if ($b > $a) { $out[] = [$a, $b]; }
        }
        return $out;
    }
}
if (!function_exists('report_iv_intersect')) {
    function report_iv_intersect(array $a, array $b): array {
        $a = report_iv_merge($a); $b = report_iv_merge($b);
        $out = []; $i = $j = 0;
        while ($i < count($a) && $j < count($b)) {
            $s = max($a[$i][0], $b[$j][0]); $e = min($a[$i][1], $b[$j][1]);
            if ($e > $s) { $out[] = [$s, $e]; }
            if ($a[$i][1] < $b[$j][1]) { $i++; } else { $j++; }
        }
        return $out;
    }
}
if (!function_exists('report_iv_subtract')) {
    function report_iv_subtract(array $a, array $b): array {
        $out = [];
        $b = report_iv_merge($b);
        foreach (report_iv_merge($a) as [$s, $e]) {
            $cur = $s;
            foreach ($b as [$bs, $be]) {
                if ($be <= $cur || $bs >= $e) { continue; }
                if ($bs > $cur) { $out[] = [$cur, $bs]; }
                $cur = max($cur, $be);
            }
            if ($cur < $e) { $out[] = [$cur, $e]; }
        }
        return $out;
    }
}
if (!function_exists('report_iv_sum')) {
    function report_iv_sum(array $iv): int {
        $t = 0;
        foreach (report_iv_merge($iv) as $i) { $t += $i[1] - $i[0]; }
        return $t;
    }
}
if (!function_exists('report_has_point_between')) {
    /** True when sorted $points holds a value strictly inside (a,b). */
    function report_has_point_between(array $points, int $a, int $b): bool {
        $lo = 0; $hi = count($points);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($points[$mid] <= $a) { $lo = $mid + 1; } else { $hi = $mid; }
        }
        return $lo < count($points) && $points[$lo] < $b;
    }
}

if (!function_exists('report_thresholds')) {
    /**
     * Thresholds derived from a firewall's check-in interval (seconds).
     * T_miss: silence tolerated before a check-in counts as missed (2.5 x interval, >= 120 s).
     * T_out:  continuous silence needed to call an outage: the larger of 900 s, the
     *         firewall's offline alert threshold and 2 x T_miss. Silence between T_miss and
     *         T_out is a missed check-in, not an outage.
     */
    function report_thresholds(int $interval, int $offlineThreshold = 300): array {
        $interval = max(30, min(3600, $interval ?: 180));
        $tMiss = max(120, (int)round($interval * 2.5));
        return ['interval' => $interval, 't_miss' => $tMiss, 't_out' => max(900, $offlineThreshold, 2 * $tMiss)];
    }
}

if (!function_exists('report_manager_gaps')) {
    /**
     * Periods in which no firewall at all produced a heartbeat.
     *
     * @param int[]  $fleetPoints sorted union of every firewall's heartbeats
     * @param int    $witnesses   distinct firewalls with at least one heartbeat in the window
     * @return array{gaps:array,trusted:bool,threshold:int}
     *   trusted=false when fewer than two firewalls report: one firewall's silence
     *   cannot be told apart from the manager's own, so gaps are not asserted as such.
     */
    function report_manager_gaps(array $fleetPoints, int $ws, int $we, int $threshold, int $witnesses): array {
        $gaps = [];
        $prev = null;   // latest point at or before the window start
        $in = [];
        foreach ($fleetPoints as $p) {
            if ($p <= $ws) { $prev = $p; continue; }
            if ($p >= $we) { break; }
            $in[] = $p;
        }
        $anchors = array_merge([$prev ?? $ws], $in, [$we]);
        for ($i = 0; $i < count($anchors) - 1; $i++) {
            if ($anchors[$i + 1] - $anchors[$i] > $threshold) {
                $gaps[] = [max($anchors[$i], $ws), $anchors[$i + 1]];
            }
        }
        return ['gaps' => report_iv_merge($gaps), 'trusted' => $witnesses >= 2, 'threshold' => $threshold];
    }
}

if (!function_exists('report_availability')) {
    /**
     * Classify every second of one firewall's reporting window.
     *
     * @param array $in start,end,now,enrolled_at?,horizon? (earliest instant the manager
     *   held any data), hb (sorted own heartbeats), fleet (sorted union of all heartbeats),
     *   t_miss,t_out, mgr_gaps, mgr_trusted, maintenance, incidents (offline incident intervals)
     */
    function report_availability(array $in): array {
        $start = (int)$in['start']; $end = (int)$in['end'];
        $now = (int)($in['now'] ?? $end);
        $we = max(min($end, $now), $start);
        $enr = isset($in['enrolled_at']) ? (int)$in['enrolled_at'] : null;
        $tMiss = (int)$in['t_miss']; $tOut = (int)$in['t_out'];
        $hb = $in['hb'] ?? []; $fleet = $in['fleet'] ?? [];
        $mg = $in['mgr_gaps'] ?? []; $mgTrusted = (bool)($in['mgr_trusted'] ?? false);
        $maint = report_iv_merge($in['maintenance'] ?? []);
        $inc = report_iv_merge($in['incidents'] ?? []);

        $r = ['up' => 0, 'missed_checkin' => 0, 'confirmed_outage' => 0, 'monitoring_gap' => 0,
              'planned_maintenance' => 0, 'unknown' => 0, 'not_enrolled' => 0, 'future' => 0,
              'total' => $end - $start, 'events' => []];

        // Window actually observable: after enrolment, before "now".
        $enrolledAt = ($enr !== null && $enr > $start) ? $enr : $start;
        $ws = min($enrolledAt, $we);
        $ws = max($ws, $start);
        $r['not_enrolled'] = $ws - $start;
        $r['future'] = $end - $we;
        if ($we <= $ws) {
            return $r + report_availability_derive($r);
        }

        // Before the manager held any data nothing can be said.
        $horizon = $in['horizon'] ?? null;
        if ($horizon === null || $horizon >= $we) {
            $r['unknown'] = $we - $ws;
            $r['events'][] = ['class' => 'unknown', 'start' => $ws, 'end' => $we];
            return $r + report_availability_derive($r);
        }
        if ($horizon > $ws) {
            $r['unknown'] += $horizon - $ws;
            $r['events'][] = ['class' => 'unknown', 'start' => $ws, 'end' => $horizon];
            $ws = $horizon;
        }

        // Anchors: the last heartbeat before the window (if recent enough to
        // matter), the in-window heartbeats, and a virtual anchor at each edge.
        $anchors = []; $pre = null;
        foreach ($hb as $t) {
            if ($t <= $ws) { $pre = $t; continue; }
            if ($t >= $we) { break; }
            $anchors[] = $t;
        }
        array_unshift($anchors, $pre ?? $ws);
        $anchors[] = $we;
        // A heartbeat exactly at the window end still proves liveness; nothing to add.

        $silent = [];
        for ($i = 0; $i < count($anchors) - 1; $i++) {
            $x = $anchors[$i]; $y = $anchors[$i + 1];
            if ($y - $x <= $tMiss) {
                $r['up'] += max(0, min($y, $we) - max($x, $ws));
            } else {
                $silent[] = [$x, $y];
            }
        }

        foreach ($silent as [$x, $y]) {
            $S = report_iv_clip([[$x, $y]], $ws, $we);
            $alive = report_has_point_between($fleet, $x, $y);
            $mgPart = report_iv_intersect($S, $mg);
            $rem = report_iv_subtract($S, $mg);

            $gapSec = report_iv_sum($mgPart);
            if ($gapSec > 0) {
                if ($mgTrusted) {
                    $r['monitoring_gap'] += $gapSec;
                    foreach ($mgPart as $p) { $r['events'][] = ['class' => 'monitoring_gap', 'start' => $p[0], 'end' => $p[1]]; }
                } else {
                    // Cannot tell the manager from the firewall: unknown, unless an incident proves it.
                    $conf = report_iv_intersect($mgPart, $inc);
                    $r['confirmed_outage'] += report_iv_sum($conf);
                    foreach ($conf as $p) { $r['events'][] = ['class' => 'confirmed_outage', 'start' => $p[0], 'end' => $p[1]]; }
                    $unk = report_iv_subtract($mgPart, $inc);
                    $r['unknown'] += report_iv_sum($unk);
                    foreach ($unk as $p) { $r['events'][] = ['class' => 'unknown', 'start' => $p[0], 'end' => $p[1]]; }
                }
            }

            $mPart = report_iv_intersect($rem, $maint);
            $r['planned_maintenance'] += report_iv_sum($mPart);
            foreach ($mPart as $p) { $r['events'][] = ['class' => 'planned_maintenance', 'start' => $p[0], 'end' => $p[1]]; }
            $rem2 = report_iv_subtract($rem, $maint);
            $R = report_iv_sum($rem2);
            if ($R <= 0) { continue; }
            if ($R < $tOut) {
                $r['missed_checkin'] += $R;
                foreach ($rem2 as $p) { $r['events'][] = ['class' => 'missed_checkin', 'start' => $p[0], 'end' => $p[1]]; }
            } elseif ($alive || report_iv_sum(report_iv_intersect($rem2, $inc)) > 0) {
                $r['confirmed_outage'] += $R;
                foreach ($rem2 as $p) { $r['events'][] = ['class' => 'confirmed_outage', 'start' => $p[0], 'end' => $p[1]]; }
            } else {
                $r['unknown'] += $R;
                foreach ($rem2 as $p) { $r['events'][] = ['class' => 'unknown', 'start' => $p[0], 'end' => $p[1]]; }
            }
        }
        usort($r['events'], fn($a, $b) => $a['start'] <=> $b['start']);
        return $r + report_availability_derive($r);
    }
}

if (!function_exists('report_availability_derive')) {
    /** Ratios from a classified breakdown; null means "cannot be computed", never 0. */
    function report_availability_derive(array $r): array {
        $m = $r['up'] + $r['missed_checkin'] + $r['confirmed_outage'];
        $inScope = $r['total'] - $r['not_enrolled'] - $r['future'];
        return [
            'measurable'   => $m,
            'availability' => $m > 0 ? ($m - $r['confirmed_outage']) / (float)$m : null,
            'continuity'   => $m > 0 ? $r['up'] / (float)$m : null,
            'coverage'     => $inScope > 0 ? 1 - ($r['monitoring_gap'] + $r['unknown']) / $inScope : null,
        ];
    }
}

if (!function_exists('report_availability_aggregate')) {
    /** Device-time-weighted aggregate across firewalls. @param array[] $rows output of report_availability() */
    function report_availability_aggregate(array $rows): array {
        $sum = ['up' => 0, 'missed_checkin' => 0, 'confirmed_outage' => 0, 'monitoring_gap' => 0,
                'planned_maintenance' => 0, 'unknown' => 0, 'not_enrolled' => 0, 'future' => 0, 'total' => 0];
        $contrib = 0;
        foreach ($rows as $r) {
            foreach ($sum as $k => $_) { $sum[$k] += $r[$k]; }
            if ($r['measurable'] > 0) { $contrib++; }
        }
        return $sum + report_availability_derive($sum) + ['devices' => count($rows), 'devices_with_data' => $contrib];
    }
}

if (!function_exists('report_state_durations')) {
    /**
     * Time spent down/degraded from a transition log (gateways, VPN tunnels).
     *
     * @param array $events   [[ts, from_state, to_state], ...] sorted; states 'up'|'down'|'degraded'|null
     * @param ?string $before state implied just before the window by the latest earlier event, if any
     * @return array{observed:bool,down:int,degraded:int,unknown:int,episodes:int,ongoing:bool}
     *   observed=false when there are no transitions and no earlier state: the period's state is then
     *   unknown, which is reported as such rather than as 100% up.
     */
    function report_state_durations(array $events, ?string $before, int $ws, int $we): array {
        $state = $before;
        if ($state === null && $events && $events[0][1] !== null) { $state = $events[0][1]; }
        if ($state === null && !$events) {
            return ['observed' => false, 'down' => 0, 'degraded' => 0, 'unknown' => $we - $ws, 'episodes' => 0, 'ongoing' => false];
        }
        $t = $ws; $down = $deg = $unk = $ep = 0;
        $acc = function (?string $st, int $from, int $to) use (&$down, &$deg, &$unk) {
            $d = max(0, $to - $from);
            if ($st === 'down') { $down += $d; } elseif ($st === 'degraded') { $deg += $d; } elseif ($st === null) { $unk += $d; }
        };
        foreach ($events as [$ts, $from, $to]) {
            if ($ts < $ws) { continue; }
            if ($ts >= $we) { break; }
            $acc($state, $t, $ts);
            if ($to === 'down' && $state !== 'down') { $ep++; }
            $state = $to; $t = $ts;
        }
        $acc($state, $t, $we);
        if ($before === 'down' && $events === []) { $ep = 1; }
        return ['observed' => true, 'down' => $down, 'degraded' => $deg, 'unknown' => $unk, 'episodes' => $ep, 'ongoing' => $state === 'down'];
    }
}

if (!function_exists('report_stats')) {
    /** Count/avg/p50/p95/max of numeric samples; null fields when there are none. */
    function report_stats(array $v): array {
        $n = count($v);
        if ($n === 0) { return ['count' => 0, 'avg' => null, 'p50' => null, 'p95' => null, 'max' => null]; }
        sort($v);
        $pct = function (float $p) use ($v, $n) { return $v[max(0, (int)ceil($p * $n) - 1)]; };
        return ['count' => $n, 'avg' => array_sum($v) / $n, 'p50' => $pct(0.50), 'p95' => $pct(0.95), 'max' => $v[$n - 1]];
    }
}

if (!function_exists('report_fmt_duration')) {
    function report_fmt_duration(?int $s): string {
        if ($s === null) { return 'n/a'; }
        if ($s < 60) { return $s . 's'; }
        $d = intdiv($s, 86400); $h = intdiv($s % 86400, 3600); $m = intdiv($s % 3600, 60);
        return trim(($d ? $d . 'd ' : '') . ($h ? $h . 'h ' : '') . ($m ? $m . 'm' : ''));
    }
}
if (!function_exists('report_fmt_pct')) {
    function report_fmt_pct(?float $r, int $dp = 3): string {
        return $r === null ? 'n/a' : number_format($r * 100, $dp, '.', '') . '%';
    }
}

if (!function_exists('report_formula_text')) {
    /** Plain-language definitions printed in every report appendix and in the docs. */
    function report_formula_text(): array {
        return [
            'Heartbeat' => 'A timestamp at which the manager stored telemetry (system stats, latency sample or check-in row) for the firewall.',
            'T_miss' => '2.5 x the firewall check-in interval, at least 120 s. Heartbeats closer than this prove the firewall was up between them.',
            'T_out' => 'Silence needed to call an outage: the larger of 900 s, the firewall offline alert threshold and 2 x T_miss.',
            'Monitoring gap' => 'Time when no firewall at all produced a heartbeat for longer than T_mgr (2.5 x the slowest check-in interval, 300-3600 s) and at least two firewalls were reporting. Attributed to the manager, not the customer; excluded from availability.',
            'Planned maintenance' => 'Silent time covered by a non-cancelled maintenance window for the firewall, its site or its customer. Excluded from availability.',
            'Missed check-in' => 'Silence shorter than T_out after removing monitoring gaps and maintenance. Not an outage; reduces check-in continuity only.',
            'Confirmed outage' => 'Silence of at least T_out while the manager was demonstrably alive (another firewall reported meanwhile, or a firewall.offline incident covers it).',
            'Unknown' => 'Silence that cannot be attributed (single reporting firewall with no incident), plus time before the manager held any data.',
            'Not enrolled / future' => 'Time before the firewall was enrolled, or after the report was generated. Excluded from everything.',
            'Measurable time' => 'up + missed check-in + confirmed outage.',
            'Observed availability' => '(measurable - confirmed outage) / measurable. Shown as n/a when measurable is zero. This is an observation from check-ins, not a contractual SLA figure.',
            'Check-in continuity' => 'up / measurable.',
            'Monitoring coverage' => '1 - (monitoring gap + unknown) / (period - not enrolled - future).',
            'Customer / fleet availability' => 'Device-time-weighted: sum of (measurable - outage) over all firewalls divided by sum of measurable. Per-firewall percentages are never averaged.',
            'Gateway / VPN downtime' => 'Derived from recorded status transitions only. With no transition and no earlier state the period is "not observed", not "up".',
            'Latency' => 'Agent-reported average WAN latency samples (ms); p95 is nearest-rank. Packet loss is only known from gateway transition events, there is no continuous loss series.',
        ];
    }
}
