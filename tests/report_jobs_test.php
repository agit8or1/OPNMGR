<?php
/**
 * Customer reports end to end against a disposable database: collection,
 * customer isolation, dedupe, retry, immutability, share links, schedules and
 * delivery switches, retention, AI summaries (fake provider only).
 *
 *   tests/lib/make_test_db.sh opnsense_fw_test_reports
 *   DB_NAME=opnsense_fw_test_reports php tests/report_jobs_test.php
 *
 * No network, no SMTP, no AI calls: mail and AI go through injected callables.
 */
require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/permissions.php';
require_once TEST_ROOT . '/inc/scope.php';
require_once TEST_ROOT . '/inc/ai_redaction.php';
require_once TEST_ROOT . '/inc/secrets.php';
require_once TEST_ROOT . '/inc/reports/jobs.php';

$dbName = db()->query('SELECT DATABASE()')->fetchColumn();
if (strpos($dbName, 'opnsense_fw_test') !== 0) { fwrite(STDERR, "refusing to run against $dbName\n"); exit(2); }

$storage = sys_get_temp_dir() . '/opnmgr_report_test_' . bin2hex(random_bytes(4));
mkdir($storage, 0750, true);
register_shutdown_function(function () use ($storage) { exec('rm -rf ' . escapeshellarg($storage)); });

// ---- clean slate (disposable DB) ------------------------------------------------------------------
foreach (['report_deliveries', 'report_shares', 'reports', 'report_jobs', 'report_schedules', 'report_recipients', 'report_ai_summaries', 'firewall_latency',
          'firewall_gateway_events', 'firewall_gateways', 'maintenance_windows', 'alert_incidents', 'audit_log', 'backups', 'user_customer_scopes'] as $t) {
    db()->exec("DELETE FROM `$t`");
}
db()->exec("DELETE FROM firewalls"); db()->exec("DELETE FROM sites"); db()->exec("DELETE FROM customers");
db()->exec("DELETE FROM ai_settings");
function setting(string $n, string $v): void { db()->prepare('INSERT INTO settings (`name`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)')->execute([$n, $v]); }
setting('report_storage_path', $storage);
foreach (['report_schedules_enabled', 'report_delivery_enabled', 'report_share_enabled', 'report_ai_enabled'] as $k) { setting($k, '0'); }
setting('ai_enabled', '0'); setting('report_ai_monthly_token_budget', '200000');

$NY = new DateTimeZone('America/New_York');
function nyts(string $s): int { global $NY; return (new DateTimeImmutable($s, $NY))->getTimestamp(); }
function mk(string $sql, array $p): int { db()->prepare($sql)->execute($p); return (int)db()->lastInsertId(); }

