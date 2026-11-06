<?php
/**
 * Integrations: ticketing connector and inbound sync tests.
 *
 * Covers one-ticket-per-incident, ambiguous creation timeouts, reconciliation,
 * recurrence policy, inbound webhook authentication, sync-loop prevention and
 * the rule that closing an external ticket never resolves a failing incident.
 * Local mock helpdesk only; nothing leaves this machine.
 *
 *   DB_NAME=opnsense_fw_test_integrations php tests/integration_tickets_test.php
 */

putenv('OPNMGR_MASTER_KEY=' . base64_encode(random_bytes(32)));
require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/alerting.php';
require_once TEST_ROOT . '/inc/integrations/events.php';
require_once TEST_ROOT . '/inc/integrations/delivery.php';
require_once TEST_ROOT . '/inc/integrations/inbound.php';

if (strpos((string)db()->query('SELECT DATABASE()')->fetchColumn(), 'opnsense_fw_test') !== 0) { fwrite(STDERR, "refusing: not a test database\n"); exit(2); }

// --- mock helpdesk -----------------------------------------------------------
$mockDir = sys_get_temp_dir() . '/opn_tkt_mock_' . bin2hex(random_bytes(4));
mkdir($mockDir);
file_put_contents($mockDir . '/router.php', <<<'PHP'
<?php
$dir = __DIR__;
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');
$h = [];
foreach ($_SERVER as $k => $v) { if (strpos($k, 'HTTP_') === 0) { $h[strtolower(str_replace('_', '-', substr($k, 5)))] = $v; } }
file_put_contents($dir . '/log.jsonl', json_encode(['path' => $uri, 'method' => $_SERVER['REQUEST_METHOD'], 'headers' => $h, 'body' => $body]) . "\n", FILE_APPEND | LOCK_EX);
$mode = trim((string)@file_get_contents($dir . '/mode')) ?: 'normal';
$tf = $dir . '/tickets.json';
$tickets = is_file($tf) ? json_decode(file_get_contents($tf), true) : [];
header('Content-Type: application/json');
if ($uri === '/tickets' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = 'T-' . (count($tickets) + 1);
    $tickets[] = ['id' => $id, 'idem' => $h['idempotency-key'] ?? ''];
    file_put_contents($tf, json_encode($tickets));
    if ($mode === 'timeout') { sleep(4); }
    if ($mode === 'noid') { http_response_code(201); echo '{}'; exit; }
    http_response_code(201); echo json_encode(['data' => ['id' => $id, 'url' => "https://helpdesk.example/t/$id"]]); exit;
}
if (preg_match('#^/tickets/lookup/(.+)$#', $uri, $m)) {
    foreach ($tickets as $t) { if ($t['idem'] === urldecode($m[1])) { echo json_encode(['data' => ['id' => $t['id']]]); exit; } }
    http_response_code(404); echo '{}'; exit;
}
if (preg_match('#^/tickets/status/(.+)$#', $uri, $m)) {
    $f = $dir . '/status_' . $m[1];
    echo json_encode(['status' => is_file($f) ? trim(file_get_contents($f)) : 'open', 'assignee' => 'alice']); exit;
}
if (preg_match('#^/tickets/(.+)$#', $uri) && in_array($_SERVER['REQUEST_METHOD'], ['PATCH', 'PUT', 'POST'], true)) { echo '{}'; exit; }
echo 'ok';
PHP);
$port = random_int(20000, 40000);
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $mockDir . '/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $mockDir, ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv());
register_shutdown_function(function () use ($srv, $mockDir) { proc_terminate($srv); array_map('unlink', glob($mockDir . '/*')); @rmdir($mockDir); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) { break; } usleep(100000); }
$base = "http://127.0.0.1:$port";

