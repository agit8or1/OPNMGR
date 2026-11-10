<?php
/**
 * Customer-side checks in update rings (3.81.0).
 *
 * Fixture-driven: firewalls, campaigns and command completions are database
 * rows; probe results are posted through api/customer_probe.php where the
 * scenario allows and inserted with a past timestamp where it needs time to
 * have passed. No firewall is contacted. The real probe is exercised against
 * a real local manager in tests/customer_probe_e2e_test.php.
 *
 * Scenarios: healthy rollout, offline probe, a firewall that checks in fine
 * while a client path fails, and a CARP pair. Plus: off by default, manual
 * progression with an audited override, and tenant scoping.
 *
 *   DB_NAME=opnsense_fw_test php tests/customer_checks_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/permissions.php';
require_once TEST_ROOT . '/inc/fleet_updates.php';

$made = ['fw' => [], 'cust' => [], 'probe' => [], 'campaign' => [], 'user' => []];
$savedSettings = db()->query("SELECT `name`, `value` FROM settings WHERE `name` IN
    ('update_ring_soak_hours','customer_check_stale_minutes','customer_check_settle_minutes','update_ha_settle_minutes')")
    ->fetchAll(PDO::FETCH_KEY_PAIR);

register_shutdown_function(function () use (&$made, $savedSettings) {
    foreach ($made['probe'] as $p) { cc_delete_probe($p); }
    foreach ($made['campaign'] as $c) {
        db()->prepare('DELETE FROM update_campaign_targets WHERE campaign_id = ?')->execute([$c]);
        db()->prepare('DELETE FROM update_campaigns WHERE id = ?')->execute([$c]);
    }
    foreach ($made['fw'] as $id) {
        foreach (['firewall_commands', 'audit_log'] as $t) {
            try { db()->prepare("DELETE FROM {$t} WHERE firewall_id = ?")->execute([$id]); } catch (Throwable $e) {}
        }
        db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$id]);
    }
    foreach ($made['user'] as $id) {
        db()->prepare('DELETE FROM user_customer_scopes WHERE user_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
    db()->exec("DELETE FROM customer_probes WHERE name LIKE '__cc page%'");
    foreach ($made['cust'] as $id) { db()->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]); }
    db()->exec("DELETE FROM settings WHERE `name` IN ('update_ring_soak_hours','customer_check_stale_minutes','customer_check_settle_minutes','update_ha_settle_minutes')");
    $ins = db()->prepare('INSERT INTO settings (`name`,`value`) VALUES (?,?)');
    foreach ($savedSettings as $k => $v) { $ins->execute([$k, $v]); }
});

T::eq('opnsense_fw_test', substr((string)db()->query('SELECT DATABASE()')->fetchColumn(), 0, 16), 'running against a test database');

foreach (['update_ring_soak_hours' => '0', 'customer_check_stale_minutes' => '10',
          'customer_check_settle_minutes' => '3', 'update_ha_settle_minutes' => '10'] as $k => $v) {
    db()->prepare('INSERT INTO settings (`name`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute([$k, $v]);
}

$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'cc-test';
$_SESSION['role'] = 'admin';

function cust(string $name): int {
    global $made;
    db()->prepare('INSERT INTO customers (name) VALUES (?)')->execute([$name]);
    return $made['cust'][] = (int)db()->lastInsertId();
}
function fw(string $name, int $customer, array $extra = []): int {
    global $made;
    $cols = array_merge([
        'hostname' => $name, 'ip_address' => '198.51.100.70', 'hardware_id' => md5($name . random_bytes(6)),
        'status' => 'online', 'last_checkin' => date('Y-m-d H:i:s'), 'customer_id' => $customer, 'updates_available' => 1,
        'platform_family' => 'opnsense', 'platform_os_version' => '26.7.8',
        'platform_detection_confidence' => 'high', 'platform_verified_at' => date('Y-m-d H:i:s'),
    ], $extra);
    db()->prepare('INSERT INTO firewalls (' . implode(',', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($cols));
    return $made['fw'][] = (int)db()->lastInsertId();
}
/** A probe with the three paths, returns [probeId, token, [dns, vpn, inet] check ids]. */
function probe_with_paths(int $customer, int $fw, string $name): array {
    global $made;
    $p = cc_create_probe($customer, $fw, $name);
    T::ok($p['ok'], "probe {$name} created");
    $made['probe'][] = $p['id'];
    $ids = [];
    foreach ([['dns', 'fs.corp.lan', 'Internal DNS name'], ['vpn_service', '10.20.0.5:445', 'File server over WireGuard'],
              ['internet_service', 'https://login.microsoftonline.com/', 'Microsoft 365']] as [$type, $target, $label]) {
        $ids[] = cc_add_check($p['id'], ['check_type' => $type, 'target' => $target, 'label' => $label])['id'];
    }
    return [$p['id'], $p['token'], $ids];
}
/** A result as if received $ago seconds ago. */
function result(int $probe, int $check, bool $ok, int $ago, string $detail = ''): void {
    db()->prepare('INSERT INTO customer_probe_results (probe_id, check_id, ok, latency_ms, detail, observed_at) VALUES (?,?,?,?,?, NOW() - INTERVAL ? SECOND)')
        ->execute([$probe, $check, $ok ? 1 : 0, 12, $detail ?: null, $ago]);
}
function all_results(int $probe, array $checks, bool $ok, int $ago): void {
    foreach ($checks as $c) { result($probe, $c, $ok, $ago); }
}
/** The firewall reports its update command finished (now), then reconcile. */
function finish_update(int $campaign, int $fw): void {
    $cmd = db()->prepare('SELECT command_id FROM update_campaign_targets WHERE campaign_id = ? AND firewall_id = ?');
    $cmd->execute([$campaign, $fw]);
    db()->prepare('UPDATE firewall_commands SET status = "completed", result = "ok" WHERE id = ?')->execute([(int)$cmd->fetchColumn()]);
    campaign_reconcile($campaign);
    // What the agent reports at its next check-in after a successful update.
    db()->prepare('UPDATE firewalls SET updates_available = 0, status = "online", last_checkin = NOW() WHERE id = ?')->execute([$fw]);
}
/** Move every recorded time back, as if $s seconds had passed. Firewalls keep checking in. */
function time_passes(int $s): void {
    global $made;
    db()->prepare('UPDATE customer_probe_results SET observed_at = observed_at - INTERVAL ? SECOND')->execute([$s]);
    db()->prepare('UPDATE update_campaign_targets SET completed_at = completed_at - INTERVAL ? SECOND,
                   dispatched_at = dispatched_at - INTERVAL ? SECOND WHERE campaign_id IN (' . implode(',', $made['campaign']) . ')')->execute([$s, $s]);
    db()->prepare('UPDATE firewalls SET last_update_attempt_at = last_update_attempt_at - INTERVAL ? SECOND, last_checkin = NOW()
                   WHERE id IN (' . implode(',', $made['fw']) . ')')->execute([$s]);
}
function target(int $campaign, int $fw): array {
    $s = db()->prepare('SELECT * FROM update_campaign_targets WHERE campaign_id = ? AND firewall_id = ?');
    $s->execute([$campaign, $fw]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
}
function campaign(string $name, array $fws, bool $customerChecks): int {
    global $made;
    $c = campaign_create(['name' => '__cc ' . $name, 'operation' => 'install', 'customer_checks' => $customerChecks ? 1 : 0], $fws);
    T::ok($c['ok'], "campaign {$name} created");
    return $made['campaign'][] = $c['id'];
}

// ===========================================================================
T::group('Narrowly defined checks');

$ok = fn(array $in) => cc_validate_check($in)['ok'];
T::ok($ok(['check_type' => 'dns', 'target' => 'fs.corp.lan', 'resolver' => '10.0.0.53', 'expect' => '10.20.0.0/24']), 'DNS: a name, an internal resolver, an expected CIDR');
T::ok(!$ok(['check_type' => 'dns', 'target' => 'fs.corp.lan', 'resolver' => '8.8.8.8']), 'DNS: a public resolver is not "internal DNS"');
T::ok(!$ok(['check_type' => 'dns', 'target' => 'a;reboot']), 'DNS: not a hostname');
T::ok($ok(['check_type' => 'vpn_service', 'target' => '10.20.0.5:445']) && $ok(['check_type' => 'vpn_service', 'target' => '[fd00::5]:443'])
      && $ok(['check_type' => 'vpn_service', 'target' => '100.64.1.2:22']), 'VPN: RFC1918, ULA and CGNAT addresses');
T::ok(!$ok(['check_type' => 'vpn_service', 'target' => '8.8.8.8:53']), 'VPN: a public address is not across the VPN');
T::ok(!$ok(['check_type' => 'vpn_service', 'target' => '127.0.0.1:22']), 'VPN: loopback is refused');
T::ok(!$ok(['check_type' => 'vpn_service', 'target' => '10.20.0.5']), 'VPN: a port is required');
T::ok($ok(['check_type' => 'internet_service', 'target' => 'https://login.microsoftonline.com/', 'expect' => '200']), 'Internet: a public https URL');
T::ok(!$ok(['check_type' => 'internet_service', 'target' => 'http://example.com/']), 'Internet: plain http is refused');
T::ok(!$ok(['check_type' => 'internet_service', 'target' => 'https://10.0.0.1/']), 'Internet: a private address is refused');
T::ok(!$ok(['check_type' => 'internet_service', 'target' => 'https://user:pw@example.com/']), 'Internet: credentials in the URL are refused');
T::ok(!$ok(['check_type' => 'shell', 'target' => 'id']), 'anything else is refused');

// ===========================================================================
T::group('Tenant scoping');

$acme  = cust('__cc Acme__');
$other = cust('__cc Other__');
$acmeFw  = fw('__cc_acme_fw__', $acme, ['update_ring' => 'canary']);
$otherFw = fw('__cc_other_fw__', $other);
$cross = cc_create_probe($acme, $otherFw, 'cross');
T::ok(!$cross['ok'] && str_contains($cross['error'], 'another customer'), 'a probe cannot cover another customer\'s firewall');

[$acmeProbe, $acmeToken, $acmeChecks] = probe_with_paths($acme, $acmeFw, 'Acme office PC');
[$otherProbe, $otherToken, $otherChecks] = probe_with_paths($other, $otherFw, 'Other office PC');
T::ok(str_starts_with($acmeToken, 'opp_'), 'a token is issued once');
T::eq(0, (int)db()->query("SELECT COUNT(*) FROM customer_probes WHERE token_hash = '" . $acmeToken . "'")->fetchColumn(), 'and only its hash is stored');

$r = call_endpoint('api/customer_probe.php', ['method' => 'GET', 'headers' => ['Authorization' => 'Bearer ' . $acmeToken]]);
T::eq(200, $r['status'], 'the probe fetches its checks');
T::eq($acmeChecks, array_column($r['json']['checks'] ?? [], 'id'), 'exactly its own three checks');
T::eq(['id', 'type', 'target', 'resolver', 'expect', 'timeout_seconds'], array_keys($r['json']['checks'][0] ?? []), 'and only what it needs to run them');

$r = call_endpoint('api/customer_probe.php', ['headers' => ['Authorization' => 'Bearer ' . $acmeToken],
    'body' => ['probe_version' => '1.0.0', 'results' => [
        ['check_id' => $acmeChecks[0], 'ok' => true, 'latency_ms' => 3, 'detail' => 'fs.corp.lan -> 10.20.0.5'],
        ['check_id' => $otherChecks[0], 'ok' => false, 'detail' => 'forged'],
    ]]]);
T::ok($r['status'] === 200 && $r['json']['accepted'] === 1 && $r['json']['rejected'] === 1, 'a result for another tenant\'s check is dropped');
T::eq(0, (int)db()->query("SELECT COUNT(*) FROM customer_probe_results WHERE check_id = {$otherChecks[0]}")->fetchColumn(), 'and nothing is stored for it');
$r = call_endpoint('api/customer_probe.php', ['headers' => ['Authorization' => 'Bearer ' . $acmeToken], 'body' => ['results' => []]]);
T::eq(429, $r['status'], 'reporting in a tight loop is refused');
$r = call_endpoint('api/customer_probe.php', ['method' => 'GET', 'headers' => ['Authorization' => 'Bearer opp_' . str_repeat('0', 48)]]);
T::eq(401, $r['status'], 'an unknown token is refused');
$rot = cc_rotate_token($otherProbe);
$r = call_endpoint('api/customer_probe.php', ['method' => 'GET', 'headers' => ['Authorization' => 'Bearer ' . $otherToken]]);
T::eq(401, $r['status'], 'a rotated token stops working');
db()->exec('DELETE FROM customer_probe_results');

// ===========================================================================
T::group('Off by default: existing rollouts are unchanged');

$plain = campaign('plain', [$acmeFw], false);
T::eq(0, (int)db()->query("SELECT customer_checks FROM update_campaigns WHERE id = {$plain}")->fetchColumn(), 'campaigns default to customer checks off');
T::ok(campaign_start_ring($plain, 'canary')['ok'], 'start canary');
// The probe has never reported: with checks off that must not matter.
$d = campaign_dispatch($plain);
T::eq(1, $d['dispatched'], 'dispatch ignores probes when the campaign does not use them');
T::eq(null, target($plain, $acmeFw)['customer_baseline'], 'and records no baseline');
finish_update($plain, $acmeFw);
time_passes(600);
all_results($acmeProbe, $acmeChecks, false, 30);
T::ok(campaign_ring_is_clean($plain, 'canary')['clean'], 'a failing probe does not make the ring unclean');
$gateCheck = campaign_start_ring($plain, 'production');
T::ok(empty($gateCheck['held']) && !str_contains((string)($gateCheck['error'] ?? ''), 'customer'),
      'and promotion is not gated by customer checks (' . ($gateCheck['error'] ?? 'ok') . ')');
db()->exec('DELETE FROM customer_probe_results');
db()->prepare('UPDATE firewalls SET updates_available = 1 WHERE id = ?')->execute([$acmeFw]);

// ===========================================================================
T::group('Healthy rollout');

$pilotFw = fw('__cc_acme_pilot__', $acme, ['update_ring' => 'pilot']);
$healthy = campaign('healthy', [$acmeFw, $pilotFw], true);
T::ok(campaign_start_ring($healthy, 'canary')['ok'], 'start canary');
all_results($acmeProbe, $acmeChecks, true, 60);
$d = campaign_dispatch($healthy);
T::eq(1, $d['dispatched'], 'with fresh healthy results the canary is dispatched');
$base = json_decode((string)target($healthy, $acmeFw)['customer_baseline'], true);
T::eq(['pass', 'pass', 'pass'], array_column($base ?: [], 'state'), 'the pre-update baseline is recorded for each path');

finish_update($healthy, $acmeFw);
$gate = cc_ring_gate($healthy, 'canary');
T::eq('waiting', $gate['state'], 'right after the update: waiting (settling)');
T::ok(str_contains(implode(' ', $gate['reasons']), 'settling'), 'and says it is settling');
time_passes(300);
$gate = cc_ring_gate($healthy, 'canary');
T::eq('waiting', $gate['state'], 'settled, no post-update result yet: still waiting (the baseline does not count)');
T::ok(!campaign_start_ring($healthy, 'pilot')['ok'], 'and pilot cannot start yet');
all_results($acmeProbe, $acmeChecks, true, 20);
$gate = cc_ring_gate($healthy, 'canary');
T::eq('pass', $gate['state'], 'healthy results after the update: pass');
T::ok(campaign_ring_is_clean($healthy, 'canary')['clean'], 'the ring is clean');
T::ok(campaign_start_ring($healthy, 'pilot')['ok'], 'and pilot starts');

// ===========================================================================
T::group('Offline probe');

db()->exec('DELETE FROM customer_probe_results');
$offFw = fw('__cc_acme_off__', $acme, ['update_ring' => 'canary']);
[$offProbe, , $offChecks] = probe_with_paths($acme, $offFw, 'Back office PC');
$offline = campaign('offline', [$offFw, $pilotFw], true);
campaign_start_ring($offline, 'canary');
all_results($offProbe, $offChecks, true, 3600);
$d = campaign_dispatch($offline);
T::eq(1, $d['held'], 'a probe silent for an hour: the target is held before update');
T::ok(str_contains((string)target($offline, $offFw)['hold_reason'], 'No fresh pre-update customer check'), 'with the reason');

all_results($offProbe, $offChecks, true, 30);
T::eq(1, campaign_dispatch($offline)['dispatched'], 'once the probe reports, it is dispatched');
finish_update($offline, $offFw);
time_passes(240);
all_results($offProbe, $offChecks, true, 0);           // reports healthy once after the update...
time_passes(3600);                                     // ...then goes silent for an hour
$gate = cc_ring_gate($offline, 'canary');
T::eq('stale', $gate['state'], 'a probe that stops reporting after the update: stale');
T::ok(str_contains(implode(' ', $gate['reasons']), 'no result for'), 'the reason says it has not reported');
T::ok(!campaign_ring_is_clean($offline, 'canary')['clean'], 'the ring is not clean');
$r = campaign_start_ring($offline, 'pilot');
T::ok(!$r['ok'] && !empty($r['held']) && str_contains($r['error'], 'Held by customer checks'), 'promotion is held');
$r = campaign_start_ring($offline, 'pilot', ['override_reason' => 'probe PC replaced; verified by phone with the customer']);
T::ok($r['ok'], 'manual progression is preserved: an operator can start it with a reason');
T::eq(1, (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'update.campaign.customer_check_override' AND object_id = '{$offline}'")->fetchColumn(),
      'and the override is audited');

// ===========================================================================
T::group('Firewall checks in, client path fails');

db()->exec('DELETE FROM customer_probe_results');
$wgFw = fw('__cc_acme_wg__', $acme, ['update_ring' => 'canary']);
[$wgProbe, , $wgChecks] = probe_with_paths($acme, $wgFw, 'Branch PC');
$wg = campaign('wireguard', [$wgFw, $pilotFw], true);
campaign_start_ring($wg, 'canary');
all_results($wgProbe, $wgChecks, true, 60);
campaign_dispatch($wg);
finish_update($wg, $wgFw);
time_passes(600);
result($wgProbe, $wgChecks[0], true, 20);
result($wgProbe, $wgChecks[1], false, 20, '10.20.0.5:445 timed out after 5s');
result($wgProbe, $wgChecks[2], true, 20);

$fwRow = db()->query("SELECT status, TIMESTAMPDIFF(SECOND, last_checkin, NOW()) s FROM firewalls WHERE id = {$wgFw}")->fetch(PDO::FETCH_ASSOC);
T::ok($fwRow['status'] === 'online' && (int)$fwRow['s'] < 60, 'the firewall is online and checking in');
T::eq('succeeded', target($wg, $wgFw)['status'], 'and its update succeeded');
$gate = cc_ring_gate($wg, 'canary');
T::eq('fail', $gate['state'], 'but the customer gate fails');
$failing = array_values(array_filter($gate['targets'][$wgFw]['checks'], fn($c) => $c['state'] === 'fail'));
T::eq(1, count($failing), 'exactly one path failed');
T::eq('Service across VPN', $failing[0]['path'] ?? null, 'and it is the path across the VPN');
T::ok(str_contains($failing[0]['detail'], 'timed out') && str_contains($failing[0]['detail'], 'passed before the update'),
      'with the probe\'s reason, and that it passed before the update: ' . ($failing[0]['detail'] ?? ''));
$clean = campaign_ring_is_clean($wg, 'canary');
T::ok(!$clean['clean'] && str_contains($clean['reason'], 'Branch PC → Service across VPN'), 'the ring summary names the probe and path');
T::ok(!campaign_start_ring($wg, 'pilot')['ok'], 'pilot is held');

cc_set_check_required($wgProbe, $wgChecks[1], false);
T::eq('pass', cc_ring_gate($wg, 'canary')['state'], 'an informational check never gates');
cc_set_check_required($wgProbe, $wgChecks[1], true);
result($wgProbe, $wgChecks[1], true, 5, 'connected to 10.20.0.5:445');
T::eq('pass', cc_ring_gate($wg, 'canary')['state'], 'when the path recovers, the gate passes');
T::ok(campaign_start_ring($wg, 'pilot')['ok'], 'and pilot starts without an override');

// ===========================================================================
T::group('CARP pair');

db()->exec('DELETE FROM customer_probe_results');
$ha = cust('__cc HA customer__');
$master = fw('__cc_ha_master__', $ha, ['carp_enabled' => 1, 'carp_state' => 'MASTER']);
$backup = fw('__cc_ha_backup__', $ha, ['carp_enabled' => 1, 'carp_state' => 'BACKUP']);
db()->prepare('UPDATE firewalls SET ha_peer_firewall_id = ? WHERE id = ?')->execute([$backup, $master]);
db()->prepare('UPDATE firewalls SET ha_peer_firewall_id = ? WHERE id = ?')->execute([$master, $backup]);
// One probe on the LAN behind the pair, registered against the MASTER.
[$haProbe, , $haChecks] = probe_with_paths($ha, $master, 'Clinic reception PC');
T::eq(3, count(cc_checks_for_firewall($backup)), 'a probe behind one member covers its CARP partner');

$carp = campaign('carp', [$master, $backup], true);
campaign_start_ring($carp, 'production');
all_results($haProbe, $haChecks, true, 60);
$d = campaign_dispatch($carp);
T::eq(1, $d['dispatched'], 'one member is dispatched');
T::eq('dispatched', target($carp, $backup)['status'], 'and it is the BACKUP, as before');
T::eq('holding', target($carp, $master)['status'], 'the MASTER is held by HA sequencing');

finish_update($carp, $backup);
time_passes(900);                             // past the 10 minute HA settle time
result($haProbe, $haChecks[0], true, 20);
result($haProbe, $haChecks[1], false, 20, '10.20.0.5:445 connection refused');
result($haProbe, $haChecks[2], true, 20);
$d = campaign_dispatch($carp);
T::eq(0, $d['dispatched'], 'with the client path failing after the BACKUP update, the MASTER is not dispatched');
$hold = (string)target($carp, $master)['hold_reason'];
T::ok(str_contains($hold, 'Customer checks after HA partner __cc_ha_backup__'), 'the hold names the partner: ' . $hold);
T::ok(str_contains($hold, 'Service across VPN'), 'and the failing path');

result($haProbe, $haChecks[1], true, 5, 'connected');
$d = campaign_dispatch($carp);
T::eq(1, $d['dispatched'], 'once the client path is healthy again, the MASTER is dispatched');
T::eq('dispatched', target($carp, $master)['status'], 'BACKUP first, then MASTER');
$mb = json_decode((string)target($carp, $master)['customer_baseline'], true);
T::eq(3, count($mb ?: []), 'the MASTER has its own pre-update baseline');

// HA safety still applies with customer checks on: never both at once.
$carp2 = campaign('carp-both', [$master, $backup], true);
db()->prepare('UPDATE firewalls SET last_update_attempt_at = NULL, last_update_result = NULL, updates_available = 1 WHERE id IN (?,?)')->execute([$master, $backup]);
campaign_start_ring($carp2, 'production');
$d = campaign_dispatch($carp2);
T::eq(1, $d['dispatched'], 'a fresh pair still gets exactly one member at a time');

// ===========================================================================
T::group('Customer Checks page: roles and tenant scope');

function session_as(string $role, ?int $scopeCustomer = null): array {
    global $made;
    db()->prepare('INSERT INTO users (username, password, role, is_active, totp_secret) VALUES (?,?,?,1,"enc:v1:test-fixture-enrolled")')
        ->execute(['__cc_' . $role . bin2hex(random_bytes(3)), 'x', $role]);
    $id = $made['user'][] = (int)db()->lastInsertId();
    if ($scopeCustomer !== null) {
        db()->prepare('INSERT INTO user_customer_scopes (user_id, customer_id) VALUES (?,?)')->execute([$id, $scopeCustomer]);
    }
    return ['user_id' => $id, 'username' => 'u' . $id, 'role' => $role, 'session_version' => 0, 'login_time' => time(),
            'last_activity' => time(), 'last_regenerated' => time(), 'csrf_token' => str_repeat('c', 64)];
}
$csrf = str_repeat('c', 64);
$create = fn(array $sess, int $cust, int $fwId) => call_endpoint('customer_checks.php', ['session' => $sess,
    'post' => ['csrf' => $csrf, 'action' => 'create_probe', 'customer_id' => $cust, 'firewall_id' => $fwId, 'name' => '__cc page probe']]);
$count = fn() => (int)db()->query("SELECT COUNT(*) FROM customer_probes WHERE name = '__cc page probe'")->fetchColumn();

$ro = session_as('readonly');
$r = call_endpoint('customer_checks.php', ['method' => 'GET', 'session' => $ro]);
T::ok($r['status'] === 200 && str_contains($r['body'], 'Acme office PC'), 'readonly can see probes and their state');
T::ok(!str_contains($r['body'], 'name="action" value="create_probe"'), 'but is offered no controls');
$r = $create($ro, $acme, $acmeFw);
T::ok(in_array($r['status'], [302, 403], true) && $count() === 0, "readonly cannot create a probe (denied with {$r['status']}, nothing written)");

$scopedTech = session_as('technician', $other);
$r = call_endpoint('customer_checks.php', ['method' => 'GET', 'session' => $scopedTech]);
T::ok(!str_contains($r['body'], 'Acme office PC') && str_contains($r['body'], 'Other office PC'), 'a technician scoped to one customer sees only that customer\'s probes');
$r = $create($scopedTech, $acme, $acmeFw);
T::ok($count() === 0 && str_contains($r['body'], 'do not have access to that customer'), 'and cannot create one for another customer');
$r = call_endpoint('customer_checks.php', ['session' => $scopedTech,
    'post' => ['csrf' => $csrf, 'action' => 'delete_probe', 'probe_id' => $acmeProbe]]);
T::ok(cc_get_probe($acmeProbe) !== null, 'or delete another customer\'s probe');

$admin = session_as('admin');
$r = $create($admin, $acme, $acmeFw);
T::ok($count() === 1 && preg_match('/opp_[0-9a-f]{48}/', $r['body']) === 1, 'an administrator creates one and sees its token once');
$r = call_endpoint('customer_checks.php', ['method' => 'GET', 'session' => $admin]);
T::ok(preg_match('/opp_[0-9a-f]{48}/', $r['body']) === 0, 'and it is not shown again');
$r = call_endpoint('customer_checks.php', ['session' => $admin,
    'post' => ['csrf' => 'wrong', 'action' => 'delete_probe', 'probe_id' => $acmeProbe]]);
T::ok(cc_get_probe($acmeProbe) !== null, 'a bad CSRF token changes nothing');

exit(T::summary());