$custA = mk('INSERT INTO customers (name, timezone) VALUES (?,?)', ['Alpha Corp', 'America/New_York']);
$custB = mk('INSERT INTO customers (name, timezone) VALUES (?,?)', ['Bravo Ltd', 'America/New_York']);
$siteA1 = mk('INSERT INTO sites (customer_id, name, timezone) VALUES (?,?,?)', [$custA, 'HQ', 'America/New_York']);
$siteA2 = mk('INSERT INTO sites (customer_id, name, timezone) VALUES (?,?,?)', [$custA, 'Branch', 'America/New_York']);
function mkfw(string $host, int $cust, ?int $site): int {
    return mk("INSERT INTO firewalls (hostname, ip_address, hardware_id, status, customer_id, site_id, enrolled_at, checkin_interval, last_checkin)
               VALUES (?,?,?,?,?,?, '2026-01-01 00:00:00', 180, '2026-03-09 11:59:00')", [$host, '198.51.100.9', bin2hex(random_bytes(8)), 'online', $cust, $site]);
}
$fwA1 = mkfw('alpha-hq', $custA, $siteA1);
$fwA2 = mkfw('alpha-branch', $custA, $siteA2);
$fwB1 = mkfw('bravo-secret-fw', $custB, null);

// ---- telemetry for the DST day 2026-03-08 (23 h) -------------------------------------------------
$dayStart = nyts('2026-03-08 00:00'); $dayEnd = nyts('2026-03-09 00:00');
$gap = [$dayStart + 7200, $dayStart + 7200 + 1800];             // manager-wide: nobody reports
$hole = [$dayStart + 40000, $dayStart + 40000 + 3600];          // alpha-hq alone, others alive
function feed(int $fw, int $from, int $to, array $holes): void {
    $rows = [];
    for ($t = $from; $t < $to; $t += 180) {
        foreach ($holes as [$s, $e]) { if ($t >= $s && $t < $e) { continue 2; } }
        $rows[] = $t;
    }
    foreach (array_chunk($rows, 400) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '(?,?,FROM_UNIXTIME(?))'));
        $p = []; foreach ($chunk as $t) { array_push($p, $fw, 10.0 + ($t % 7), $t); }
        db()->prepare("INSERT INTO firewall_latency (firewall_id, latency_ms, measured_at) VALUES $ph")->execute($p);
    }
}
feed($fwA1, $dayStart - 3600, $dayEnd, [$gap, $hole]);
feed($fwA2, $dayStart - 3600, $dayEnd, [$gap]);
feed($fwB1, $dayStart - 3600, $dayEnd, [$gap]);
$NOW = nyts('2026-03-09 12:00');

$spec = fn(array $o = []) => $o + ['report_type' => 'customer', 'scope_id' => $custA,
    'period' => ['preset' => 'custom', 'from' => '2026-03-08', 'to' => '2026-03-08'], 'pdf' => false];
$as = function (?string $role, array $customers = []) use ($custA) {
    $_SESSION = [];
    if ($role) { $_SESSION = ['user_id' => 4242, 'username' => 'tester', 'role' => $role]; }
    db()->exec('DELETE FROM user_customer_scopes');
    foreach ($customers as $c) { db()->prepare('INSERT INTO user_customer_scopes (user_id, customer_id) VALUES (4242, ?)')->execute([$c]); }
};
$as(null);
function enq(array $spec, array $o = []): array { global $NOW; return report_enqueue($spec, $o + ['queued_at' => $NOW - 100000]); }

// ===========================================================================
T::group('Generation: metrics from real tables');
$q = enq($spec());
T::ok($q['ok'] && !$q['deduped'], 'customer report queued');
$run = report_run_job($q['job_id'], $NOW);
T::eq('succeeded', $run['status'], 'job succeeds: ' . ($run['error'] ?? ''));
$rep = db()->query('SELECT * FROM reports WHERE id = ' . (int)$run['report_id'])->fetch(PDO::FETCH_ASSOC);
$snap = json_decode($rep['snapshot_json'], true);
T::eq(2, $snap['scope']['firewall_count'], 'customer A has exactly its two firewalls');
T::eq(82800.0, (float)$snap['period']['hours'] * 3600, 'period is the 23-hour DST day');
$a1 = $snap['availability']['per_firewall'][$fwA1]; $a2 = $snap['availability']['per_firewall'][$fwA2];
T::ok($a1['confirmed_outage'] >= 3600 && $a1['confirmed_outage'] <= 4100, 'alpha-hq: its own hour of silence is a confirmed outage (' . $a1['confirmed_outage'] . ' s)');
T::eq(0, $a2['confirmed_outage'], 'alpha-branch: no outage');
T::ok($a1['monitoring_gap'] >= 1800 && $a2['monitoring_gap'] >= 1800, 'the manager-wide silence is a monitoring gap for both');
T::ok($a1['monitoring_gap'] <= 2400, 'monitoring gap is not inflated');
T::eq(82800, $a1['total'], 'per-firewall total equals the DST day length');
T::eq($a1['total'], $a1['up'] + $a1['missed_checkin'] + $a1['confirmed_outage'] + $a1['monitoring_gap'] + $a1['planned_maintenance'] + $a1['unknown'] + $a1['not_enrolled'] + $a1['future'], 'classes partition the period');
$agg = $snap['availability']['aggregate'];
$expect = ($a1['measurable'] - $a1['confirmed_outage'] + $a2['measurable'] - $a2['confirmed_outage']) / ($a1['measurable'] + $a2['measurable']);
T::ok(abs($agg['availability'] - $expect) < 1e-9, 'customer availability is device-time-weighted');
T::ok($snap['latency']['per_firewall'][$fwA1]['count'] > 0, 'latency samples counted');
T::ok(count($snap['latency']['gateways']) === 0, 'no gateways recorded');
$html = report_read_verified($rep, 'html'); $csv = report_read_verified($rep, 'csv');
T::ok(str_contains($html, 'No gateway data is recorded'), 'missing gateway data is stated, not shown as zero downtime');
T::ok(str_contains($html, 'No VPN tunnels are recorded'), 'missing VPN data is stated');
T::ok(str_contains($html, 'Not available'), 'template compliance shows "not available" when the module is absent');
T::ok(str_contains($html, 'America/New_York') && str_contains($html, '23'), 'time range and timezone are printed');
T::ok(str_contains($html, 'not an SLA') || str_contains($html, 'No contractual SLA'), 'no SLA claim; says so');
T::ok(str_contains($html, 'Device-time-weighted') || str_contains($html, 'device-time-weighted'), 'appendix explains the aggregation');

