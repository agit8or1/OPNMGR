<?php
/**
 * Mixed-fleet platform layer: detection, capabilities, dispatch and gating.
 *
 * Run against a disposable database:
 *   tests/lib/make_test_db.sh && DB_NAME=opnsense_fw_test php tests/platform_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/agent_commands.php';
require_once TEST_ROOT . '/inc/backup_storage.php';
require_once TEST_ROOT . '/inc/platform/platform.php';

$FIX = TEST_ROOT . '/tests/fixtures/configs';
$created = [];
register_shutdown_function(function () use (&$created) {
    foreach ($created as $id) {
        db()->prepare('DELETE FROM firewall_commands WHERE firewall_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$id]);
    }
    db()->exec("DELETE FROM platform_validations WHERE evidence LIKE 'test:%'");
    db()->exec("UPDATE settings SET value = '' WHERE name = 'policy_apply_lab_firewall_ids'");
});

function mk_fw(array $cols): array {
    global $created;
    $cols = array_merge([
        'hostname' => 'plat-' . bin2hex(random_bytes(3)), 'ip_address' => '198.51.100.40',
        'hardware_id' => bin2hex(random_bytes(16)), 'status' => 'online',
    ], $cols);
    $ph = implode(',', array_fill(0, count($cols), '?'));
    db()->prepare('INSERT INTO firewalls (' . implode(',', array_keys($cols)) . ") VALUES ($ph)")
        ->execute(array_values($cols));
    $id = (int)db()->lastInsertId();
    $created[] = $id;
    return platform_firewall($id);
}

$now = date('Y-m-d H:i:s');

// ===========================================================================
T::group('Detection');

$d = platform_detect(['agent_platform' => ['family' => 'opnsense', 'product_version' => '26.7.8',
    'markers' => ['opnsense_version_bin' => true, 'pfsense_globals' => false]]]);
T::eq('opnsense', $d['family'], 'OPNsense from the opnsense-version marker');
T::eq('high', $d['confidence'], 'with high confidence');
T::eq(null, $d['edition'], 'OPNsense has no edition');

$d = platform_detect(['agent_platform' => ['family' => 'pfsense', 'product_version' => '2.7.2-RELEASE',
    'markers' => ['pfsense_globals' => true, 'plus_globals' => false]]]);
T::eq('pfsense', $d['family'], 'pfSense from globals.inc');
T::eq('community', $d['edition'], 'CE from a 2.x version');

$d = platform_detect(['agent_platform' => ['family' => 'pfsense', 'product_version' => '25.07-RELEASE',
    'markers' => ['pfsense_globals' => true, 'plus_globals' => true]]]);
T::eq('plus', $d['edition'], 'Plus from globals.plus.inc');

$d = platform_detect(['agent_platform' => ['family' => 'pfsense', 'product_version' => '24.11-RELEASE',
    'markers' => ['pfsense_globals' => true]]]);
T::eq('plus', $d['edition'], 'Plus from a YY.MM version when the marker is absent');

$d = platform_detect(['agent_platform' => ['family' => 'pfsense', 'product_version' => 'weird',
    'markers' => ['pfsense_globals' => true]]]);
T::eq('unknown', $d['edition'], 'an unparseable pfSense version gives edition unknown, not a guess');

$d = platform_detect(['agent_platform' => ['base_version' => '14.2-RELEASE-p3']]);
T::eq(null, $d['family'], 'FreeBSD version alone never identifies the product');

$d = platform_detect([]);
T::eq(null, $d['family'], 'no evidence: unknown');
T::eq('low', $d['confidence'], 'with low confidence');

$d = platform_detect(['agent_platform' => ['markers' => ['pfsense_globals' => true]], 'config_root' => 'opnsense']);
T::eq(null, $d['family'], 'contradicting evidence yields unknown');
T::ok($d['conflict'] !== null && str_contains($d['conflict'], 'disagrees'), 'and records the conflict');

$d = platform_detect(['agent_version' => '1.7.0', 'version_string' => '26.7.4_1']);
T::eq('opnsense', $d['family'], 'legacy plugin agent reporting opnsense-version output');
T::eq('medium', $d['confidence'], 'is only medium confidence');

$d = platform_detect(['config_root' => 'pfsense']);
T::eq('pfsense', $d['family'], 'a <pfsense> configuration identifies pfSense');
T::eq('unknown', $d['edition'], 'but not its edition');

// ===========================================================================
T::group('Capabilities');

$opn = mk_fw(['platform_family' => 'opnsense', 'platform_os_version' => '26.7.8',
              'platform_detection_confidence' => 'high', 'platform_verified_at' => $now]);
$ce  = mk_fw(['platform_family' => 'pfsense', 'platform_edition' => 'community',
              'platform_os_version' => '2.7.2-RELEASE', 'platform_verified_at' => $now,
              'platform_probe' => json_encode(['pfsense_config_restore' => true, 'pfssh' => true])]);
$plus = mk_fw(['platform_family' => 'pfsense', 'platform_edition' => 'plus',
               'platform_os_version' => '25.07-RELEASE', 'platform_verified_at' => $now,
               'platform_probe' => json_encode(['pfsense_config_restore' => false])]);
$unk = mk_fw([]);
$stale = mk_fw(['platform_family' => 'opnsense', 'platform_os_version' => '26.7.8',
                'platform_verified_at' => date('Y-m-d H:i:s', time() - 7 * 86400)]);

$c = platform_capabilities($opn);
T::eq('supported', $c['service.control']['state'], 'OPNsense service control is baseline supported');
T::eq('untested', $c['config.restore']['state'] === 'unavailable' ? 'untested' : $c['config.restore']['state'],
      'OPNsense restore is not claimed as supported');
T::eq('unavailable', $c['policy.apply']['state'], 'OPNsense policy apply needs the 1.8 probe first');

$c = platform_capabilities($ce);
T::eq('untested', $c['service.control']['state'], 'pfSense CE service control is implemented but untested');
T::eq('unsupported', $c['firmware.install']['state'], 'pfSense firmware install is explicitly unsupported');
T::ok(str_contains($c['firmware.install']['reason'], 'pfSense-upgrade'), 'with a specific explanation');
T::eq('unsupported', $c['remote.tunnel']['state'], 'pfSense remote tunnel is unsupported, with reason');
T::eq('untested', $c['config.restore']['state'], 'CE restore is untested (probe present)');

$c = platform_capabilities($plus);
T::eq('unavailable', $c['config.restore']['state'], 'Plus restore unavailable when the probe says config_restore() is missing');

$c = platform_capabilities($unk);
foreach ($c as $cap => $row) {
    if ($row['class'] === 'write') {
        T::ok($row['state'] === 'unavailable', "unknown platform: {$cap} is not writable");
    }
}
T::eq('untested', $c['config.backup']['state'], 'unknown platform may still be backed up (read-only)');

$c = platform_capabilities($stale);
T::eq('stale', $c['service.control']['state'], 'a long-unverified platform reports stale');
T::ok(!platform_capability_allows($stale, 'service.control')['allowed'], 'and stale writes are refused');

db()->prepare('INSERT INTO platform_validations (platform_family, platform_edition, version_prefix, capability, validated_at, evidence)
               VALUES ("pfsense","community","2.7","service.control",NOW(),"test: lab restart of unbound")')->execute();
platform_validations(true);
T::eq('supported', platform_capabilities($ce)['service.control']['state'], 'a recorded lab validation makes it supported');
T::eq('untested', platform_capabilities($plus)['service.control']['state'], 'but only for the validated edition/version');

// ===========================================================================
T::group('Platform-specific command dispatch');

$b = build_structured_command('service_restart', ['service' => 'unbound'], $opn);
T::ok($b['ok'] && str_contains($b['command'], 'configctl') , 'OPNsense restart uses configctl');
$b = build_structured_command('service_restart', ['service' => 'unbound'], $ce);
T::ok($b['ok'] && str_contains($b['command'], 'pfSsh.php playback svc restart'), 'pfSense restart uses pfSsh.php svc');
T::ok(!str_contains($b['command'], 'configctl'), 'and never configctl');
$b = build_structured_command('gateway_status', [], $ce);
T::ok($b['ok'] && str_contains($b['command'], 'playback gatewaystatus'), 'pfSense gateway status via playback');
$b = build_structured_command('install_updates', [], $ce);
T::ok(!$b['ok'] && str_contains($b['error'], 'not implemented'), 'pfSense firmware install refused with reason');
$b = build_structured_command('service_restart', ['service' => 'configd'], $ce);
T::ok(!$b['ok'], 'an OPNsense-only service is refused on pfSense');
$b = build_structured_command('service_restart', ['service' => 'unbound'], $plus);
T::ok(!$b['ok'] && str_contains($b['error'], 'lab'), 'untested write on non-lab Plus refused, naming the lab rule');
$b = build_structured_command('ping', ['target' => '9.9.9.9'], $unk);
T::ok($b['ok'], 'unknown platform may run a read-only ping');
$b = build_structured_command('reboot', [], $unk);
T::ok(!$b['ok'], 'unknown platform may not reboot');

db()->exec("UPDATE settings SET value = '{$plus['id']}' WHERE name = 'policy_apply_lab_firewall_ids'");
$b = build_structured_command('service_restart', ['service' => 'unbound'], $plus);
T::ok($b['ok'], 'designated lab firewall may run untested writes');
db()->exec("UPDATE settings SET value = '' WHERE name = 'policy_apply_lab_firewall_ids'");

// ===========================================================================
T::group('Delivery gate (legacy endpoints cannot bypass)');

$v = platform_delivery_verdict($ce, ['command' => '/usr/local/sbin/configctl unbound restart', 'action' => null, 'is_raw' => 1]);
T::ok(!$v['deliver'] && str_contains($v['reason'], 'configctl'), 'raw configctl is not delivered to pfSense');
$v = platform_delivery_verdict($opn, ['command' => '/usr/local/sbin/pfSsh.php playback svc restart unbound', 'action' => null, 'is_raw' => 1]);
T::ok(!$v['deliver'], 'pfSsh.php is not delivered to OPNsense');
$v = platform_delivery_verdict($unk, ['command' => 'uptime', 'action' => null, 'is_raw' => 1]);
T::ok(!$v['deliver'], 'raw shell is not delivered to an unknown platform');
$v = platform_delivery_verdict($opn, ['command' => 'uptime', 'action' => null, 'is_raw' => 1]);
T::ok($v['deliver'], 'raw shell still reaches OPNsense (baseline)');
$v = platform_delivery_verdict($plus, ['command' => 'x', 'action' => 'policy_confirm', 'is_raw' => 0]);
T::ok($v['deliver'], 'a policy confirmation is never withheld');

$q = queue_firewall_command((int)$ce['id'], '/usr/local/sbin/configctl unbound restart', 'legacy', ['is_raw' => true]);
T::ok(!$q['ok'], 'queue_firewall_command refuses OPNsense tooling for pfSense');

// A row inserted directly, as several legacy endpoints do.
db()->prepare('INSERT INTO firewall_commands (firewall_id, command, description, status) VALUES (?,?,?,"pending")')
    ->execute([$ce['id'], '/usr/local/sbin/opnsense-update -bkp', 'direct insert']);
$cmdId = (int)db()->lastInsertId();
require_once TEST_ROOT . '/inc/audit.php';
// platform_filter_deliverable lives in agent_checkin.php; exercise the same
// verdict + close-out the check-in performs.
$row = db()->query("SELECT * FROM firewall_commands WHERE id = $cmdId")->fetch(PDO::FETCH_ASSOC);
$verdict = platform_delivery_verdict($ce, $row);
T::ok(!$verdict['deliver'] && str_contains($verdict['reason'], 'opnsense-update'),
      'a directly inserted opnsense-update is caught at delivery');

// ===========================================================================
T::group('Backups and restore never cross platforms');

$tmp = tempnam(sys_get_temp_dir(), 'opnmgr');
copy("$FIX/pfsense-ce-2.7.2-synthetic.xml", $tmp);
T::ok(validate_backup_upload($tmp, filesize($tmp), 'pfsense')['ok'], 'a pfSense backup is valid for a pfSense firewall');
T::ok(!validate_backup_upload($tmp, filesize($tmp), 'opnsense')['ok'], 'and rejected for an OPNsense firewall');
T::ok(validate_backup_upload($tmp, filesize($tmp), null)['ok'], 'an unverified firewall accepts either root');
copy("$FIX/opnsense-26.7-home-sanitized.xml", $tmp);
T::ok(!validate_backup_upload($tmp, filesize($tmp), 'pfsense')['ok'], 'an OPNsense backup is rejected for pfSense');
unlink($tmp);

$m = platform_normalize_config(file_get_contents("$FIX/opnsense-26.7-home-sanitized.xml"), 'pfsense');
T::ok(!$m['ok'], 'normalizing with the wrong expected platform is an error, not a guess');

// ===========================================================================
T::group('Configuration normalization (fixtures)');

$o = platform_normalize_config(file_get_contents("$FIX/opnsense-26.7-edge-sanitized.xml"));
T::ok($o['ok'] && $o['platform'] === 'opnsense', 'sanitized real OPNsense config parses');
$mvc = array_values(array_filter($o['filter_rules'], fn($r) => $r['section'] === 'mvc'));
T::ok(count($mvc) === 67, 'all 67 MVC rules kept (' . count($mvc) . ')');
$seq = array_column($mvc, 'sequence');
$sorted = $seq; sort($sorted);
T::ok($seq === $sorted, 'MVC rules are in sequence order');
T::eq(range(0, count($mvc) - 1), array_column($mvc, 'order'), 'with an explicit order index');
T::eq('hybrid', $o['nat']['outbound']['mode'], 'outbound NAT mode preserved');
T::ok(count($o['nat']['port_forwards']) === 54, 'port forwards kept separately from filter rules');
T::ok(count($o['vpn']['wireguard']['peers']) === 3, 'WireGuard MVC peers parsed');

$p = platform_normalize_config(file_get_contents("$FIX/pfsense-ce-2.7.2-synthetic.xml"));
T::ok($p['ok'] && $p['platform'] === 'pfsense', 'pfSense config parses');
T::eq('23.3', $p['config_version'], 'config schema version recorded');
$float = $p['filter_rules'][0];
T::ok($float['floating'] && $float['quick'] && $float['direction'] === 'in', 'floating rule keeps quick + direction');
T::eq(['wan', 'opt1'], $float['interface'], 'and its interface list');
T::eq('1700000001', $float['platform_fields']['tracker'], 'pfSense tracker kept as platform field');
T::ok($p['filter_rules'][1]['quick'] === true, 'interface rules are implicitly quick');
T::ok($p['filter_rules'][3]['enabled'] === false, 'disabled pfSense rule reported disabled');
T::eq(['10.99.0.1/32', '10.10.0.0/24'], $p['vpn']['wireguard']['peers'][0]['allowed_ips'], 'WireGuard AllowedIPs');
T::ok($p['vpn']['wireguard']['peers'][0]['has_psk'] === true, 'PSK presence recorded as a boolean');
T::eq('172.16.50.0/24', $p['vpn']['ipsec']['phase2'][0]['remote']['cidr'], 'IPsec phase 2 selector');
T::eq(['rules', 'aliases', 'nat', 'dns'], $p['ha']['sync_items'], 'pfSense XMLRPC sync items');
T::eq(['10.20.0.53', '9.9.9.9'], $p['system']['dns_servers'], 'DNS servers');
T::eq(['0.pfsense.pool.ntp.org', '1.pfsense.pool.ntp.org'], $p['system']['ntp_servers'], 'NTP servers');

foreach (glob("$FIX/*.xml") as $f) {
    $j = json_encode(platform_normalize_config(file_get_contents($f)));
    T::ok(!preg_match('/MUST-NEVER|FIXTURE-HASYNC|FIXTURE-CARP|BEGIN .*PRIVATE|\$2y\$/', $j),
          'no secret reaches the model: ' . basename($f));
}

$evil = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><opnsense><system><hostname>&e;</hostname></system></opnsense>';
T::ok(!platform_normalize_config($evil)['ok'], 'a DTD/entity config is refused outright');

exit(T::summary());
