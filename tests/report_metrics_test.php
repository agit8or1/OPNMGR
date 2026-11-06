<?php
/**
 * Customer report metric definitions: periods across DST, availability
 * classification, monitoring gaps vs customer outages, aggregation.
 *
 * Pure functions only; still run against a test DB per project convention:
 *   DB_NAME=opnsense_fw_test_reports php tests/report_metrics_test.php
 */
require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/reports/period.php';
require_once TEST_ROOT . '/inc/reports/metrics.php';

// ===========================================================================
T::group('Periods: timezone and DST');
$ny = 'America/New_York';
$p = report_period_resolve(['preset' => 'custom', 'from' => '2026-03-08', 'to' => '2026-03-08'], $ny);
T::eq(23.0, $p['hours'], 'spring-forward day (2026-03-08, New York) is 23 hours');
T::eq(82800, $p['end'] - $p['start'], '... and 82800 seconds');
T::eq(gmdate('Y-m-d H:i', $p['start']), '2026-03-08 05:00', 'starts at local midnight = 05:00 UTC (EST)');
T::eq(gmdate('Y-m-d H:i', $p['end']), '2026-03-09 04:00', 'ends at next local midnight = 04:00 UTC (EDT)');
$p = report_period_resolve(['preset' => 'custom', 'from' => '2026-11-01', 'to' => '2026-11-01'], $ny);
T::eq(25.0, $p['hours'], 'fall-back day (2026-11-01, New York) is 25 hours');
T::eq(1, count($p['days']), 'one calendar day');
$p = report_period_resolve(['preset' => 'custom', 'from' => '2026-11-01', 'to' => '2026-11-01'], 'UTC');
T::eq(24.0, $p['hours'], 'the same date in UTC is 24 hours');

$now = new DateTimeImmutable('2026-03-10 12:00:00', new DateTimeZone($ny));
$p = report_period_resolve(['preset' => 'last_week'], $ny, $now);
T::eq('2026-03-02 00:00', substr($p['label'], 0, 16), 'last_week starts Monday 2026-03-02 local');
T::eq('2026-03-09 00:00', substr($p['label'], 20, 16), 'last_week ends Monday 2026-03-09 local');
T::eq(167.0, $p['hours'], 'week containing spring-forward is 167 hours, not 168');
T::eq(7, count($p['days']), 'seven local days');
T::eq(23.0, array_values(array_filter($p['days'], fn($d) => $d['date'] === '2026-03-08'))[0]['hours'], 'per-day table shows 23h for the DST day');
T::eq($p['end'] - $p['start'], (int)array_sum(array_map(fn($d) => $d['hours'] * 3600, $p['days'])), 'day lengths sum to the period');

$p = report_period_resolve(['preset' => 'last_month'], $ny, new DateTimeImmutable('2026-04-15 09:00:00', new DateTimeZone($ny)));
T::eq(743.0, $p['hours'], 'March 2026 in New York = 31*24-1 = 743 hours');
$p = report_period_resolve(['preset' => 'last_month'], $ny, new DateTimeImmutable('2026-12-02 09:00:00', new DateTimeZone($ny)));
T::eq(721.0, $p['hours'], 'November 2026 in New York = 30*24+1 = 721 hours');
$p = report_period_resolve(['preset' => 'last_month'], 'Australia/Sydney', new DateTimeImmutable('2026-11-10 09:00:00', new DateTimeZone('Australia/Sydney')));
T::eq(743.0, $p['hours'], 'October 2026 in Sydney (DST starts 2026-10-04) = 743 hours');

try { report_period_resolve(['preset' => 'custom', 'from' => '2026-02-30', 'to' => '2026-03-01'], $ny); $bad = false; } catch (InvalidArgumentException $e) { $bad = true; }
T::ok($bad, 'an impossible calendar date is rejected');
try { report_period_resolve(['preset' => 'custom', 'from' => '2026-03-05', 'to' => '2026-03-01'], $ny); $bad = false; } catch (InvalidArgumentException $e) { $bad = true; }
T::ok($bad, 'an inverted period is rejected');