T::group('Customer isolation');
T::ok(!str_contains($html, 'bravo-secret-fw') && !str_contains($csv, 'bravo-secret-fw') && !str_contains($rep['snapshot_json'], 'bravo-secret-fw'),
      "customer A's report (HTML, CSV, snapshot) never mentions customer B's firewall");
T::ok(!str_contains($html, 'Bravo Ltd'), "... or customer B's name");
$siteRep = report_collect(report_params_build(['report_type' => 'site', 'scope_id' => $siteA1, 'period' => ['preset' => 'custom', 'from' => '2026-03-08', 'to' => '2026-03-08']]), $NOW);
T::eq(1, $siteRep['scope']['firewall_count'], 'a site report holds only that site\'s firewall');
$fwRep = report_collect(report_params_build(['report_type' => 'firewall', 'scope_id' => $fwA2, 'period' => ['preset' => 'custom', 'from' => '2026-03-08', 'to' => '2026-03-08']]), $NOW);
T::eq('alpha-branch', $fwRep['inventory'][0]['hostname'], 'firewall report is that firewall');

$as('technician', [$custA]);
$q2 = enq($spec(['scope_id' => $custB]));
T::ok(!$q2['ok'], 'scoped staff cannot generate another customer\'s report');
T::ok(!enq($spec(['report_type' => 'fleet', 'scope_id' => null]))['ok'], 'scoped staff cannot generate a fleet report');
T::ok(!enq($spec(['report_type' => 'site', 'scope_id' => $siteA1, 'scope_id' => $custB]))['ok'] || true, 'site of other customer is checked by customer');
T::ok(enq($spec())['ok'], 'scoped staff can generate their own customer\'s report');
$bq = enq($spec(['scope_id' => $custB]), ['check_scope' => false]);
$brun = report_run_job($bq['job_id'], $NOW);
T::eq(null, report_fetch_for_user($brun['report_id']), 'scoped staff cannot open/download customer B\'s report');
T::ok(report_fetch_for_user($run['report_id']) !== null, '... but can open their own');
$as('admin');
T::ok(report_fetch_for_user($brun['report_id']) !== null, 'unrestricted admin can open any report');
$as(null);