function mock_log(): array { global $mockDir; $f = @file($mockDir . '/log.jsonl', FILE_IGNORE_NEW_LINES) ?: []; return array_map(fn($l) => json_decode($l, true), $f); }
function mock_calls(string $method, string $prefix): array { return array_values(array_filter(mock_log(), fn($r) => $r['method'] === $method && strpos($r['path'], $prefix) === 0)); }
function mock_ops(): array { return array_map(fn($r) => json_decode($r['body'], true)['operation'] ?? '?', mock_calls('PATCH', '/tickets/')); }
function mock_tickets(): int { global $mockDir; return is_file($mockDir . '/tickets.json') ? count(json_decode(file_get_contents($mockDir . '/tickets.json'), true)) : 0; }
function last_of(array $a) { return $a ? $a[array_key_last($a)] : null; }
function mock_mode(string $m): void { global $mockDir; file_put_contents($mockDir . '/mode', $m); }
function mock_reset(): void { global $mockDir; foreach (glob($mockDir . '/*') as $f) { if (basename($f) !== 'router.php') { unlink($f); } } }

function reset_tables(): void {
    foreach (['integration_delivery_attempts', 'integration_deliveries', 'integration_events', 'integration_routes', 'integration_ticket_links',
              'integration_escalations', 'integration_inbound_nonces', 'integration_inbound_log', 'integration_destinations'] as $t) { db()->exec("DELETE FROM {$t}"); }
}
function row(string $sql, array $p = []): ?array { $s = db()->prepare($sql); $s->execute($p); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
function pump(int $n = 1): void {
    for ($i = 0; $i < $n; $i++) {
        db()->exec("UPDATE integration_deliveries SET next_attempt_at = NOW() WHERE status IN ('pending','retry')");
        db()->exec('UPDATE integration_destinations SET paused_until = NULL');
        integration_run_cycle(20);
    }
}
function mkticketdest(array $over = [], array $secretOver = [], array $cfgOver = []): int {
    global $base;
    $secrets = $secretOver + ['url' => "$base/tickets", 'update_url' => "$base/tickets/{external_id}", 'inbound_secret' => 'inbound-secret-0123456789'];
    $r = integration_destination_save($over + ['name' => 'tkt-' . bin2hex(random_bytes(3)), 'type' => 'ticket_webhook', 'secrets' => $secrets,
        'config' => $cfgOver + ['external_id_path' => 'data.id', 'external_url_path' => 'data.url'], 'allow_internal' => 1, 'timeout_seconds' => 2,
        'max_attempts' => 4, 'inbound_ack_enabled' => 1, 'inbound_comment_enabled' => 1, 'inbound_assign_enabled' => 1], null, 'test');
    if (!$r['ok']) { throw new RuntimeException($r['error']); }
    $rt = integration_route_save(['name' => 'rt' . $r['id'], 'destination_id' => $r['id']], null, 'test');
    return $r['id'];
}
function link_of(int $dest, int $inc): ?array { return row('SELECT * FROM integration_ticket_links WHERE destination_id=? AND incident_id=? ORDER BY id DESC', [$dest, $inc]); }
function signed(int $dest, array $payload, array $o = []): array {
    $raw = $o['raw'] ?? json_encode($payload);
    $ts = (string)($o['ts'] ?? time());
    $secret = $o['secret'] ?? 'inbound-secret-0123456789';
    $hdr = ['timestamp' => $ts, 'signature' => integration_sign($secret, $ts, $raw), 'nonce' => $o['nonce'] ?? bin2hex(random_bytes(8))];
    return integration_inbound_handle($o['dest'] ?? $dest, $raw, $hdr, '203.0.113.9', $o['now'] ?? null);
}

db()->prepare('INSERT INTO customers (name) VALUES (?)')->execute(['__tkt_cust__']); $cust = (int)db()->lastInsertId();
db()->prepare('INSERT INTO firewalls (hostname, ip_address, hardware_id, status, customer_id) VALUES (?,?,?,"online",?)')->execute(['__tkt_fw__', '198.51.100.77', md5(random_bytes(8)), $cust]);
$fw = (int)db()->lastInsertId();
$_SESSION['user_id'] = 1; $_SESSION['username'] = 'tkt-test'; $_SESSION['role'] = 'admin';
register_shutdown_function(function () use ($cust, $fw) {
    try {
        reset_tables(); db()->exec('DELETE FROM integration_events');
        db()->prepare('DELETE e FROM alert_incident_events e JOIN alert_incidents i ON i.id=e.incident_id WHERE i.firewall_id=?')->execute([$fw]);
        db()->prepare('DELETE FROM alert_incidents WHERE firewall_id=?')->execute([$fw]);
        db()->prepare('DELETE FROM firewalls WHERE id=?')->execute([$fw]);
        db()->prepare('DELETE FROM customers WHERE id=?')->execute([$cust]);
    } catch (Throwable $e) {}
});
reset_tables(); db()->exec('DELETE FROM integration_events');
$n = 0;
function raise(string $key, array $o = []): int { global $fw; return alert_raise('gateway.down', ['firewall_id' => $fw, 'object_key' => $key, 'title' => "GW $key down"] + $o); }

// ===========================================================================
T::group('One ticket per ongoing incident');

$d = mkticketdest();
$i = raise('K1');
pump();
$l = link_of($d, $i);
T::eq(1, count(mock_calls('POST', '/tickets')), 'opening an incident creates one ticket');
T::eq('active', $l['link_state'], 'mapping is active');
T::eq('T-1', $l['external_id'], 'external id captured from the configured JSON path');
T::eq('https://helpdesk.example/t/T-1', $l['external_url'], 'external URL captured');
for ($k = 0; $k < 6; $k++) { raise('K1'); }
pump();
T::eq(1, mock_tickets(), 'six more evaluation cycles create no further tickets');
T::eq([], mock_ops(), 'and no updates (nothing changed)');
raise('K1', ['severity' => 'warning']); pump();
T::eq(['update'], mock_ops(), 'a severity change updates the existing ticket');
alert_acknowledge($i, 'looking'); pump();
T::eq(['update', 'update'], mock_ops(), 'acknowledgement updates the ticket');
T::eq('acknowledged', link_of($d, $i)['ticket_state'], 'ticket workflow state tracks the acknowledgement');
alert_resolve('gateway.down', $fw, 'K1'); pump();
T::eq('resolve', last_of(mock_ops()), 'resolution resolves the ticket (policy: resolve)');
T::eq(1, mock_tickets(), 'still exactly one ticket for the incident');
$l = link_of($d, $i);
T::ok($l['link_state'] === 'closed' && $l['active_key'] === null, 'mapping closes after resolution');
$posted = json_decode(mock_calls('POST', '/tickets')[0]['body'], true);
T::eq($l['create_idempotency_key'], mock_calls('POST', '/tickets')[0]['headers']['idempotency-key'], 'create carries the idempotency key');
T::eq($i, $posted['ticket']['incident_id'], 'ticket body references the incident');
T::ok(strpos(json_encode($posted), 'private') === false, 'ticket body has no credentials');

// ===========================================================================
T::group('Ambiguous create: timeout after the ticket was made');

reset_tables(); db()->exec('DELETE FROM integration_events'); mock_reset();
$d = mkticketdest();
mock_mode('timeout');
$i = raise('AMB');
pump();
$l = link_of($d, $i);
T::eq(1, mock_tickets(), 'the helpdesk created the ticket (response was lost)');
T::eq('needs_reconciliation', $l['link_state'], 'mapping is marked needs_reconciliation');
T::eq(1, (int)$l['outcome_unknown'], 'outcome recorded as unknown');
T::eq('needs_reconciliation', row('SELECT status FROM integration_deliveries WHERE incident_id=?', [$i])['status'], 'delivery parked, not retried');
pump(3);
T::eq(1, mock_tickets(), 'retries and restarts never create a second ticket');
T::eq(1, count(mock_calls('POST', '/tickets')), 'only one create request was ever sent');
mock_mode('normal');
alert_resolve('gateway.down', $fw, 'AMB'); pump();
T::eq([], mock_ops(), 'updates for the incident wait behind the unresolved mapping');
$rec = integration_ticket_reconcile((int)$l['id'], 'link', 'T-1', 'op');
T::ok($rec['ok'], 'operator links the existing ticket');
pump();
$ops = mock_calls('PATCH', '/tickets/');
T::eq('/tickets/T-1', $ops[0]['path'] ?? '', 'queued update now goes to the reconciled ticket');
T::eq(1, mock_tickets(), 'still one ticket');
// not-created and abandon paths
reset_tables(); db()->exec('DELETE FROM integration_events'); mock_reset();
$d = mkticketdest(); mock_mode('timeout');
$i = raise('AMB2'); pump(); $l = link_of($d, $i);
mock_mode('normal');
T::ok(integration_ticket_reconcile((int)$l['id'], 'not_created', null, 'op')['ok'], 'operator confirms nothing was created');
$tc = mock_tickets();
pump();
T::eq($tc + 1, mock_tickets(), 'the create is retried only after that confirmation');
T::eq(link_of($d, $i)['create_idempotency_key'], last_of(mock_calls('POST', '/tickets'))['headers']['idempotency-key'], 'retry reuses the same idempotency key');
reset_tables(); db()->exec('DELETE FROM integration_events'); mock_reset();
$d = mkticketdest(); mock_mode('timeout'); $i = raise('AMB3'); pump(); $l = link_of($d, $i); mock_mode('normal');
T::ok(integration_ticket_reconcile((int)$l['id'], 'abandon', null, 'op')['ok'], 'operator abandons the mapping');
T::eq('cancelled', row('SELECT status FROM integration_deliveries WHERE incident_id=? AND event_type="incident.opened"', [$i])['status'], 'parked delivery cancelled');
T::ok(!integration_ticket_reconcile((int)$l['id'], 'abandon', null, 'op')['ok'], 'a settled mapping cannot be reconciled again');

T::group('Ambiguous create with a lookup URL');
reset_tables(); db()->exec('DELETE FROM integration_events'); mock_reset();
$d = mkticketdest([], ['lookup_url' => "$base/tickets/lookup/{idempotency_key}"]);
mock_mode('timeout'); $i = raise('LKP'); pump();
T::eq('retry', row('SELECT status FROM integration_deliveries WHERE incident_id=?', [$i])['status'], 'with lookup support the delivery retries');
mock_mode('normal');
pump();
$l = link_of($d, $i);
T::eq('active', $l['link_state'], 'retry looked the ticket up by idempotency key and adopted it');
T::eq('T-1', $l['external_id'], 'adopted the existing ticket id');
T::eq(1, count(mock_calls('POST', '/tickets')), 'no second create request');
T::eq(1, mock_tickets(), 'one ticket');
T::eq(1, count(mock_calls('GET', '/tickets/lookup/')), 'a lookup was made');

T::group('Create that succeeds but cannot be parsed');
reset_tables(); db()->exec('DELETE FROM integration_events'); mock_reset();
$d = mkticketdest(); mock_mode('noid'); $i = raise('NOID'); pump(); mock_mode('normal');
T::eq('needs_reconciliation', link_of($d, $i)['link_state'], 'missing id at the JSON path parks the mapping');
pump(2); T::eq(1, mock_tickets(), 'and is not retried');

T::group('Provably-not-created failure is safely retried');
reset_tables(); db()->exec('DELETE FROM integration_events'); mock_reset();
$free = stream_socket_server('tcp://127.0.0.1:0', $en, $es); $fp = (int)substr(strrchr(stream_socket_get_name($free, false), ':'), 1); fclose($free);
$d = mkticketdest([], ['url' => "http://127.0.0.1:$fp/tickets"]);
$i = raise('REF'); pump();
$l = link_of($d, $i);
T::eq('retry', row('SELECT status FROM integration_deliveries WHERE incident_id=?', [$i])['status'], 'connection refused -> retry');
T::eq(0, (int)$l['outcome_unknown'], 'outcome is not unknown when the request never connected');
$dd = integration_destination_load($d);
$s = $dd['secrets']; $s['url'] = "$base/tickets";
db()->prepare('UPDATE integration_destinations SET secrets_enc=? WHERE id=?')->execute([opnmgr_encrypt(json_encode($s)), $d]);
pump();
T::eq(1, mock_tickets(), 'creates once the helpdesk is reachable');

// ===========================================================================
T::group('Recurrence policy: reopen vs new ticket');

foreach (['reopen', 'new_ticket'] as $pol) {
    reset_tables(); db()->exec('DELETE FROM integration_events'); mock_reset(); mock_mode('normal');
    $d = mkticketdest(['on_recurrence' => $pol]);
    $i1 = raise('REC'); pump(); alert_resolve('gateway.down', $fw, 'REC'); pump();
    $i2 = raise('REC'); pump();
    $ev = row("SELECT event_type FROM integration_events WHERE incident_id=? ORDER BY id LIMIT 1", [$i2]);
    T::eq('incident.reopened', $ev['event_type'], "[$pol] a recurrence is announced as reopened");
    if ($pol === 'reopen') {
        T::eq(1, mock_tickets(), '[reopen] no new ticket for the recurrence');
        T::ok(in_array('reopen', mock_ops(), true), '[reopen] the earlier ticket was reopened');
        T::eq('T-1', link_of($d, $i2)['external_id'], '[reopen] new incident maps onto the same external ticket');
    } else {
        T::eq(2, mock_tickets(), '[new_ticket] the recurrence gets a fresh ticket');
        T::eq('T-2', link_of($d, $i2)['external_id'], '[new_ticket] mapped to the new ticket');
    }
    alert_resolve('gateway.down', $fw, 'REC'); pump();
}

// ===========================================================================
T::group('Inbound webhook: signature, freshness, replay, authorization');

reset_tables(); db()->exec('DELETE FROM integration_events'); mock_reset();
$d = mkticketdest();
$slackId = null;
$i = raise('IN1'); pump();
$tk = link_of($d, $i)['external_id'];
$ack = ['type' => 'ticket.acknowledged', 'external_id' => $tk];
T::eq(401, signed($d, $ack, ['secret' => 'wrong-secret-wrong-secret'])['code'], 'wrong signature rejected');
T::eq(401, signed($d, $ack, ['ts' => time() - 1000])['code'], 'stale timestamp rejected');
T::eq(401, signed($d, $ack, ['ts' => time() + 1000])['code'], 'future timestamp rejected');
$raw = json_encode($ack);
$tsx = (string)time();
$tampered = integration_inbound_handle($d, $raw . ' ', ['timestamp' => $tsx, 'signature' => integration_sign('inbound-secret-0123456789', $tsx, $raw), 'nonce' => 'n-t'], '1.2.3.4');
T::eq(401, $tampered['code'], 'a modified body fails verification');
T::eq(401, integration_inbound_handle($d, $raw, ['timestamp' => $tsx, 'nonce' => 'x'], '1.2.3.4')['code'], 'missing signature rejected');
T::eq(401, signed($d, $ack, ['dest' => 999999])['code'], 'unknown destination gets the same 401');
T::eq('open', row('SELECT status FROM alert_incidents WHERE id=?', [$i])['status'], 'rejected requests changed nothing');
$nonce = 'fixed-nonce-1';
$ok1 = signed($d, $ack, ['nonce' => $nonce]);
T::eq(200, $ok1['code'], 'valid signed request accepted');
T::eq('acknowledged', row('SELECT status FROM alert_incidents WHERE id=?', [$i])['status'], 'external acknowledgement acknowledges the incident');
T::eq('ext:' . integration_destination_load($d)['name'], row('SELECT acknowledged_by FROM alert_incidents WHERE id=?', [$i])['acknowledged_by'], 'acknowledged_by names the external system');
T::eq(401, signed($d, $ack, ['nonce' => $nonce])['code'], 'replaying the same request (nonce) is rejected');
$rawNoNonce = json_encode(['type' => 'ticket.updated', 'external_id' => $tk, 'event_id' => 'evt-77']);
$ts2 = (string)time();
$h2 = ['timestamp' => $ts2, 'signature' => integration_sign('inbound-secret-0123456789', $ts2, $rawNoNonce)];
T::eq(200, integration_inbound_handle($d, $rawNoNonce, $h2)['code'], 'event_id in the body serves as nonce');
T::eq(401, integration_inbound_handle($d, $rawNoNonce, $h2)['code'], 'and replays are rejected by it');
db()->prepare("UPDATE integration_destinations SET secrets_enc=? WHERE id=?")->execute([opnmgr_encrypt(json_encode(['url' => "$base/tickets", 'update_url' => "$base/tickets/{external_id}"])), $d]);
T::eq(401, signed($d, $ack)['code'], 'a destination with no inbound secret accepts nothing');
db()->prepare("UPDATE integration_destinations SET secrets_enc=? WHERE id=?")->execute([opnmgr_encrypt(json_encode(['url' => "$base/tickets", 'update_url' => "$base/tickets/{external_id}", 'inbound_secret' => 'inbound-secret-0123456789'])), $d]);
db()->prepare("UPDATE integration_destinations SET enabled=0 WHERE id=?")->execute([$d]);
T::eq(401, signed($d, $ack)['code'], 'a disabled destination accepts nothing');
db()->prepare("UPDATE integration_destinations SET enabled=1 WHERE id=?")->execute([$d]);
T::eq(404, signed($d, ['type' => 'ticket.comment', 'external_id' => 'T-nope', 'comment' => 'x'])['code'], 'unknown ticket is a 404, not an action');
T::eq(400, signed($d, ['type' => 'ticket.bogus', 'external_id' => $tk])['code'], 'unknown event type is a 400');
T::eq(413, integration_inbound_handle($d, str_repeat('a', 70000), [])['code'], 'oversized body rejected');
$logged = (int)row("SELECT COUNT(*) c FROM integration_inbound_log WHERE result='rejected'")['c'];
T::ok($logged >= 8, 'rejections are logged');

T::group('Inbound comments, ownership, and per-destination switches');
$cm = signed($d, ['type' => 'ticket.comment', 'external_id' => $tk, 'comment' => "Please check cable password=letmein123 thanks", 'author' => 'alice']);
T::eq(200, $cm['code'], 'comment accepted');
$ev = row("SELECT * FROM alert_incident_events WHERE incident_id=? AND event='updated' ORDER BY id DESC LIMIT 1", [$i]);
T::ok(strpos($ev['detail'], 'Please check cable') !== false && strpos($ev['detail'], 'letmein123') === false, 'comment is added as a note with secrets redacted');
T::ok((bool)row("SELECT 1 FROM audit_log WHERE action='integration.inbound_comment' AND object_id=?", [(string)$i]), 'and audited');
$as = signed($d, ['type' => 'ticket.assigned', 'external_id' => $tk, 'assignee' => 'bob']);
T::eq('bob', row('SELECT owner FROM alert_incidents WHERE id=?', [$i])['owner'], 'assignment sets local ownership when enabled');
db()->prepare('UPDATE integration_destinations SET inbound_assign_enabled=0, inbound_comment_enabled=0 WHERE id=?')->execute([$d]);
$r = signed($d, ['type' => 'ticket.assigned', 'external_id' => $tk, 'assignee' => 'mallory']);
T::eq('ignored', $r['body']['result'], 'assignment ignored when not enabled');
T::eq('bob', row('SELECT owner FROM alert_incidents WHERE id=?', [$i])['owner'], 'owner unchanged');
T::eq('ignored', signed($d, ['type' => 'ticket.comment', 'external_id' => $tk, 'comment' => 'x'])['body']['result'], 'comment ignored when not enabled');
alert_resolve('gateway.down', $fw, 'IN1'); pump();

// ===========================================================================
T::group('Sync-loop prevention');

reset_tables(); db()->exec('DELETE FROM integration_events'); mock_reset();
$d = mkticketdest();
$other = integration_destination_save(['name' => 'chat-' . bin2hex(random_bytes(2)), 'type' => 'webhook', 'secrets' => ['url' => "$base/other", 'signing_secret' => 'abcdefghijklmnopqrst'], 'allow_internal' => 1, 'timeout_seconds' => 2], null, 't')['id'];
integration_route_save(['name' => 'other', 'destination_id' => $other], null, 't');
$i = raise('LOOP'); pump();
$tk = link_of($d, $i)['external_id'];
T::eq(1, count(mock_calls('POST', '/other')), 'chat destination told about the incident');
$opsBefore = count(mock_ops());
$r = signed($d, ['type' => 'ticket.acknowledged', 'external_id' => $tk]);
T::eq(200, $r['code'], 'inbound ack applied');
$evrow = row("SELECT * FROM integration_events WHERE incident_id=? AND event_type='incident.acknowledged'", [$i]);
T::eq($d, (int)$evrow['origin_destination_id'], 'the resulting event carries the inbound origin');
pump();
T::eq($opsBefore, count(mock_ops()), 'the acknowledgement is NOT sent back to the ticket system that sent it');
$sup = row("SELECT status, suppress_reason FROM integration_deliveries WHERE event_id=? AND destination_id=?", [$evrow['event_id'], $d]);
T::eq('suppressed', $sup['status'], 'the echo is recorded as suppressed');
T::ok(strpos($sup['suppress_reason'], 'origin') !== false, 'with an origin reason');
T::eq(2, count(mock_calls('POST', '/other')), 'other destinations still hear about the acknowledgement');
// A local (UI) ack, by contrast, does reach the ticket system.
$i2 = raise('LOOP2'); pump(); $before = count(mock_ops());
alert_acknowledge($i2, 'ui'); pump();
T::eq($before + 1, count(mock_ops()), 'a local acknowledgement is synchronised outward');
alert_resolve('gateway.down', $fw, 'LOOP'); alert_resolve('gateway.down', $fw, 'LOOP2'); pump();

// ===========================================================================
T::group('Closing the ticket does not heal the incident');

reset_tables(); db()->exec('DELETE FROM integration_events'); mock_reset();
$d = mkticketdest();
$i = raise('CLS'); pump(); $tk = link_of($d, $i)['external_id'];
$r = signed($d, ['type' => 'ticket.closed', 'external_id' => $tk]);
T::eq(200, $r['code'], 'external close accepted');
$inc = row('SELECT status, resolved_at FROM alert_incidents WHERE id=?', [$i]);
T::eq('open', $inc['status'], 'the still-failing incident stays open');
T::eq(null, $inc['resolved_at'], 'and unresolved');
$l = link_of($d, $i);
T::eq('closed', $l['ticket_state'], 'ticket workflow state recorded as closed');
T::eq(1, (int)$l['closed_while_incident_active'], 'flagged: ticket closed while condition persists');
T::eq('open', $l['incident_state'], 'observed incident state kept separately');
$ops0 = count(mock_ops());
raise('CLS', ['severity' => 'warning']); pump();
T::eq($ops0, count(mock_ops()), 'policy "note": closed ticket is not poked by later updates');
db()->prepare("UPDATE integration_destinations SET on_external_close='reopen_ticket' WHERE id=?")->execute([$d]);
raise('CLS', ['severity' => 'critical']); pump();
T::ok(in_array('reopen', mock_ops(), true), 'policy "reopen_ticket": a still-failing condition reopens the ticket');
$i3 = raise('CLS'); // same incident
T::eq($i, $i3, 'evaluator still sees one incident');

T::group('Outbound polling (no inbound exposure required)');
reset_tables(); db()->exec('DELETE FROM integration_events'); mock_reset();
$d = mkticketdest([], ['poll_url' => "$base/tickets/status/{external_id}"], ['poll_status_path' => 'status', 'poll_closed_values' => 'closed,resolved', 'poll_ack_values' => 'in_progress', 'poll_assignee_path' => 'assignee']);
$i = raise('POLL'); pump(); $tk = link_of($d, $i)['external_id'];
file_put_contents($mockDir . "/status_$tk", 'in_progress');
$dest = integration_destination_load($d);
T::eq(1, (int)integration_poll_destination($dest) >= 1 ? 1 : 0, 'poll applies a status change');
T::eq('acknowledged', row('SELECT status FROM alert_incidents WHERE id=?', [$i])['status'], 'poll: in-progress status acknowledges the incident');
T::eq('alice', row('SELECT owner FROM alert_incidents WHERE id=?', [$i])['owner'], 'poll: assignee mirrored when enabled');
file_put_contents($mockDir . "/status_$tk", 'closed');
integration_poll_destination(integration_destination_load($d));
T::eq('closed', link_of($d, $i)['ticket_state'], 'poll: closed ticket recorded');
T::ok(in_array(row('SELECT status FROM alert_incidents WHERE id=?', [$i])['status'], ['open', 'acknowledged'], true), 'poll: incident not resolved by the closed ticket');
T::ok(count(mock_calls('GET', '/tickets/status/')) >= 2, 'polling is outbound GETs only');

T::group('Ticket destination validation and test button');
T::ok(OpnmgrConnectorTicketWebhook::validate([], ['url' => 'https://h.example/t'], false) !== [], 'ticket webhook needs an external id path');
T::ok(OpnmgrConnectorTicketWebhook::validate(['external_id_path' => 'id'], ['url' => 'https://{host}.example/t'], false) !== [], 'placeholders are not allowed in the host');
reset_tables(); db()->exec('DELETE FROM integration_events'); mock_reset();
$d = mkticketdest();
$t = integration_send_test($d, 'tester');
$posted = json_decode(mock_calls('POST', '/tickets')[0]['body'], true);
T::ok($t['ok'] && !empty($posted['test']) && strpos($posted['ticket']['title'], '[TEST]') !== false, 'test ticket call is labelled as a test');
T::eq(0, (int)row('SELECT COUNT(*) c FROM integration_ticket_links')['c'], 'a test creates no incident mapping');

// endpoint: signed request through the real inbound script
T::group('Inbound endpoint (api/integration_inbound.php)');
$i = raise('EP'); pump(); $tk = link_of($d, $i)['external_id'];
$raw = json_encode(['type' => 'ticket.acknowledged', 'external_id' => $tk]);
$ts = (string)time();
$hdr = ['X-OPNManager-Timestamp' => $ts, 'X-OPNManager-Nonce' => bin2hex(random_bytes(6)), 'X-OPNManager-Signature' => integration_sign('inbound-secret-0123456789', $ts, $raw)];
$res = call_endpoint('api/integration_inbound.php', ['method' => 'POST', 'get' => ['destination' => $d], 'body' => $raw, 'headers' => $hdr]);
T::eq(200, $res['status'], 'endpoint accepts a correctly signed request (' . substr($res['body'], 0, 80) . ')');
$res2 = call_endpoint('api/integration_inbound.php', ['method' => 'POST', 'get' => ['destination' => $d], 'body' => $raw, 'headers' => $hdr]);
T::eq(401, $res2['status'], 'endpoint rejects the replay');
$res3 = call_endpoint('api/integration_inbound.php', ['method' => 'GET', 'get' => ['destination' => $d]]);
T::eq(405, $res3['status'], 'endpoint is POST only');
$res4 = call_endpoint('api/integration_inbound.php', ['method' => 'POST', 'get' => ['destination' => $d], 'body' => $raw]);
T::eq(401, $res4['status'], 'endpoint without signature headers is unauthorized');

exit(T::summary());