// next run: 06:00 local stays 06:00 local across DST
$after = (new DateTimeImmutable('2026-03-06 12:00:00', new DateTimeZone($ny)))->getTimestamp();  // Friday
$next = report_next_run('weekly', 1, 6, $ny, $after);
T::eq('2026-03-09 06:00', (new DateTimeImmutable('@' . $next))->setTimezone(new DateTimeZone($ny))->format('Y-m-d H:i'), 'weekly Monday 06:00 after the DST change is still 06:00 local');
$next = report_next_run('monthly', 1, 6, $ny, (new DateTimeImmutable('2026-10-15 00:00', new DateTimeZone($ny)))->getTimestamp());
T::eq('2026-11-01 06:00', (new DateTimeImmutable('@' . $next))->setTimezone(new DateTimeZone($ny))->format('Y-m-d H:i'), 'monthly run on the fall-back day is 06:00 local');
$next = report_next_run('monthly', 5, 6, 'UTC', (new DateTimeImmutable('2026-12-20 00:00 UTC'))->getTimestamp());
T::eq('2027-01-05 06:00', gmdate('Y-m-d H:i', $next), 'monthly run rolls over the year');

// ===========================================================================
T::group('Availability classification');
$B = 1760000000;           // arbitrary instant
$DAY = 86400;
$th = report_thresholds(180, 300);
T::eq([450, 900], [$th['t_miss'], $th['t_out']], 'thresholds: T_miss 450 s, T_out 900 s for a 180 s interval');

/** Heartbeats every $step seconds over [$from,$to), skipping holes [[s,e],...). */
function beats(int $from, int $to, array $holes = [], int $step = 180): array {
    $o = [];
    for ($t = $from; $t < $to; $t += $step) {
        foreach ($holes as [$s, $e]) { if ($t >= $s && $t < $e) { continue 2; } }
        $o[] = $t;
    }
    return $o;
}
/** Run report_availability for one firewall with sane defaults. */
function avail(array $o): array {
    global $B, $DAY, $th;
    $hb = $o['hb'];
    return report_availability($o + [
        'start' => $B, 'end' => $B + $DAY, 'now' => $B + $DAY, 'horizon' => $B - 86400, 'hb' => $hb,
        'fleet' => $o['fleet'] ?? $hb, 't_miss' => $th['t_miss'], 't_out' => $th['t_out'],
        'mgr_gaps' => [], 'mgr_trusted' => true, 'maintenance' => [], 'incidents' => [],
    ]);
}
function sums_ok(array $r): bool {
    return $r['up'] + $r['missed_checkin'] + $r['confirmed_outage'] + $r['monitoring_gap'] + $r['planned_maintenance'] + $r['unknown'] + $r['not_enrolled'] + $r['future'] === $r['total'];
}

$r = avail(['hb' => beats($B - 600, $B + $DAY)]);
T::eq($DAY, $r['up'], 'continuous check-ins: whole day is up');
T::eq(1.0, $r['availability'], 'availability 100%');
T::ok(sums_ok($r), 'classes sum to the period');

// missed check-in vs outage
$hole = [$B + 3600, $B + 3600 + 600];
$r = avail(['hb' => beats($B - 600, $B + $DAY, [$hole])]);
T::ok($r['missed_checkin'] > 0 && $r['confirmed_outage'] === 0, 'a 10-minute silence is a missed check-in, not an outage');
T::eq(1.0, $r['availability'], 'a missed check-in does not reduce observed availability');
T::ok($r['continuity'] < 1.0, '... but it reduces check-in continuity');
T::ok(sums_ok($r), 'sums hold');

$hole = [$B + 3600, $B + 3600 + 3600];
$hbA = beats($B - 600, $B + $DAY, [$hole]);
$hbB = beats($B - 600, $B + $DAY);           // another firewall that kept reporting
$r = avail(['hb' => $hbA, 'fleet' => $hbB]);
T::ok($r['confirmed_outage'] >= 3600 && $r['confirmed_outage'] <= 3600 + 450, 'one-hour silence while another firewall reported is a confirmed outage');
T::ok($r['availability'] < 1.0 && $r['availability'] > 0.9, 'availability reflects the outage');
T::ok(sums_ok($r), 'sums hold');

// single firewall: cannot tell from manager outage
$r = avail(['hb' => $hbA, 'fleet' => $hbA, 'mgr_trusted' => false, 'mgr_gaps' => report_manager_gaps($hbA, $B, $B + $DAY, 450, 1)['gaps']]);
T::eq(0, $r['confirmed_outage'], 'sole reporting firewall, no incident: NOT claimed as an outage');
T::ok($r['unknown'] >= 3600, '... classified as unknown status');
$inc = [[$B + 3700, $B + 3600 + 3600 + 100]];
$r = avail(['hb' => $hbA, 'fleet' => $hbA, 'mgr_trusted' => false, 'mgr_gaps' => report_manager_gaps($hbA, $B, $B + $DAY, 450, 1)['gaps'], 'incidents' => $inc]);
T::ok($r['confirmed_outage'] >= 3000, 'a firewall.offline incident (manager was alive) confirms the outage');