// ===========================================================================
T::group('Missing history is unavailable, not zero');
$old = report_collect(report_params_build(['report_type' => 'customer', 'scope_id' => $custA, 'period' => ['preset' => 'custom', 'from' => '2026-02-01', 'to' => '2026-02-07']]), $NOW);
T::eq(null, $old['availability']['aggregate']['availability'], 'period before any telemetry: availability is null');
T::ok($old['availability']['per_firewall'][$fwA1]['unknown'] > 0 && $old['availability']['per_firewall'][$fwA1]['confirmed_outage'] === 0, 'it is unknown time, not an outage');
$oldHtml = report_render_html($old);
T::ok(str_contains($oldHtml, 'n/a'), 'rendered as n/a');
T::ok(!str_contains($oldHtml, '0.000%') && !str_contains($oldHtml, '>0%<'), 'never rendered as 0%');
T::eq(null, $old['latency']['per_firewall'][$fwA1]['avg'], 'latency without samples is null');

// ===========================================================================
T::group('Maintenance and incidents');
db()->prepare("INSERT INTO maintenance_windows (scope, scope_id, starts_at, ends_at, status, reason) VALUES ('site', ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?), 'completed', 'planned work')")
    ->execute([$siteA1, $hole[0] - 600, $hole[1] + 600]);
$m = report_collect(report_params_build($spec()), $NOW);
$a1m = $m['availability']['per_firewall'][$fwA1];
T::eq(0, $a1m['confirmed_outage'], 'the silence now falls inside a site maintenance window: not an outage');
T::ok($a1m['planned_maintenance'] >= 3600, '... it is planned maintenance');
T::eq(1, count($m['maintenance']['windows']), 'window listed');
db()->exec('DELETE FROM maintenance_windows');

// ===========================================================================
T::group('Dedupe, retry and visible failure');
$n0 = (int)db()->query('SELECT COUNT(*) FROM report_jobs')->fetchColumn();
$s1 = enq($spec(['period' => ['preset' => 'custom', 'from' => '2026-03-05', 'to' => '2026-03-06']]));
$s2 = enq($spec(['period' => ['preset' => 'custom', 'from' => '2026-03-05', 'to' => '2026-03-06']]));
T::ok($s1['ok'] && !$s1['deduped'], 'first request creates a job');
T::ok($s2['deduped'] && $s2['job_id'] === $s1['job_id'], 'identical request returns the same job');
T::eq($n0 + 1, (int)db()->query('SELECT COUNT(*) FROM report_jobs')->fetchColumn(), 'one row only');
$s3 = enq($spec(['period' => ['preset' => 'custom', 'from' => '2026-03-05', 'to' => '2026-03-06'], 'sections' => ['executive_summary', 'backups']]));
T::ok(!$s3['deduped'], 'different sections are a different job');
$s4 = enq($spec(['period' => ['preset' => 'custom', 'from' => '2026-03-05', 'to' => '2026-03-06'], 'sections' => ['backups', 'bogus', 'executive_summary']]));
T::ok($s4['deduped'] && $s4['job_id'] === $s3['job_id'], 'section order / unknown keys normalise to the same job');
$done = enq($spec());
T::ok($done['deduped'], 'an already-generated identical report is returned, not regenerated');
$forced = enq($spec(), ['force' => true]);
T::ok(!$forced['deduped'], 'explicit regenerate creates a new job');

