<?php
/**
 * OPNMGR configuration drift and firewall health tests.
 *
 * Run with: php tests/health_drift_test.php
 * Creates and removes its own fixtures.
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/config_drift.php';
require_once TEST_ROOT . '/inc/firewall_health.php';

$fwId = 0;
register_shutdown_function(function () use (&$fwId) {
    if (!$fwId) return;
    foreach (['firewall_gateways','firewall_gateway_events','firewall_vpn_tunnels','firewall_vpn_events',
              'firewall_carp','firewall_services','firewall_certificates','config_baselines',
              'config_drift','backups','audit_log'] as $t) {
        try { db()->prepare("DELETE FROM {$t} WHERE firewall_id = ?")->execute([$fwId]); } catch (Throwable $e) {}
    }
    db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$fwId]);
});

db()->prepare('INSERT INTO firewalls (hostname, ip_address, hardware_id, status) VALUES (?,?,?,"online")')
    ->execute(['__test_fw_health__', '198.51.100.11', hash('md5', 'hd' . random_bytes(6))]);
$fwId = (int)db()->lastInsertId();

/** Minimal but realistic OPNsense config. */
function cfg(string $hostname = 'fw', string $extraRule = '', string $revTime = '1700000000'): string {
    return <<<XML
<?xml version="1.0"?>
<opnsense>
  <revision><username>root@1.2.3.4</username><time>{$revTime}</time><description>/ui/edit</description></revision>
  <system><hostname>{$hostname}</hostname><domain>example.net</domain></system>
  <interfaces><wan><if>igc0</if><enable>1</enable></wan><lan><if>igc1</if><enable>1</enable></lan></interfaces>
  <filter>
    <rule><type>pass</type><interface>wan</interface><descr>Allow HTTPS</descr><protocol>tcp</protocol><created><time>111</time></created></rule>
    {$extraRule}
  </filter>
</opnsense>
XML;
}

// ===========================================================================
T::group('Config drift: canonicalisation');

$base = cfg();

T::ok(drift_fingerprint($base)['ok'], 'a well-formed config fingerprints');
T::ok(!drift_fingerprint('<opnsense><broken>')['ok'], 'malformed XML is rejected');

$identical = drift_compare($base, $base);
T::ok(!$identical['changed'], 'a config does not drift from itself');

// Whitespace and self-closing style must not register as drift: two backups of
// an untouched firewall routinely differ this way.
$reformatted = str_replace(
    ['<enable>1</enable>', "\n  "],
    ['<enable>1</enable>' . "\n\n\t", "\n    "],
    $base
);
T::ok(!drift_compare($base, $reformatted)['changed'], 'whitespace differences are not drift');

$selfClosed = str_replace('<domain>example.net</domain>', '<domain>example.net</domain><spare/>', $base);
T::ok(drift_compare($base, $selfClosed)['changed'], 'an added element IS drift');

// The <revision> block is stamped on every save.
$revised = cfg('fw', '', '1799999999');
T::ok(!drift_compare($base, $revised)['changed'], 'the revision block is ignored');

// Rule created/updated timestamps are volatile.
$retimed = str_replace('<time>111</time>', '<time>999</time>', $base);
T::ok(!drift_compare($base, $retimed)['changed'], 'rule created/updated timestamps are ignored');

// Real changes are caught.
$renamed = cfg('fw-renamed');
$cmp = drift_compare($base, $renamed);
T::ok($cmp['changed'], 'a hostname change is drift');
T::eq(['system'], $cmp['modified'], 'and is attributed to the system section');

$extraRule = '<rule><type>block</type><interface>wan</interface><descr>Block SMB</descr><protocol>tcp</protocol></rule>';
$withRule = cfg('fw', $extraRule);
$cmp = drift_compare($base, $withRule);
T::ok($cmp['changed'], 'an added firewall rule is drift');
T::eq(['filter'], $cmp['modified'], 'and is attributed to the filter section');

// Element order within a parent should not matter.
$reordered = str_replace(
    '<hostname>fw</hostname><domain>example.net</domain>',
    '<domain>example.net</domain><hostname>fw</hostname>',
    $base
);
T::ok(!drift_compare($base, $reordered)['changed'], 'element ordering is not drift');

T::group('Config drift: readable differences');

$diff = drift_section_diff($base, $withRule, 'filter', 20);
T::eq(1, count($diff), 'an added rule produces exactly one difference, not one per field');
T::eq('added', $diff[0]['change'] ?? '', 'it is reported as an addition');
T::ok(str_contains((string)($diff[0]['to'] ?? ''), 'Block SMB'),
      'the rule is described by its description, not an opaque index');

// ===========================================================================
T::group('Health ingestion');

$now = time();
health_ingest($fwId, [
    'gateways' => [
        ['name' => 'WAN_GW', 'interface' => 'igc0', 'status' => 'none',
         'delay' => '12.4 ms', 'loss' => '0.0 %', 'is_default' => true],
        ['name' => 'WAN2_GW', 'status' => 'down', 'loss' => '100.0 %'],
    ],
    'vpn' => [
        ['type' => 'wireguard', 'name' => 'wg0', 'status' => 'up',
         'latest_handshake' => $now, 'rx_bytes' => 100, 'tx_bytes' => 200],
        ['type' => 'bogusvpn', 'name' => 'nope', 'status' => 'up'],
    ],
    'services' => [
        ['name' => 'unbound', 'running' => true],
        ['name' => 'openvpn', 'running' => false],
    ],
    'certificates' => [
        // Offset by half a day so floor() lands solidly on 5. Sitting exactly
        // on the boundary made this flaky: any elapsed time between capturing
        // $now and the ingest computing time() dropped the result to 4.
        ['refid' => 'c1', 'name' => 'webgui', 'not_after' => $now + 86400 * 5 + 43200],
        ['refid' => 'c2', 'name' => 'old',    'not_after' => $now - 86400 * 3],
    ],
    'carp' => ['peer_host' => 'peer.example', 'vhids' => [
        ['vhid' => '1', 'state' => 'MASTER', 'interface' => 'igc0'],
    ]],
]);

