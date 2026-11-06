<?php
/**
 * Integrations pipeline tests: envelope, routing, suppression, delivery
 * reliability, SSRF guard, connectors, secrets, scoping.
 *
 * Uses a local mock HTTP endpoint (php -S on 127.0.0.1) and never contacts
 * a real service. Run against a disposable database:
 *   DB_NAME=opnsense_fw_test_integrations php tests/integration_pipeline_test.php
 */

putenv('OPNMGR_MASTER_KEY=' . base64_encode(random_bytes(32)));
require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/alerting.php';
require_once TEST_ROOT . '/inc/maintenance.php';
require_once TEST_ROOT . '/inc/integrations/events.php';
require_once TEST_ROOT . '/inc/integrations/delivery.php';

if (strpos((string)db()->query('SELECT DATABASE()')->fetchColumn(), 'opnsense_fw_test') !== 0) { fwrite(STDERR, "refusing: not a test database\n"); exit(2); }

// --- mock endpoint ---------------------------------------------------------
$mockDir = sys_get_temp_dir() . '/opn_int_mock_' . bin2hex(random_bytes(4));
mkdir($mockDir);
file_put_contents($mockDir . '/router.php', <<<'PHP'
<?php
$dir = __DIR__;
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');
$h = [];
foreach ($_SERVER as $k => $v) { if (strpos($k, 'HTTP_') === 0) { $h[strtolower(str_replace('_', '-', substr($k, 5)))] = $v; } }
file_put_contents($dir . '/log.jsonl', json_encode(['path' => $uri, 'method' => $_SERVER['REQUEST_METHOD'], 'headers' => $h, 'body' => $body]) . "\n", FILE_APPEND | LOCK_EX);
if ($uri === '/rate') {
    $f = $dir . '/rate_hits'; $n = (int)@file_get_contents($f); file_put_contents($f, $n + 1);
    if ($n === 0) { http_response_code(429); header('Retry-After: 7'); echo 'rate'; exit; }
    echo 'ok'; exit;
}
if ($uri === '/fail500') { http_response_code(500); echo 'boom'; exit; }
if ($uri === '/redirect') { http_response_code(302); header('Location: /redirected'); exit; }
if ($uri === '/bad') { http_response_code(400); echo 'invalid_payload'; exit; }
echo 'ok';
PHP);
$port = random_int(20000, 40000);
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $mockDir . '/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $mockDir);
register_shutdown_function(function () use ($srv, $mockDir) { proc_terminate($srv); array_map('unlink', glob($mockDir . '/*')); @rmdir($mockDir); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) { break; } usleep(100000); }
$base = "http://127.0.0.1:$port";
function mock_log(): array { global $mockDir; $f = @file($mockDir . '/log.jsonl', FILE_IGNORE_NEW_LINES) ?: []; return array_map(fn($l) => json_decode($l, true), $f); }
function mock_count(string $path): int { return count(array_filter(mock_log(), fn($r) => $r['path'] === $path)); }
function mock_reset(): void { global $mockDir; foreach (glob($mockDir . '/{log.jsonl,rate_hits}', GLOB_BRACE) as $f) { unlink($f); } }

// --- fixtures ---------------------------------------------------------------
function reset_integration_tables(): void {
    foreach (['integration_delivery_attempts', 'integration_deliveries', 'integration_events', 'integration_routes', 'integration_ticket_links',
              'integration_escalations', 'integration_inbound_nonces', 'integration_inbound_log', 'integration_destinations'] as $t) {
        db()->exec("DELETE FROM {$t}");
    }
}
reset_integration_tables();
$_SESSION['user_id'] = 1; $_SESSION['username'] = 'int-test'; $_SESSION['role'] = 'admin';

db()->prepare('INSERT INTO customers (name) VALUES (?)')->execute(['__int_cust_A__']); $custA = (int)db()->lastInsertId();
db()->prepare('INSERT INTO customers (name) VALUES (?)')->execute(['__int_cust_B__']); $custB = (int)db()->lastInsertId();
db()->prepare('INSERT INTO sites (customer_id, name) VALUES (?,?)')->execute([$custA, 'Site A1']); $siteA = (int)db()->lastInsertId();
db()->prepare('INSERT INTO tags (name) VALUES (?)')->execute(['__int_tag_' . bin2hex(random_bytes(3))]); $tagId = (int)db()->lastInsertId();
function mkfw(string $name, int $cust, ?int $site): int {
    db()->prepare('INSERT INTO firewalls (hostname, ip_address, hardware_id, status, customer_id, site_id) VALUES (?,?,?,"online",?,?)')
        ->execute([$name, '198.51.100.' . random_int(2, 250), md5($name . random_bytes(4)), $cust, $site]);
    return (int)db()->lastInsertId();
}
$fwA = mkfw('__int_fw_A__', $custA, $siteA);
$fwB = mkfw('__int_fw_B__', $custB, null);
db()->prepare('INSERT INTO firewall_tags (firewall_id, tag_id) VALUES (?,?)')->execute([$fwA, $tagId]);