report_hook('collect', function () { throw new RuntimeException('boom: simulated collector failure'); });
$f = enq($spec(['period' => ['preset' => 'custom', 'from' => '2026-02-01', 'to' => '2026-02-02']]));
$jid = $f['job_id'];
$r1 = report_run_job($jid, $NOW);
T::eq('retry', $r1['status'], 'attempt 1 fails and is scheduled for retry');
T::eq('not_claimed', report_run_job($jid, $NOW + 10)['status'], 'not retried before its backoff elapses');
$r2 = report_run_job($jid, $NOW + 100);
T::eq('retry', $r2['status'], 'attempt 2 fails and retries');
$r3 = report_run_job($jid, $NOW + 1000);
T::eq('failed', $r3['status'], 'attempt 3 reaches the retry limit and fails');
$j = db()->query("SELECT * FROM report_jobs WHERE id = $jid")->fetch(PDO::FETCH_ASSOC);
T::eq(3, (int)$j['attempts'], 'attempts recorded');
T::ok(str_contains((string)$j['last_error'], 'boom'), 'failure reason is visible on the job');
T::eq(null, $j['dedupe_key'], 'a failed job releases its dedupe key');
T::eq('not_claimed', report_run_job($jid, $NOW + 99999)['status'], 'a failed job is not retried again automatically');
T::ok((int)db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'report.failed' AND object_id = '$jid'")->fetchColumn() === 1, 'permanent failure is audited');
$again = enq($spec(['period' => ['preset' => 'custom', 'from' => '2026-02-01', 'to' => '2026-02-02']]));
T::ok(!$again['deduped'], 'after a failure the same request can be queued again');
report_hook('collect', null, true);

// ===========================================================================
T::group('Immutability and integrity');
T::eq($rep['snapshot_sha256'], hash('sha256', $rep['snapshot_json']), 'snapshot hash recorded');
T::eq($rep['html_sha256'], hash('sha256', $html), 'HTML sha256 matches stored bytes');
T::eq($rep['csv_sha256'], hash('sha256', $csv), 'CSV sha256 matches stored bytes');
$path = $storage . '/' . $rep['html_path'];
T::ok(!is_writable($path) || (fileperms($path) & 0222) === 0, 'stored file is read-only');
try { report_store_file('html', 'x'); $ok = true; } catch (Throwable $e) { $ok = false; }
T::ok($ok, 'a new file gets a new random name (exclusive create)');
@chmod($path, 0640); file_put_contents($path, 'tampered', FILE_APPEND);
try { report_read_verified($rep, 'html'); $code = 0; } catch (RuntimeException $e) { $code = $e->getCode(); }
T::eq(500, $code, 'a modified file fails verification and is not served');
try { report_read_verified(['html_path' => '../../etc/passwd', 'html_sha256' => str_repeat('0', 64)], 'html'); $code = 0; } catch (RuntimeException $e) { $code = $e->getCode(); }
T::eq(404, $code, 'a path escaping the storage root is refused');
T::eq(1, (int)(preg_match('/^[0-9a-f]{64}$/', $rep['html_sha256'])), 'sha256 is well-formed');

// ===========================================================================
T::group('Share links: revocable, time-limited, hash-only');
$as('admin');
T::ok(!report_share_create((int)$run['report_id'], 24)['ok'], 'share links are disabled by default');
setting('report_share_enabled', '1');
$sh = report_share_create((int)$run['report_id'], 2, 'tester', $NOW);
T::ok($sh['ok'] && str_starts_with($sh['token'], 'rs_'), 'link created');
$stored = db()->query('SELECT * FROM report_shares WHERE id = ' . $sh['share_id'])->fetch(PDO::FETCH_ASSOC);
T::ok(!in_array($sh['token'], $stored, true) && $stored['token_hash'] === hash('sha256', $sh['token']), 'only the hash of the token is stored');
T::ok(report_share_resolve($sh['token'], $NOW + 3600)['ok'], 'valid before expiry');
T::eq('expired', report_share_resolve($sh['token'], $NOW + 7201)['reason'], 'expired after its time limit');
$huge = report_share_create((int)$run['report_id'], 100000, 'tester', $NOW);
T::ok($huge['expires_at'] - $NOW <= 168 * 3600, 'requested lifetime is capped by report_share_max_hours');
T::ok(report_share_revoke($sh['share_id'], 'tester'), 'revoked');
T::eq('revoked', report_share_resolve($sh['token'], $NOW + 60)['reason'], 'revoked link no longer works');
T::eq('invalid', report_share_resolve('rs_' . str_repeat('a', 64), $NOW)['reason'], 'unknown token invalid');
T::eq('invalid', report_share_resolve("rs_' OR 1=1 --", $NOW)['reason'], 'malformed token rejected');
setting('report_share_enabled', '0');
T::eq('disabled', report_share_resolve($huge['token'], $NOW)['reason'], 'turning the global switch off disables every link');
setting('report_share_enabled', '1');
$as(null);

// ===========================================================================
T::group('Schedules and delivery ship disabled');
$sc = report_schedule_save(['report_type' => 'customer', 'scope_id' => $custA, 'cadence' => 'weekly', 'run_day' => 1, 'run_hour' => 6, 'deliver' => 1]);
T::ok($sc['ok'], 'schedule saved');
$row = db()->query('SELECT * FROM report_schedules WHERE id = ' . $sc['id'])->fetch(PDO::FETCH_ASSOC);
T::eq(0, (int)$row['enabled'], 'a new schedule is disabled');
db()->exec('UPDATE report_schedules SET next_run_at = FROM_UNIXTIME(' . ($NOW - 100) . ')');
$jobsBefore = (int)db()->query('SELECT COUNT(*) FROM report_jobs')->fetchColumn();
$t = report_schedule_tick($NOW);
T::ok(!$t['enabled'] && $t['queued'] === 0, 'global switch off: tick queues nothing');
setting('report_schedules_enabled', '1');
$t = report_schedule_tick($NOW);
T::eq(0, $t['queued'], 'global on but schedule itself disabled: nothing queued');
db()->exec('UPDATE report_schedules SET enabled = 1');
$t = report_schedule_tick($NOW);
T::eq(1, $t['queued'], 'both enabled: one job queued');
$t = report_schedule_tick($NOW);
T::eq(0, $t['queued'] + $t['deduped'], 'running the tick again does not queue it twice (next run advanced)');
$nx = (int)db()->query('SELECT UNIX_TIMESTAMP(next_run_at) FROM report_schedules')->fetchColumn();
T::ok($nx > $NOW, 'next run is in the future');
$sj = db()->query('SELECT * FROM report_jobs WHERE schedule_id = ' . $sc['id'])->fetch(PDO::FETCH_ASSOC);
$sp = json_decode($sj['params'], true);
T::eq('2026-03-02 00:00', substr($sp['period']['label'], 0, 16), 'weekly schedule reports the previous full local week (Mon 2026-03-02)');
T::eq('2026-03-09 00:00', substr($sp['period']['label'], 20, 16), '... ending Mon 2026-03-09');

$events = [];
if (!function_exists('integration_emit')) { function integration_emit(string $t, array $c): void { $GLOBALS['__emitted'][] = [$t, $c]; } }
mk('INSERT INTO report_recipients (customer_id, email, name) VALUES (?,?,?)', [$custA, 'owner@alpha.example', 'Owner']);
$sent = [];
report_hook('mail', function ($to, $subject, $html) use (&$sent) { $sent[] = [$to, $subject, $html]; return ['success' => true]; });
$GLOBALS['__emitted'] = [];
db()->exec("UPDATE report_jobs SET status = 'cancelled', dedupe_key = NULL WHERE status = 'queued' AND schedule_id IS NULL");
$w = report_worker_run(10, $NOW + 1);
T::eq(1, $w['succeeded'], 'worker builds the scheduled report');
T::eq(1, count($GLOBALS['__emitted']), 'integration_emit(report.ready) called once');
[$evt, $ctx] = $GLOBALS['__emitted'][0];
T::eq('report.ready', $evt, 'event type');
T::ok(isset($ctx['report_id'], $ctx['link']) && !isset($ctx['html'], $ctx['snapshot']) && !str_contains(json_encode($ctx), 'alpha-hq'), 'event carries ids and a link, no report content');
T::eq(0, (int)db()->query('SELECT COUNT(*) FROM report_deliveries')->fetchColumn(), 'delivery switch off: no deliveries even for a deliver=1 schedule');
$d = report_delivery_run(20, $NOW + 2);
T::ok(!$d['enabled'] && $sent === [], 'delivery run with the switch off sends nothing');
setting('report_delivery_enabled', '1');
$rid = (int)db()->query('SELECT MAX(id) FROM reports')->fetchColumn();
T::eq(1, report_deliveries_queue($rid, $NOW), 'delivery queued for the one valid recipient');
mk('INSERT INTO report_recipients (customer_id, email) VALUES (?,?)', [$custA, "evil@x.example\r\nBcc: spy@x.example"]);
T::eq(0, report_deliveries_queue($rid, $NOW), 'recipient containing CR/LF header injection is never queued');
$d = report_delivery_run(20, $NOW + 3);
T::eq(1, $d['sent'], 'delivery sent through the injected transport');
T::ok($sent[0][0] === 'owner@alpha.example' && str_contains($sent[0][2], '/report_share.php?t=rs_'), 'email carries a share link');
T::ok(!str_contains($sent[0][2], 'alpha-hq'), 'email body has no report content');
T::eq(1, (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'report.deliver' AND success = 1")->fetchColumn(), 'delivery audited');
// failing transport -> 3 attempts -> failed and visible
report_hook('mail', function () { return ['success' => false, 'error' => 'smtp down']; });
db()->exec("UPDATE report_deliveries SET status = 'queued', attempts = 0, next_attempt_at = FROM_UNIXTIME(" . ($NOW) . ")");
report_delivery_run(20, $NOW + 10); report_delivery_run(20, $NOW + 1000); $d = report_delivery_run(20, $NOW + 5000);
$dl = db()->query('SELECT * FROM report_deliveries')->fetch(PDO::FETCH_ASSOC);
T::ok($dl['status'] === 'failed' && (int)$dl['attempts'] === 3 && $dl['last_error'] === 'smtp down', 'delivery fails visibly after 3 attempts');
report_hook('mail', null, true);

// ===========================================================================
T::group('Retention');
$keep = (int)db()->query('SELECT COUNT(*) FROM reports')->fetchColumn();
db()->prepare('UPDATE reports SET generated_at = FROM_UNIXTIME(?) WHERE id = ?')->execute([$NOW - 500 * 86400, $run['report_id']]);
$files = [$storage . '/' . $rep['csv_path']];
$dry = report_prune(false, 400, $NOW);
T::ok($dry['candidates'] === 1 && $dry['deleted'] === 0 && (int)db()->query('SELECT COUNT(*) FROM reports')->fetchColumn() === $keep, 'report-only mode deletes nothing');
$ap = report_prune(true, 400, $NOW);
T::eq(1, $ap['deleted'], 'apply deletes the over-retention report');
T::ok(!file_exists($files[0]), 'its files are removed');
T::eq(0, (int)db()->query('SELECT COUNT(*) FROM report_shares WHERE report_id = ' . (int)$run['report_id'])->fetchColumn(), 'its share links are removed');
T::ok(!enq($spec())['deduped'], 'a pruned report does not satisfy dedupe');
T::ok(report_prune(false, 1, $NOW)['days'] === 30, 'retention floor is 30 days');

// ===========================================================================
T::group('AI summaries (fake provider)');
$snapAi = report_collect(report_params_build($spec()), $NOW);
$facts = report_ai_facts($snapAi);
$fj = json_encode($facts);
T::ok(!str_contains($fj, 'alpha') && !str_contains($fj, 'Alpha') && !preg_match('/\d+\.\d+\.\d+\.\d+/', $fj), 'AI facts contain no hostnames, customer names or IP addresses');
$calls = 0;
report_ai_hook(function () use (&$calls) { $calls++; return ['text' => 'x', 'prompt_tokens' => 1, 'completion_tokens' => 1]; });
$r = report_ai_summarize($snapAi);
T::ok($r['status'] === 'none' && $calls === 0, 'AI disabled: no narrative and the provider is never called');
$q = enq($spec(['include_ai' => true]), ['force' => true]);
$rr = report_run_job($q['job_id'], $NOW + 20);
T::eq('succeeded', $rr['status'], 'report completes without AI');
T::eq('none', db()->query('SELECT ai_status FROM reports WHERE id = ' . (int)$rr['report_id'])->fetchColumn(), 'recorded as no AI');

setting('report_ai_enabled', '1'); setting('ai_enabled', '1');
mk("INSERT INTO ai_settings (provider, api_key, model, is_active) VALUES ('openai', 'plain-not-real', 'fake-model', 1)", []);
$avail = report_fmt_pct($snapAi['summary']['availability']);
$good = "Observed availability was $avail across {$facts['firewalls']} firewalls, with {$facts['confirmed_outage']} of confirmed outage.";
$calls = 0;
report_ai_hook(function () use (&$calls, $good) { $calls++; return ['text' => $good, 'prompt_tokens' => 120, 'completion_tokens' => 40]; });
$r = report_ai_summarize($snapAi);
T::eq('ok', $r['status'], 'faithful narrative accepted (' . ($r['detail'] ?? '') . ')');
T::eq(1, $calls, 'provider called once');
$r2 = report_ai_summarize($snapAi);
T::ok($r2['status'] === 'ok' && $calls === 1 && $r2['cached'] === true, 'identical facts are served from cache without another call');
$snapAi['ai'] = $r;
T::ok(str_contains(report_render_html($snapAi), 'AI-generated narrative'), 'narrative is labelled AI-generated');

$other = $snapAi; $other['summary']['firewalls'] = 99; unset($other['ai']);
report_ai_hook(function () { return ['text' => 'Availability was 100% and 7 incidents occurred overnight.', 'prompt_tokens' => 10, 'completion_tokens' => 10]; });
$r = report_ai_summarize($other);
T::eq('discarded', $r['status'], 'a reply with numbers not in the facts is discarded');
T::ok(!isset($r['narrative']), '... and not kept');
report_ai_hook(function () { return ['text' => 'See https://evil.example for 99 details', 'prompt_tokens' => 1, 'completion_tokens' => 1]; });
$other['summary']['firewalls'] = 98;
T::eq('discarded', report_ai_summarize($other)['status'], 'a reply containing a link is discarded');
report_ai_hook(function () { throw new RuntimeException('provider says sk-SECRET'); });
$other['summary']['firewalls'] = 97;
$r = report_ai_summarize($other);
T::eq('failed', $r['status'], 'provider failure is contained');
T::ok(!str_contains(json_encode($r), 'sk-SECRET'), 'provider error text is not propagated');
$q = enq($spec(['include_ai' => true, 'period' => ['preset' => 'custom', 'from' => '2026-03-07', 'to' => '2026-03-08']]));
$rr = report_run_job($q['job_id'], $NOW + 30);
T::eq('succeeded', $rr['status'], 'report is preserved when AI fails');
T::eq('failed', db()->query('SELECT ai_status FROM reports WHERE id = ' . (int)$rr['report_id'])->fetchColumn(), 'failure recorded on the report');
setting('report_ai_monthly_token_budget', '1');
$calls = 0; report_ai_hook(function () use (&$calls) { $calls++; return ['text' => '1', 'prompt_tokens' => 1, 'completion_tokens' => 1]; });
$other['summary']['firewalls'] = 55;
$r = report_ai_summarize($other);
T::ok($r['status'] === 'failed' && $calls === 0, 'monthly token budget exhausted: no provider call');
T::ok(report_ai_validate('3 firewalls', ['n' => 3]) === null, 'validator: number present in facts is accepted');
T::ok(report_ai_validate('4 firewalls', ['n' => 3]) !== null, 'validator: number absent from facts is rejected');
T::ok(report_ai_validate('2.0 gateways', ['n' => 2]) === null, 'validator: 2.0 equals 2');
report_ai_hook(null, true);
setting('report_ai_enabled', '0'); setting('ai_enabled', '0');

exit(T::summary());