$gw = db()->prepare('SELECT * FROM firewall_gateways WHERE firewall_id = ? ORDER BY name');
$gw->execute([$fwId]);
$gws = $gw->fetchAll(PDO::FETCH_ASSOC);
T::eq(2, count($gws), 'both gateways stored');
$byName = array_column($gws, null, 'name');
T::eq('12.40', (string)$byName['WAN_GW']['latency_ms'], 'a unit-suffixed latency is parsed');
T::eq('100.00', (string)$byName['WAN2_GW']['loss_percent'], 'a unit-suffixed loss percentage is parsed');
T::eq(1, (int)$byName['WAN_GW']['is_default'], 'the default gateway flag is stored');

$vpn = db()->prepare('SELECT * FROM firewall_vpn_tunnels WHERE firewall_id = ?');
$vpn->execute([$fwId]);
$tunnels = $vpn->fetchAll(PDO::FETCH_ASSOC);
T::eq(1, count($tunnels), 'an unknown VPN type is rejected rather than stored');
T::eq('wireguard', $tunnels[0]['vpn_type'], 'the valid tunnel is kept');

$certs = db()->prepare('SELECT refid, days_remaining FROM firewall_certificates WHERE firewall_id = ? ORDER BY refid');
$certs->execute([$fwId]);
$cs = array_column($certs->fetchAll(PDO::FETCH_ASSOC), 'days_remaining', 'refid');
T::eq(5, (int)$cs['c1'], 'days remaining is computed server-side, not trusted from the agent');
T::ok((int)$cs['c2'] < 0, 'an expired certificate has negative days remaining');

$carp = db()->prepare('SELECT carp_enabled, carp_state FROM firewalls WHERE id = ?');
$carp->execute([$fwId]);
$c = $carp->fetch(PDO::FETCH_ASSOC);
T::eq(1, (int)$c['carp_enabled'], 'CARP is flagged enabled');
T::eq('MASTER', $c['carp_state'], 'the node rolls up to MASTER');

T::group('Health: transitions and pruning');

health_ingest($fwId, ['gateways' => [
    ['name' => 'WAN_GW', 'status' => 'down', 'loss' => '100.0 %'],
    ['name' => 'WAN2_GW', 'status' => 'down'],
]]);

$ev = db()->prepare('SELECT * FROM firewall_gateway_events WHERE firewall_id = ? ORDER BY id DESC');
$ev->execute([$fwId]);
$events = $ev->fetchAll(PDO::FETCH_ASSOC);
T::eq(1, count($events), 'exactly one transition is recorded');
T::eq('none', $events[0]['from_status'], 'the previous status is captured');
T::eq('down', $events[0]['to_status'], 'the new status is captured');

health_ingest($fwId, ['services' => [['name' => 'unbound', 'running' => true]]]);
$svc = db()->prepare('SELECT name FROM firewall_services WHERE firewall_id = ?');
$svc->execute([$fwId]);
T::eq(['unbound'], $svc->fetchAll(PDO::FETCH_COLUMN), 'services no longer reported are pruned');

// An absent section must leave existing state alone.
health_ingest($fwId, ['gateways' => [['name' => 'WAN_GW', 'status' => 'down']]]);
$svc->execute([$fwId]);
T::eq(1, count($svc->fetchAll(PDO::FETCH_COLUMN)), 'an omitted section does not wipe stored state');

// An empty section means "none here" and does clear.
health_ingest($fwId, ['services' => []]);
$svc->execute([$fwId]);
T::eq(0, count($svc->fetchAll(PDO::FETCH_COLUMN)), 'an explicitly empty section clears state');

T::group('Health: input validation');

T::eq(12.4, health_clamp_float('12.4 ms', 0, 1000), 'unit suffixes are stripped');
T::eq(null, health_clamp_float('n/a', 0, 1000),      'non-numeric text yields null');
T::eq(100.0, health_clamp_float('9999', 0, 100),     'values are clamped to range');
T::eq(null, health_clamp_float(null, 0, 100),        'null stays null');
T::eq(null, health_parse_timestamp(0),               'a zero timestamp is null');
T::eq(null, health_parse_timestamp(99999999999),     'an absurd future timestamp is rejected');
T::ok(health_parse_timestamp(time()) !== null,       'a plausible timestamp is accepted');
T::eq(null, health_clean_string(''),                 'an empty string is null');
T::eq('ab', health_clean_string("a\x00b"),           'control characters are stripped');

T::eq('critical', health_gateway_severity(['status' => 'down', 'loss_percent' => null, 'latency_ms' => null]),
      'a down gateway is critical');
T::eq('ok', health_gateway_severity(['status' => 'none', 'loss_percent' => 0, 'latency_ms' => 5]),
      'a healthy gateway is ok');
T::eq('warning', health_gateway_severity(['status' => 'none', 'loss_percent' => 50, 'latency_ms' => 5]),
      'high packet loss is a warning even when the status reads healthy');

T::eq('expired', health_cert_severity(-1), 'a past expiry is expired');
T::eq('critical', health_cert_severity(3), 'three days out is critical');
T::eq('ok', health_cert_severity(365),     'a year out is fine');
T::eq('unknown', health_cert_severity(null), 'an unknown expiry is unknown');

T::eq('online', health_gateway_label('none'), 'OPNsense "none" is presented as online');

exit(T::summary());