// manager-wide gap
$mgHole = [$B + 7200, $B + 7200 + 1800];
$hbA = beats($B - 600, $B + $DAY, [$mgHole]); $hbB = beats($B - 600, $B + $DAY, [$mgHole], 120);
$fleet = array_values(array_unique(array_merge($hbA, $hbB))); sort($fleet);
$mg = report_manager_gaps($fleet, $B, $B + $DAY, 450, 2);
T::ok($mg['trusted'] && count($mg['gaps']) === 1, 'both firewalls silent together = one manager gap');
$r = avail(['hb' => $hbA, 'fleet' => $fleet, 'mgr_gaps' => $mg['gaps'], 'mgr_trusted' => true]);
T::eq(0, $r['confirmed_outage'], 'manager-wide gap is NOT customer downtime');
T::ok($r['monitoring_gap'] >= 1800, '... it is a monitoring gap');
T::eq(1.0, $r['availability'], 'availability unaffected by the manager gap');
T::ok(sums_ok($r), 'sums hold');

// the same firewall also has a genuine outage later while B reports
$own = [$B + 40000, $B + 40000 + 5400];
$hbA2 = beats($B - 600, $B + $DAY, [$mgHole, $own]);
$hbB2 = beats($B - 600, $B + $DAY, [$mgHole], 120);
$fleet2 = array_values(array_unique(array_merge($hbA2, $hbB2))); sort($fleet2);
$mg2 = report_manager_gaps($fleet2, $B, $B + $DAY, 450, 2);
$r = avail(['hb' => $hbA2, 'fleet' => $fleet2, 'mgr_gaps' => $mg2['gaps']]);
T::ok($r['confirmed_outage'] >= 5400 && $r['monitoring_gap'] >= 1800, 'a manager gap and a real outage in one day are counted separately');

// planned maintenance
$hole = [$B + 20000, $B + 20000 + 3600];
$hb = beats($B - 600, $B + $DAY, [$hole]);
$r = avail(['hb' => $hb, 'fleet' => beats($B - 600, $B + $DAY), 'maintenance' => [[$B + 19000, $B + 30000]]]);
T::eq(0, $r['confirmed_outage'], 'silence inside a maintenance window is not an outage');
T::ok($r['planned_maintenance'] >= 3600, '... it is planned maintenance');
T::eq(1.0, $r['availability'], 'availability excludes planned maintenance');
// precedence: a monitoring gap inside maintenance is a monitoring gap
$r = avail(['hb' => $hbA, 'fleet' => $fleet, 'mgr_gaps' => $mg['gaps'], 'maintenance' => [[$B, $B + $DAY]]]);
T::ok($r['monitoring_gap'] >= 1800 && $r['planned_maintenance'] < 1000, 'manager gap takes precedence over maintenance');

// enrolment / future / horizon / no data
$r = avail(['hb' => beats($B + 40000, $B + $DAY), 'enrolled_at' => $B + 40000, 'fleet' => beats($B - 600, $B + $DAY)]);
T::eq(40000, $r['not_enrolled'], 'time before enrolment is "not enrolled", not downtime');
T::eq(0, $r['confirmed_outage'], '... and not an outage');
T::ok(sums_ok($r), 'sums hold');
$r = avail(['hb' => beats($B - 600, $B + 50000), 'now' => $B + 50000]);
T::eq($DAY - 50000, $r['future'], 'time after generation is "future"');
T::eq(0, $r['confirmed_outage'], 'the not-yet-elapsed part is not an outage');
T::ok(sums_ok($r), 'sums hold');
$r = avail(['hb' => beats($B + 30000, $B + $DAY), 'horizon' => $B + 30000, 'fleet' => beats($B + 30000, $B + $DAY)]);
T::eq(30000, $r['unknown'], 'time before the manager held any data is unknown');
T::ok($r['availability'] === 1.0, 'availability uses only the observed part');
$r = avail(['hb' => [], 'horizon' => null, 'fleet' => []]);
T::eq(null, $r['availability'], 'no data at all: availability is unavailable (null)');
T::ok($r['availability'] !== 0.0 && $r['availability'] !== 0, '... never zero');
T::eq($DAY, $r['unknown'], 'whole period unknown');
T::eq(null, $r['continuity'], 'continuity unavailable too');