function mkdest(string $type, array $secrets, array $over = []): int {
    $r = integration_destination_save($over + ['name' => $type . '-' . bin2hex(random_bytes(3)), 'type' => $type, 'secrets' => $secrets,
        'config' => $over['config'] ?? [], 'allow_internal' => 1, 'timeout_seconds' => 2, 'max_attempts' => 3], null, 'test');
    if (!$r['ok']) { throw new RuntimeException('mkdest: ' . $r['error']); }
    return $r['id'];
}
function mkroute(int $dest, array $over = []): int {
    $r = integration_route_save($over + ['name' => 'r' . bin2hex(random_bytes(2)), 'destination_id' => $dest], null, 'test');
    if (!$r['ok']) { throw new RuntimeException('mkroute: ' . $r['error']); }
    return $r['id'];
}
/** Run the whole pipeline once, forcing retry rows to be due. */
function pump(int $rounds = 1): void {
    for ($i = 0; $i < $rounds; $i++) {
        db()->exec("UPDATE integration_deliveries SET next_attempt_at = NOW() WHERE status IN ('pending','retry')");
        db()->exec('UPDATE integration_destinations SET paused_until = NULL');
        integration_run_cycle(10);
    }
}
function row(string $sql, array $p = []): ?array { $s = db()->prepare($sql); $s->execute($p); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
function dstatus(int $id): string { return (string)row('SELECT status FROM integration_deliveries WHERE id=?', [$id])['status']; }

register_shutdown_function(function () use ($custA, $custB, $fwA, $fwB, $tagId) {
    try {
        reset_integration_tables();
        db()->exec('DELETE FROM integration_events');
        foreach ([$fwA, $fwB] as $f) {
            db()->prepare('DELETE e FROM alert_incident_events e JOIN alert_incidents i ON i.id=e.incident_id WHERE i.firewall_id=?')->execute([$f]);
            db()->prepare('DELETE FROM alert_incidents WHERE firewall_id=?')->execute([$f]);
            db()->prepare('DELETE FROM maintenance_windows WHERE scope="firewall" AND scope_id=?')->execute([$f]);
            db()->prepare('DELETE FROM firewalls WHERE id=?')->execute([$f]);
        }
        db()->prepare('DELETE FROM tags WHERE id=?')->execute([$tagId]);
        db()->prepare('DELETE FROM user_customer_scopes WHERE user_id=999001')->execute();
        db()->prepare('DELETE FROM users WHERE username="__int_admin__"')->execute();
        db()->prepare('DELETE FROM alert_settings WHERE setting_name IN ("email_enabled","alerts_critical_enabled")')->execute();
        db()->prepare('DELETE FROM sites WHERE customer_id IN (?,?)')->execute([$custA, $custB]);
        db()->prepare('DELETE FROM customers WHERE id IN (?,?)')->execute([$custA, $custB]);
        db()->prepare('DELETE FROM settings WHERE name="integrations_legacy_email_owner"')->execute();
        db()->exec("INSERT IGNORE INTO settings (name,value) VALUES ('integrations_legacy_email_owner','legacy')");
    } catch (Throwable $e) {}
});

// ===========================================================================
T::group('Envelope: versioned, complete, and free of credentials');

$eid = integration_emit('policy.deployment_failed', [
    'firewall_id' => $fwA,
    'summary' => 'Deploy failed password=hunter2secret token: abcdef0123456789abcdef0123456789abcdef01',
    'details' => ['reason' => 'rule rejected', 'ssh_private_key' => 'KEYDATA', 'api_secret' => 'S3CR3T', 'config_xml' => '<opnsense>raw</opnsense>',
                  'note' => "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAA\n-----END OPENSSH PRIVATE KEY-----", 'nested' => ['password' => 'pw']],
    'link_path' => '/policies.php?x=1',
]);
T::ok(is_string($eid) && strlen($eid) === 36, 'emit returns an event id');
$ev = row('SELECT * FROM integration_events WHERE event_id = ?', [$eid]);
$env = json_decode($ev['envelope_json'], true);
T::eq('opnmanager.event', $env['schema'], 'schema name');
T::eq(1, $env['version'], 'envelope version');
T::eq('policy.deployment_failed', $env['type'], 'event type');
T::eq($custA, $env['customer']['id'], 'customer id');
T::eq($siteA, $env['site']['id'], 'site id');
T::eq($fwA, $env['firewall']['id'], 'firewall id');
T::ok(array_key_exists('platform', $env) && array_key_exists('correlation_id', $env) && !empty($env['occurred_at']), 'platform, correlation id and timestamp present');
T::ok(str_ends_with($env['links']['manager'], '/policies.php?x=1'), 'manager link built from the supplied path');
$json = $ev['envelope_json'];
foreach (['hunter2secret', 'abcdef0123456789abcdef0123456789abcdef01', 'KEYDATA', 'S3CR3T', '<opnsense>', 'BEGIN OPENSSH', '"pw"'] as $needle) {
    T::ok(strpos($json, $needle) === false, "envelope does not contain '" . substr($needle, 0, 20) . "'");
}
T::ok(strpos($json, 'rule rejected') !== false, 'harmless detail is kept');

$e1 = integration_emit('report.ready', ['dedupe_key' => 'rpt:1', 'summary' => 'Report ready']);
$e2 = integration_emit('report.ready', ['dedupe_key' => 'rpt:1', 'summary' => 'Report ready']);
T::eq($e1, $e2, 'emit with the same dedupe key returns the original event');
T::eq(1, (int)row('SELECT COUNT(*) c FROM integration_events WHERE dedupe_key="rpt:1"')['c'], 'only one outbox row for a repeated emit');
T::eq(null, integration_emit('not.a.type', []), 'unknown event type returns null without throwing');

// ===========================================================================
T::group('Secrets at rest and in errors');

$dSecret = mkdest('webhook', ['url' => $base . '/ok', 'signing_secret' => 'super-signing-secret-1234']);
$raw = row('SELECT secrets_enc, config_json FROM integration_destinations WHERE id=?', [$dSecret]);
T::ok(str_starts_with($raw['secrets_enc'], 'enc:v1:'), 'secrets are stored encrypted');
T::ok(strpos($raw['secrets_enc'] . $raw['config_json'], 'super-signing-secret') === false, 'no plaintext secret in the row');
$l = integration_destination_load($dSecret);
T::eq('super-signing-secret-1234', $l['secrets']['signing_secret'], 'secrets decrypt on load');
T::ok(strpos(json_encode(integration_destination_masked($l)), 'super-signing') === false, 'masked view hides the secret');
$audit = row("SELECT metadata FROM audit_log WHERE action='integration.destination_created' ORDER BY id DESC LIMIT 1");
T::ok($audit && strpos($audit['metadata'], 'super-signing') === false && strpos($audit['metadata'], '127.0.0.1') === false, 'audit entry carries no secret or URL');
$red = integration_redact_string('POST https://hooks.slack.com/services/T000/B000/XXXXXXXXXXXX failed; Authorization: Bearer abc123def456ghi; key super-signing-secret-1234', ['super-signing-secret-1234']);
T::ok(strpos($red, 'XXXXXXXX') === false && strpos($red, 'abc123def456') === false && strpos($red, 'super-signing') === false, 'error redaction removes webhook paths, bearer tokens and known secrets');
$upd = integration_destination_save(['name' => $l['name'], 'type' => 'webhook', 'secrets' => ['url' => '', 'signing_secret' => ''], 'allow_internal' => 1], $dSecret, 'test');
T::eq('super-signing-secret-1234', integration_destination_load($dSecret)['secrets']['signing_secret'], 'blank secret fields keep the stored value on edit');

// ===========================================================================
T::group('Routing: customer, site, severity, type, tag, schedule, escalation; preview');

reset_integration_tables();
$dAll = mkdest('webhook', ['url' => $base . '/ok', 'signing_secret' => 'abcdefghijklmnopqrst']);
$dCrit = mkdest('webhook', ['url' => $base . '/ok', 'signing_secret' => 'abcdefghijklmnopqrst']);
$dTag = mkdest('webhook', ['url' => $base . '/ok', 'signing_secret' => 'abcdefghijklmnopqrst']);
$dB = mkdest('webhook', ['url' => $base . '/ok', 'signing_secret' => 'abcdefghijklmnopqrst']);
$rAll = mkroute($dAll, ['customer_id' => $custA]);
$rCrit = mkroute($dCrit, ['severities' => ['critical'], 'incident_types' => 'gateway.down', 'site_id' => $siteA]);
$rTag = mkroute($dTag, ['tag_ids' => [$tagId]]);
$rB = mkroute($dB, ['customer_id' => $custB]);
$sample = ['event_type' => 'incident.opened', 'customer_id' => $custA, 'site_id' => $siteA, 'firewall_id' => $fwA, 'incident_id' => null, 'severity' => 'critical'];
$dec = integration_routing_decisions($sample, null, ['alert_type' => 'gateway.down', 'tag_ids' => [$tagId], 'incident' => null]);
$deliver = array_map(fn($d) => (int)$d['destination']['id'], array_filter($dec, fn($d) => $d['verdict'] === 'deliver'));
T::eq([$dAll, $dCrit, $dTag], array_values($deliver), 'preview: customer, site/severity/type and tag routes match; other customer does not');
$dec2 = integration_routing_decisions(['severity' => 'warning'] + $sample, null, ['alert_type' => 'gateway.down', 'tag_ids' => [], 'incident' => null]);
$deliver2 = array_values(array_map(fn($d) => (int)$d['destination']['id'], array_filter($dec2, fn($d) => $d['verdict'] === 'deliver')));
T::eq([$dAll], $deliver2, 'a warning without the tag reaches only the catch-all customer route');
$noMatchReason = array_values(array_filter($dec2, fn($d) => (int)$d['route']['id'] === $rCrit))[0]['reason'];
T::eq('severity not selected', $noMatchReason, 'preview explains why a route did not match');

// schedule: business hours in a timezone
$sched = ['sched_enabled' => 1, 'sched_timezone' => 'America/New_York', 'sched_start' => '09:00', 'sched_end' => '17:00', 'sched_days' => [1, 2, 3, 4, 5]];
$ny = new DateTimeZone('America/New_York');
$tsIn  = (new DateTime('2026-11-04 10:30', $ny))->getTimestamp();  // Wednesday
$tsOut = (new DateTime('2026-11-04 21:00', $ny))->getTimestamp();
$tsWe  = (new DateTime('2026-11-07 11:00', $ny))->getTimestamp();  // Saturday
$rs = mkroute($dAll, ['customer_id' => $custA, 'severities' => ['info'], 'name' => 'hours'] + $sched);
$jsonSched = row('SELECT schedule_json FROM integration_routes WHERE id=?', [$rs])['schedule_json'];
T::ok(integration_schedule_check($jsonSched, $tsIn)['in'], 'inside business hours (New York)');
T::ok(!integration_schedule_check($jsonSched, $tsOut)['in'], 'evening is outside business hours');
T::ok(!integration_schedule_check($jsonSched, $tsWe)['in'], 'Saturday is outside business hours');
$nx = integration_schedule_check($jsonSched, $tsWe)['next_start'];
T::eq('Mon 09:00', (new DateTime('@' . $nx))->setTimezone($ny)->format('D H:i'), 'next window starts Monday 09:00 local');
db()->prepare("UPDATE integration_routes SET severities='' WHERE id=?")->execute([$rs]);
db()->prepare('DELETE FROM integration_routes WHERE id=?')->execute([$rAll]);
$dd = array_values(array_filter(integration_routing_decisions($sample, $tsOut, ['alert_type' => 'x', 'tag_ids' => [], 'incident' => null]), fn($d) => (int)$d['route']['id'] === $rs))[0];
T::eq('suppress', $dd['verdict'], 'route outside its schedule withholds (skip mode)');
db()->prepare("UPDATE integration_routes SET schedule_outside='defer' WHERE id=?")->execute([$rs]);
$dd = array_values(array_filter(integration_routing_decisions($sample, $tsOut, ['alert_type' => 'x', 'tag_ids' => [], 'incident' => null]), fn($d) => (int)$d['route']['id'] === $rs))[0];
T::eq('defer', $dd['verdict'], 'defer mode schedules for the next window');

// escalation: unacknowledged after N minutes -> destination B
reset_integration_tables();
db()->exec('DELETE FROM integration_events');
$dFirst = mkdest('webhook', ['url' => $base . '/first', 'signing_secret' => 'abcdefghijklmnopqrst']);
$dEsc = mkdest('webhook', ['url' => $base . '/escalated', 'signing_secret' => 'abcdefghijklmnopqrst']);
mkroute($dFirst, ['event_types' => ['incident.opened']]);
$rEsc = mkroute($dEsc, ['escalate_after_minutes' => 15]);
$iid = alert_raise('gateway.down', ['firewall_id' => $fwA, 'object_key' => 'ESC_GW', 'title' => 'Gateway ESC_GW down']);
mock_reset();
pump();
T::eq(1, mock_count('/first'), 'first-line destination notified on open');
T::eq(0, mock_count('/escalated'), 'escalation destination not notified immediately');
integration_escalation_sweep(); pump();
T::eq(0, mock_count('/escalated'), 'no escalation before the threshold');
db()->prepare("UPDATE alert_incidents SET first_seen_at = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE id=?")->execute([$iid]);
integration_escalation_sweep(); integration_escalation_sweep(); pump();
T::eq(1, mock_count('/escalated'), 'unacknowledged after 15 minutes escalates once');
$iid2 = alert_raise('gateway.down', ['firewall_id' => $fwA, 'object_key' => 'ESC2', 'title' => 'Gateway ESC2 down']);
db()->prepare("UPDATE alert_incidents SET first_seen_at = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE id=?")->execute([$iid2]);
alert_acknowledge($iid2, 'on it'); mock_reset();
integration_escalation_sweep(); pump();
T::eq(0, mock_count('/escalated'), 'an acknowledged incident never escalates');
alert_resolve('gateway.down', $fwA, 'ESC_GW'); alert_resolve('gateway.down', $fwA, 'ESC2');

// ===========================================================================
T::group('Idempotency, retries, dead letter, rate limit, replay');

reset_integration_tables(); db()->exec('DELETE FROM integration_events'); mock_reset();
$dOk = mkdest('webhook', ['url' => $base . '/ok', 'signing_secret' => 'abcdefghijklmnopqrst']);
mkroute($dOk);
$e = integration_emit('report.ready', ['summary' => 'Weekly report ready', 'customer_id' => $custA, 'dedupe_key' => 'rpt:dup']);
$evRow = row('SELECT * FROM integration_events WHERE event_id=?', [$e]);
integration_route_event($evRow); integration_route_event($evRow);
T::eq(1, (int)row('SELECT COUNT(*) c FROM integration_deliveries')['c'], 'routing the same event twice yields one delivery (idempotency key)');
pump(); pump();
T::eq(1, mock_count('/ok'), 'the same idempotency key is sent exactly once');
$del = row('SELECT * FROM integration_deliveries'); 
T::eq('succeeded', $del['status'], 'delivery succeeded');
$sent = mock_log()[0];
T::eq($del['idempotency_key'], $sent['headers']['x-opnmanager-idempotency-key'], 'idempotency key is sent as a header');
$ts = $sent['headers']['x-opnmanager-timestamp'];
T::eq('v1=' . hash_hmac('sha256', $ts . '.' . $sent['body'], 'abcdefghijklmnopqrst'), $sent['headers']['x-opnmanager-signature'], 'HMAC-SHA256 over timestamp.body verifies');
T::eq($e, json_decode($sent['body'], true)['id'], 'body carries the stable event id');
T::ok(!empty(json_decode($sent['body'], true)['delivery']['id']), 'body carries the delivery identifier');

// backoff
foreach ([1 => 30, 2 => 60, 3 => 120, 8 => 3600] as $n => $base0) {
    $lo = integration_backoff_seconds($n, 0.0); $hi = integration_backoff_seconds($n, 0.999);
    T::ok($lo >= $base0 * 0.8 - 1 && $hi <= $base0 * 1.2 + 1 && $lo < $hi, "attempt $n backs off ~{$base0}s with jitter ({$lo}-{$hi})");
}
// retry -> dead
reset_integration_tables(); db()->exec('DELETE FROM integration_events'); mock_reset();
$dFail = mkdest('webhook', ['url' => $base . '/fail500', 'signing_secret' => 'abcdefghijklmnopqrst'], ['max_attempts' => 3]);
mkroute($dFail);
integration_emit('report.ready', ['summary' => 'x', 'customer_id' => $custA]);
integration_route_pending_events();
$did = (int)row('SELECT id FROM integration_deliveries')['id'];
pump();
$r1 = row('SELECT * FROM integration_deliveries WHERE id=?', [$did]);
T::eq('retry', $r1['status'], 'first 500 schedules a retry');
T::ok(strtotime($r1['next_attempt_at']) > time() + 15, 'retry is scheduled in the future (backoff)');
pump(); T::eq('retry', dstatus($did), 'second failure still retries');
pump(); 
$r3 = row('SELECT * FROM integration_deliveries WHERE id=?', [$did]);
T::eq('dead', $r3['status'], 'retry limit reached -> dead letter');
T::eq(3, (int)$r3['attempts'], 'exactly max_attempts attempts were made');
T::eq(3, mock_count('/fail500'), 'no sends beyond the retry limit');
pump(); T::eq(3, mock_count('/fail500'), 'a dead delivery is not retried automatically');
T::eq(3, (int)row('SELECT COUNT(*) c FROM integration_delivery_attempts WHERE delivery_id=?', [$did])['c'], 'every attempt is recorded');
// replay
db()->prepare('UPDATE integration_destinations SET secrets_enc=? WHERE id=?')->execute([opnmgr_encrypt(json_encode(['url' => $base . '/ok', 'signing_secret' => 'abcdefghijklmnopqrst'])), $dFail]);
$key = $r3['idempotency_key'];
$rp = integration_replay($did, 'tester');
T::ok($rp['ok'], 'manual replay of a dead delivery is accepted');
T::eq('pending', dstatus($did), 'replayed delivery is queued again');
T::eq($key, row('SELECT idempotency_key FROM integration_deliveries WHERE id=?', [$did])['idempotency_key'], 'replay keeps the idempotency key');
mock_reset(); pump();
T::eq('succeeded', dstatus($did), 'replayed delivery succeeds once the endpoint is fixed');
$rp2 = integration_replay($did, 'tester');
T::ok($rp2['ok'] && $rp2['id'] !== $did, 'replaying a succeeded notification creates a new delivery');
mock_reset(); pump();
T::eq(1, mock_count('/ok'), 'the re-send is sent once');
T::ok(!integration_replay($rp2['id'], 'tester')['ok'] || dstatus($rp2['id']) === 'succeeded', 'cannot replay what is not finished');

// 429
reset_integration_tables(); db()->exec('DELETE FROM integration_events'); mock_reset();
$dRate = mkdest('slack', ['url' => $base . '/rate']);
mkroute($dRate);
integration_emit('report.ready', ['summary' => 'rate', 'customer_id' => $custA]);
integration_route_pending_events();
$leased = integration_lease_deliveries(5);
integration_process_delivery($leased[0]);
$r = row('SELECT * FROM integration_deliveries WHERE id=?', [$leased[0]['id']]);
T::eq('retry', $r['status'], '429 puts the delivery back to retry');
T::eq(0, (int)$r['attempts'], '429 does not consume the retry budget');
$wait = strtotime($r['next_attempt_at']) - time();
T::ok($wait >= 6 && $wait <= 14, "Retry-After (7s) honoured (next attempt in ~{$wait}s)");
$dd = row('SELECT paused_until FROM integration_destinations WHERE id=?', [$dRate]);
T::ok($dd['paused_until'] && strtotime($dd['paused_until']) > time() + 4, 'the destination is paused for the Retry-After period');
db()->exec('UPDATE integration_deliveries SET next_attempt_at = NOW()');
T::eq([], integration_lease_deliveries(5), 'a paused destination is not leased even when a delivery is due');
db()->exec('UPDATE integration_destinations SET paused_until = NULL');
T::eq(1, count(integration_lease_deliveries(5)), 'leasing resumes after the pause');

// ===========================================================================
T::group('Suppression: maintenance, acknowledgement, origin; legacy email ownership');

reset_integration_tables(); db()->exec('DELETE FROM integration_events'); mock_reset();
$dN = mkdest('slack', ['url' => $base . '/notify']);
mkroute($dN);
// maintenance
db()->prepare("INSERT INTO maintenance_windows (scope, scope_id, starts_at, ends_at, status, suppress_alerts) VALUES ('firewall',?,DATE_SUB(NOW(),INTERVAL 1 HOUR),DATE_ADD(NOW(),INTERVAL 1 HOUR),'active',1)")->execute([$fwA]);
maintenance_reset_cache();
$im = alert_raise('gateway.down', ['firewall_id' => $fwA, 'object_key' => 'MAINT', 'title' => 'Gateway MAINT down']);
pump();
T::eq(0, mock_count('/notify'), 'no notification for an incident inside a maintenance window');
$sup = row("SELECT * FROM integration_deliveries WHERE incident_id=? AND event_type='incident.opened'", [$im]);
T::eq('suppressed', $sup['status'], 'the withheld notification is recorded as suppressed');
T::eq('maintenance window active', $sup['suppress_reason'], 'with the reason');
alert_resolve('gateway.down', $fwA, 'MAINT'); pump();
T::eq(0, mock_count('/notify'), 'no "resolved" message for an incident nobody was told about');
db()->exec('DELETE FROM maintenance_windows WHERE scope="firewall"'); maintenance_reset_cache();
// ack between routing and send
$ia = alert_raise('gateway.down', ['firewall_id' => $fwA, 'object_key' => 'ACKME', 'title' => 'Gateway ACKME down']);
integration_route_pending_events();
alert_acknowledge($ia, 'seen');
pump();
T::eq(0, mock_count('/notify'), 'an incident acknowledged before sending is not notified (send-time gate)');
T::eq('incident acknowledged', row("SELECT suppress_reason FROM integration_deliveries WHERE incident_id=? AND event_type='incident.opened'", [$ia])['suppress_reason'], 'suppression reason recorded');
alert_resolve('gateway.down', $fwA, 'ACKME');
// normal open -> ack -> resolve follow-ups reach a destination that was told
$ib = alert_raise('gateway.down', ['firewall_id' => $fwA, 'object_key' => 'FLOW', 'title' => 'Gateway FLOW down']);
pump(); T::eq(1, mock_count('/notify'), 'opened notifies');
alert_acknowledge($ib, 'x'); alert_resolve('gateway.down', $fwA, 'FLOW'); pump();
T::eq(3, mock_count('/notify'), 'acknowledged and resolved follow the opened message');
alert_raise('gateway.down', ['firewall_id' => $fwA, 'object_key' => 'FLOW2', 'title' => 'x']);
pump(); mock_reset(); 
for ($k = 0; $k < 5; $k++) { alert_raise('gateway.down', ['firewall_id' => $fwA, 'object_key' => 'FLOW2', 'title' => 'x']); }
pump(); T::eq(0, mock_count('/notify'), 'repeat sightings of a persisting condition emit nothing');
alert_resolve('gateway.down', $fwA, 'FLOW2');

// legacy / new pipeline duplicate protection
reset_integration_tables(); db()->exec('DELETE FROM integration_events');
db()->prepare("INSERT INTO users (username, password, email, role, alert_levels) VALUES ('__int_admin__','x','admin-legacy@example.test','admin','warning,critical')")->execute();
db()->exec("INSERT INTO alert_settings (setting_name, setting_value) VALUES ('email_enabled','1'),('alerts_critical_enabled','1') ON DUPLICATE KEY UPDATE setting_value='1'");
$mails = [];
$GLOBALS['OPNMGR_INTEGRATION_EMAIL_SENDER'] = function ($to, $subj, $html, $text) use (&$mails) { $mails[] = $to; return ['success' => true]; };
$dMail = mkdest('email', [], ['config' => ['recipients' => 'admin-legacy@example.test, oncall@example.test']]);
mkroute($dMail);
db()->exec("UPDATE settings SET value='legacy' WHERE name='integrations_legacy_email_owner'");
$ic = alert_raise('gateway.down', ['firewall_id' => $fwA, 'object_key' => 'MAIL', 'title' => 'Gateway MAIL down']);
pump();
sort($mails);
T::eq(['oncall@example.test'], $mails, 'legacy owns email: the integration drops recipients the legacy pipeline already mails');
T::eq('succeeded', row("SELECT status FROM integration_deliveries WHERE incident_id=? AND event_type='incident.opened'", [$ic])['status'], 'delivery still completes for the remaining recipient');
alert_resolve('gateway.down', $fwA, 'MAIL');
pump(); $mails = [];
db()->exec("UPDATE settings SET value='integrations' WHERE name='integrations_legacy_email_owner'");
$id2 = alert_raise('gateway.down', ['firewall_id' => $fwA, 'object_key' => 'MAIL2', 'title' => 'Gateway MAIL2 down']);
pump(); sort($mails);
T::eq(['admin-legacy@example.test', 'oncall@example.test'], $mails, 'after migration to integrations the destination mails everyone');
T::eq('integrations', integration_legacy_email_owner(), 'owner setting switches');
T::ok(strpos(file_get_contents(TEST_ROOT . '/cron/evaluate_alerts.php'), "integration_legacy_email_owner() === 'integrations'") !== false, 'legacy evaluator stands down when integrations own email');
alert_resolve('gateway.down', $fwA, 'MAIL2');
db()->exec("UPDATE settings SET value='legacy' WHERE name='integrations_legacy_email_owner'");
unset($GLOBALS['OPNMGR_INTEGRATION_EMAIL_SENDER']);

// ===========================================================================
T::group('SSRF guard, redirects, DNS pinning');

foreach (['10.1.2.3', '127.0.0.1', '169.254.169.254', '192.168.1.1', '172.16.0.1', '100.64.0.1', '::1', 'fe80::1', 'fc00::1', '::ffff:127.0.0.1', '0.0.0.0', 'fd00:ec2::254'] as $ip) {
    T::ok(integration_ip_block_reason($ip) !== null, "$ip is blocked by default");
}
foreach (['8.8.8.8', '93.184.216.34', '2606:4700:4700::1111'] as $ip) { T::ok(integration_ip_block_reason($ip) === null, "$ip is allowed"); }
T::ok(integration_validate_url('https://user:pw@example.com/x', false)[0] === null, 'credentials in URLs are refused');
T::ok(integration_validate_url('http://example.com/x', false)[0] === null, 'plain http refused for external endpoints');
T::ok(integration_validate_url('file:///etc/passwd', true)[0] === null, 'non-http schemes refused');

reset_integration_tables(); db()->exec('DELETE FROM integration_events'); mock_reset();
$dBlk = mkdest('webhook', ['url' => "https://127.0.0.1:$port/ok", 'signing_secret' => 'abcdefghijklmnopqrst'], ['allow_internal' => 0]);
mkroute($dBlk);
integration_emit('report.ready', ['summary' => 'ssrf', 'customer_id' => $custA]);
pump();
$bl = row('SELECT * FROM integration_deliveries');
T::eq('dead', $bl['status'], 'a loopback target is refused without the internal flag');
T::ok(strpos($bl['last_error'], 'blocked') !== false, 'error says the address is blocked');
T::eq(0, mock_count('/ok'), 'no request was made to the blocked address');
$GLOBALS['OPNMGR_INTEGRATION_RESOLVER'] = fn($h) => ['169.254.169.254'];
$x = integration_http_request('https://metadata.attacker.test/latest', []);
T::ok($x['blocked'] && !$x['sent'], 'a hostname resolving to the metadata address is refused');
$GLOBALS['OPNMGR_INTEGRATION_RESOLVER'] = fn($h) => ['93.184.216.34', '10.0.0.5'];
$p = integration_pin_target('https://mixed.test/', false);
T::eq('93.184.216.34', $p['ip'], 'with mixed answers the public address is pinned');
// pinning: a name that does not resolve in DNS is reachable only through the pinned address
$GLOBALS['OPNMGR_INTEGRATION_RESOLVER'] = fn($h) => ['127.0.0.1'];
mock_reset();
$x = integration_http_request("http://pinned.invalid:$port/ok", ['allow_internal' => true, 'method' => 'GET']);
T::ok($x['ok'] && $x['ip'] === '127.0.0.1', 'request goes to the pinned IP (CURLOPT_RESOLVE), not a second lookup');
unset($GLOBALS['OPNMGR_INTEGRATION_RESOLVER']);
// redirect
mock_reset();
$x = integration_http_request("$base/redirect", ['allow_internal' => true, 'method' => 'GET']);
T::ok(!$x['ok'] && $x['status'] === 302, 'redirect response is not treated as success');
T::eq(0, mock_count('/redirected'), 'the redirect target was never requested');
T::ok(strpos($x['error'], 'redirect not followed') !== false, 'error explains the redirect was not followed');
// address change alerts
$dIp = mkdest('webhook', ['url' => $base . '/ok', 'signing_secret' => 'abcdefghijklmnopqrst']);
db()->prepare("UPDATE integration_destinations SET last_ip='127.0.0.9' WHERE id=?")->execute([$dIp]);
mkroute($dIp);
integration_emit('report.ready', ['summary' => 'ipchange', 'customer_id' => $custA]);
pump();
$dd = row('SELECT * FROM integration_destinations WHERE id=?', [$dIp]);
T::eq(1, (int)$dd['ip_change_pending'], 'a changed endpoint address is flagged');
T::ok((bool)row("SELECT 1 FROM audit_log WHERE action='integration.endpoint_ip_changed' AND object_id=?", [(string)$dIp]), 'and audited');
db()->prepare("UPDATE integration_destinations SET ip_change_policy='block', last_ip='127.0.0.9', ip_change_pending=0 WHERE id=?")->execute([$dIp]);
mock_reset();
integration_emit('report.ready', ['summary' => 'ipchange2', 'customer_id' => $custA]);
pump();
T::eq(0, mock_count('/ok'), 'block policy holds delivery until an operator accepts the new address');
$held = row("SELECT status,last_error FROM integration_deliveries WHERE destination_id=? ORDER BY id DESC LIMIT 1", [$dIp]);
T::ok(strpos($held['last_error'], 'operator must accept') !== false, 'held delivery says why');

// ===========================================================================
T::group('Connectors: Slack, Teams, validation');

$teams = OpnmgrConnectorTeams::payload(['type' => 'incident.opened', 'occurred_at' => '2026-11-06T00:00:00Z', 'summary' => 'GW down', 'severity' => 'critical',
    'incident' => ['severity' => 'critical', 'status' => 'open'], 'firewall' => ['hostname' => 'fw1'], 'links' => ['manager' => 'https://m.example/incidents.php?incident=1']]);
T::eq('message', $teams['type'], 'Teams: message envelope');
$att = $teams['attachments'][0];
T::eq('application/vnd.microsoft.card.adaptive', $att['contentType'], 'Teams: adaptive card content type');
T::ok(array_key_exists('contentUrl', $att) && $att['contentUrl'] === null, 'Teams: contentUrl is null');
T::eq('AdaptiveCard', $att['content']['type'], 'Teams: AdaptiveCard');
T::eq('1.4', $att['content']['version'], 'Teams: card version 1.4');
T::eq('http://adaptivecards.io/schemas/adaptive-card.json', $att['content']['$schema'], 'Teams: schema');
T::ok(!empty($att['content']['body']), 'Teams: card body present');
foreach (['https://contoso.webhook.office.com/webhookb2/abc/IncomingWebhook/def', 'https://outlook.office.com/webhook/abc'] as $u) {
    T::ok(OpnmgrConnectorTeams::is_retired_url($u) && OpnmgrConnectorTeams::validate([], ['url' => $u], false) !== [], "retired connector URL rejected: " . parse_url($u, PHP_URL_HOST));
}
T::eq([], OpnmgrConnectorTeams::validate([], ['url' => 'https://prod-12.westus.logic.azure.com:443/workflows/abc/triggers/manual/paths/invoke?sig=x'], false), 'Workflows URL accepted');
T::ok(OpnmgrConnectorTeams::is_known_workflows_host('https://x.environment.api.powerplatform.com/powerautomate/automations/direct/workflows/1/triggers/manual/paths/invoke'), 'Power Platform host recognised');
$sv = integration_destination_save(['name' => 'old-teams', 'type' => 'teams', 'secrets' => ['url' => 'https://contoso.webhook.office.com/webhookb2/abc']], null, 'test');
T::ok(!$sv['ok'] && stripos($sv['error'], 'retired') !== false, 'saving a retired Teams URL is refused with an explanation');
$sl = OpnmgrConnectorSlack::payload(['type' => 'incident.opened', 'occurred_at' => 'x', 'summary' => 'A <b> & c', 'incident' => ['severity' => 'critical'], 'links' => ['manager' => 'https://m/x']]);
T::ok(isset($sl['text'], $sl['blocks']) && $sl['blocks'][0]['type'] === 'section', 'Slack: text and blocks');
T::ok(strpos(json_encode($sl), '<b>') === false, 'Slack: angle brackets escaped');
T::ok(OpnmgrConnectorSlack::validate([], ['url' => 'https://example.com/hook'], false) !== [], 'Slack: only hooks.slack.com accepted');
T::eq([], OpnmgrConnectorSlack::validate([], ['url' => 'https://hooks.slack.com/services/T0/B0/xyz'], false), 'Slack: hooks.slack.com accepted');
T::ok(OpnmgrConnectorWebhook::validate([], ['url' => 'https://example.com/x', 'signing_secret' => 'short'], false) !== [], 'Webhook: weak signing secret refused');

reset_integration_tables(); db()->exec('DELETE FROM integration_events'); mock_reset();
$dSl = mkdest('slack', ['url' => $base . '/bad']);
mkroute($dSl);
integration_emit('report.ready', ['summary' => 'slack400', 'customer_id' => $custA]);
pump();
$bad = row('SELECT * FROM integration_deliveries');
T::eq('dead', $bad['status'], 'Slack 400 invalid_payload is permanent (no retry storm)');
T::eq(1, mock_count('/bad'), 'sent once');
// test button
$dT = mkdest('webhook', ['url' => $base . '/testbtn', 'signing_secret' => 'abcdefghijklmnopqrst']);
mock_reset();
T::eq(0, mock_count('/testbtn'), 'nothing is sent until the Test button is pressed');
$t = integration_send_test($dT, 'tester');
T::ok($t['ok'], 'test message delivered');
$tb = json_decode(mock_log()[0]['body'], true);
T::ok(!empty($tb['test']) && strpos($tb['summary'], 'Test message') !== false, 'test message is clearly labelled');
T::eq(1, (int)row('SELECT COUNT(*) c FROM integration_deliveries WHERE is_test=1 AND destination_id=?', [$dT])['c'], 'test delivery shows in history flagged as test');

// ===========================================================================
T::group('Per-destination concurrency limit');

reset_integration_tables(); db()->exec('DELETE FROM integration_events');
$dC1 = mkdest('webhook', ['url' => $base . '/ok', 'signing_secret' => 'abcdefghijklmnopqrst'], ['concurrency_limit' => 2]);
$dC2 = mkdest('webhook', ['url' => $base . '/ok', 'signing_secret' => 'abcdefghijklmnopqrst'], ['concurrency_limit' => 5]);
mkroute($dC1); mkroute($dC2);
for ($i = 0; $i < 6; $i++) { integration_emit('report.ready', ['summary' => "c$i", 'customer_id' => $custA]); }
integration_route_pending_events();
$l1 = integration_lease_deliveries(50);
$by = array_count_values(array_map(fn($r) => (int)$r['destination_id'], $l1));
T::eq(2, $by[$dC1] ?? 0, 'destination limited to 2 concurrent deliveries');
T::eq(5, $by[$dC2] ?? 0, 'other destination uses its own limit of 5');
T::eq([], array_filter(integration_lease_deliveries(50), fn($r) => (int)$r['destination_id'] === $dC1), 'no further leases while the limit is in use');
db()->exec("UPDATE integration_deliveries SET lease_until = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE status='leased'");
T::ok(integration_sweep_leases() >= 7, 'expired leases (crashed worker) are recovered');
T::eq(7, (int)row("SELECT COUNT(*) c FROM integration_deliveries WHERE status='retry'")['c'], 'and returned to the retry queue');

// ===========================================================================
T::group('Customer scoping');

reset_integration_tables(); db()->exec('DELETE FROM integration_events');
$dA = mkdest('webhook', ['url' => $base . '/ok', 'signing_secret' => 'abcdefghijklmnopqrst'], ['customer_id' => $custA]);
$dBb = mkdest('webhook', ['url' => $base . '/ok', 'signing_secret' => 'abcdefghijklmnopqrst'], ['customer_id' => $custB]);
$dG = mkdest('webhook', ['url' => $base . '/ok', 'signing_secret' => 'abcdefghijklmnopqrst']);
mkroute($dA); mkroute($dBb); mkroute($dG);
$e = integration_emit('report.ready', ['summary' => 'scoped', 'customer_id' => $custA]);
integration_route_pending_events();
$dests = array_map(fn($r) => (int)$r['destination_id'], db()->query('SELECT destination_id FROM integration_deliveries')->fetchAll(PDO::FETCH_ASSOC));
sort($dests);
T::eq([$dA, $dG], $dests, 'a customer-bound destination never receives another customer\'s event');
$delA = (int)row('SELECT id FROM integration_deliveries WHERE destination_id=?', [$dA])['id'];
db()->exec("UPDATE integration_deliveries SET status='dead'");
db()->prepare('INSERT INTO user_customer_scopes (user_id, customer_id) VALUES (999001, ?)')->execute([$custB]);
$_SESSION['user_id'] = 999001; $_SESSION['role'] = 'technician';
$vis = array_map(fn($r) => (int)$r['id'], integration_destination_list());
T::eq([$dBb], $vis, 'scoped staff see only destinations of their customer');
T::ok(!integration_replay($delA, 'scoped')['ok'], 'scoped staff cannot replay another customer\'s delivery');
[$w, $pp] = integration_scope_sql('d.customer_id');
T::ok($w !== '1=1', 'delivery history query is scope-restricted');
$_SESSION['user_id'] = 1; $_SESSION['role'] = 'admin';
T::ok(integration_replay($delA, 'admin')['ok'], 'unrestricted admin can replay');

exit(T::summary());