// property: classes always partition the period
mt_srand(7); $allOk = true;
for ($i = 0; $i < 150; $i++) {
    $holes = []; for ($k = mt_rand(0, 4); $k > 0; $k--) { $s = $B + mt_rand(0, $DAY); $holes[] = [$s, $s + mt_rand(60, 9000)]; }
    $other = []; for ($k = mt_rand(0, 2); $k > 0; $k--) { $s = $B + mt_rand(0, $DAY); $other[] = [$s, $s + mt_rand(60, 9000)]; }
    $mine = beats($B - 700, $B + $DAY, $holes); $oth = mt_rand(0, 1) ? beats($B - 700, $B + $DAY, $other, 150) : [];
    $fl = array_values(array_unique(array_merge($mine, $oth))); sort($fl);
    $mgx = report_manager_gaps($fl, $B, $B + $DAY, 450, $oth ? 2 : 1);
    $m = [];
    if (mt_rand(0, 1)) { $s = $B + mt_rand(0, $DAY); $m[] = [$s, $s + mt_rand(100, 20000)]; }
    $rr = avail(['hb' => $mine, 'fleet' => $fl, 'mgr_gaps' => $mgx['gaps'], 'mgr_trusted' => $mgx['trusted'], 'maintenance' => $m,
                 'now' => $B + mt_rand(1000, $DAY * 2), 'enrolled_at' => mt_rand(0, 1) ? $B + mt_rand(0, $DAY) : null]);
    if (!sums_ok($rr)) { $allOk = false; break; }
}
T::ok($allOk, 'property: 150 random timelines always partition the period exactly');

// DST day: 23 hours of check-ins, fully up
$p = report_period_resolve(['preset' => 'custom', 'from' => '2026-03-08', 'to' => '2026-03-08'], $ny);
$hb = beats($p['start'] - 600, $p['end']);
$r = report_availability(['start' => $p['start'], 'end' => $p['end'], 'now' => $p['end'], 'horizon' => $p['start'] - 86400, 'hb' => $hb, 'fleet' => $hb,
    't_miss' => 450, 't_out' => 900, 'mgr_gaps' => [], 'mgr_trusted' => true]);
T::eq(82800, $r['total'], 'DST day total is 82800 s');
T::eq(82800, $r['up'], 'all 23 hours are up, none invented');

// ===========================================================================
T::group('Aggregation');
$a1 = ['up' => 86400, 'missed_checkin' => 0, 'confirmed_outage' => 0, 'monitoring_gap' => 0, 'planned_maintenance' => 0, 'unknown' => 0, 'not_enrolled' => 0, 'future' => 0, 'total' => 86400];
$a1 += report_availability_derive($a1);
$a2 = ['up' => 1800, 'missed_checkin' => 0, 'confirmed_outage' => 1800, 'monitoring_gap' => 0, 'planned_maintenance' => 0, 'unknown' => 0, 'not_enrolled' => 82800, 'future' => 0, 'total' => 86400];
$a2 += report_availability_derive($a2);
$g = report_availability_aggregate([$a1, $a2]);
T::ok(abs($g['availability'] - (86400 + 1800) / (86400 + 3600)) < 1e-12, 'customer availability is device-time-weighted');
T::ok(abs($g['availability'] - 0.75) > 0.1, '... and differs from the naive average of per-device percentages (0.75)');
$empty = report_availability_aggregate([]);
T::eq(null, $empty['availability'], 'no devices: availability n/a, not 0');

// ===========================================================================
T::group('Gateway / VPN transition durations');
$d = report_state_durations([], null, $B, $B + 1000);
T::eq(false, $d['observed'], 'no transitions and no earlier state: not observed (not "100% up")');
$d = report_state_durations([], 'down', $B, $B + 1000);
T::ok($d['observed'] && $d['down'] === 1000 && $d['ongoing'], 'earlier down state and no transition: down for the whole period, ongoing');
$d = report_state_durations([[$B + 100, 'up', 'down'], [$B + 400, 'down', 'up'], [$B + 600, 'up', 'down'], [$B + 700, 'down', 'up']], 'up', $B, $B + 1000);
T::eq(400, $d['down'], 'two down episodes summed (300 + 100)');
T::eq(2, $d['episodes'], 'two episodes counted');
T::eq(false, $d['ongoing'], 'recovered');
$d = report_state_durations([[$B + 300, null, 'up']], null, $B, $B + 1000);
T::eq(300, $d['unknown'], 'time before the first known state is unknown');

T::group('Statistics and formatting');
$st = report_stats([]);
T::ok($st['count'] === 0 && $st['avg'] === null && $st['p95'] === null, 'no samples: null stats, not zero');
$st = report_stats(range(1, 100));
T::eq(95, $st['p95'], 'p95 nearest-rank');
T::eq('n/a', report_fmt_pct(null), 'null percentage renders n/a');
T::eq('99.500%', report_fmt_pct(0.995), 'percentage formatting');

exit(T::summary());
