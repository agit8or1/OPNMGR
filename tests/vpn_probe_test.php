<?php
/**
 * VPN guided diagnostics: parameter validation, source selection, command
 * construction on both platforms, rate limiting, coordinated legs, results.
 *
 *   DB_NAME=opnsense_fw_test_vpn php tests/vpn_probe_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/vpn/vpn.php';

$made = [];
register_shutdown_function(function () use (&$made) {
    foreach ($made as $id) {
        db()->prepare('DELETE FROM vpn_diagnostic_runs WHERE firewall_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM firewall_commands WHERE firewall_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$id]);
    }
    db()->exec("UPDATE settings SET value = '30' WHERE name = 'vpn_probe_rate_user_per_hour'");
    db()->exec("UPDATE settings SET value = '20' WHERE name = 'vpn_probe_rate_firewall_per_hour'");
});
function mkfw(string $family, ?string $verified = 'now'): array {
    global $made;
    db()->prepare('INSERT INTO firewalls (hostname, ip_address, hardware_id, status, platform_family, platform_edition, platform_os_version, platform_verified_at)
                   VALUES (?,?,?,"online",?,?,?,?)')
        ->execute(['vpnprobe-' . bin2hex(random_bytes(3)), '198.51.100.' . random_int(100, 250), bin2hex(random_bytes(16)),
                   $family ?: null, $family === 'pfsense' ? 'community' : null, $family === 'pfsense' ? '2.7.2-RELEASE' : '26.7.8',
                   $verified ? date('Y-m-d H:i:s') : null]);
    $id = (int)db()->lastInsertId();
    $made[] = $id;
    return platform_firewall($id);
}
function ifc(string $dev, string $ip, int $bits, string $descr = ''): array {
    return ['name' => $dev, 'device' => $dev, 'descr' => $descr, 'enabled' => true, 'ipv4' => $ip, 'subnet' => (string)$bits,
            'network' => platform_cidr_network($ip, $bits), 'ipv6' => null];
}
function dev(array $fw, string $lanIp, string $wan, array $vpn = []): array {
    return ['id' => (int)$fw['id'], 'hostname' => $fw['hostname'], 'customer_id' => 1, 'customer_name' => 'C', 'platform' => $fw['platform_family'],
        'platform_label' => 'x', 'wan_ip' => $wan, 'telemetry_at' => null, 'config_time' => date('Y-m-d H:i:s'), 'runtime' => [], 'activity' => [],
        'config' => ['interfaces' => ['wan' => ifc('em0', $wan, 24, 'WAN'), 'lan' => ifc('em1', $lanIp, 24, 'LAN')], 'routes' => [], 'gateways' => [],
            'nat' => ['port_forwards' => [], 'outbound' => ['mode' => 'automatic', 'rules' => []], 'one_to_one' => []], 'filter_rules' => [], 'alias_names' => [],
            'vpn' => $vpn + ['wireguard' => ['instances' => [], 'peers' => []], 'ipsec' => ['phase1' => [], 'phase2' => [], 'connections' => []], 'openvpn' => ['servers' => [], 'clients' => []]]]];
}
$p2 = fn($l, $r) => ['ikeid' => '1', 'uniqid' => 'u', 'reqid' => '1', 'enabled' => true, 'mode' => 'tunnel',
    'local' => ['type' => 'network', 'cidr' => $l], 'remote' => ['type' => 'network', 'cidr' => $r], 'nat_local' => null, 'description' => ''];
$p1 = fn($gw) => ['ikeid' => '1', 'enabled' => true, 'iketype' => 'ikev2', 'interface' => 'wan', 'remote_gateway' => $gw, 'auth_method' => 'psk', 'my_id' => '', 'peer_id' => '', 'mobile' => false, 'has_psk' => true, 'description' => 't'];

// ===========================================================================
T::group('Probe parameter validation');

$ok = vpn_probe_validate(['method' => 'icmp', 'dest' => '10.10.0.5']);
T::ok($ok['ok'] && $ok['params'] === ['method' => 'icmp', 'target' => '10.10.0.5'], 'a plain ICMP probe validates');
T::ok(!vpn_probe_validate(['method' => 'tcp', 'dest' => '10.10.0.5'])['ok'], 'TCP needs a port');
T::ok(!vpn_probe_validate(['method' => 'tcp', 'dest' => '10.10.0.5', 'port' => '0'])['ok'], 'port 0 is refused');
T::ok(!vpn_probe_validate(['method' => 'udp', 'dest' => '10.10.0.5', 'port' => '70000'])['ok'], 'port 70000 is refused');
T::eq(443, vpn_probe_validate(['method' => 'tcp', 'dest' => '10.10.0.5', 'port' => '443'])['params']['port'], 'a valid TCP port is kept as an integer');
T::ok(!vpn_probe_validate(['method' => 'icmp', 'dest' => '10.10.0.5', 'port' => '80'])['ok'], 'a port on an ICMP probe is refused rather than ignored');
T::ok(!vpn_probe_validate(['method' => 'telnet', 'dest' => '10.10.0.5'])['ok'], 'unknown protocol refused');
foreach (['example.com', '10.0.0.1; rm -rf /', '10.0.0.1 -c 9999', '$(id)', '', '10.0.0.256', '2001:db8::1', '127.0.0.1', '169.254.1.1', '224.0.0.5', '0.0.0.0', '255.255.255.255'] as $bad) {
    T::ok(!vpn_probe_validate(['method' => 'icmp', 'dest' => $bad])['ok'], 'destination "' . $bad . '" is refused');
}
$sub = vpn_probe_validate(['method' => 'icmp', 'dest' => '172.16.4.0/24']);
T::ok($sub['ok'] && $sub['params']['target'] === '172.16.4.1' && $sub['note'] !== null, 'a subnet probes its first host and says so');
T::ok(!vpn_probe_validate(['method' => 'icmp', 'dest' => '10.0.0.0/4'])['ok'], 'a huge subnet is refused');
T::ok(!vpn_probe_validate(['method' => 'icmp', 'dest' => '10.0.0.5', 'size' => '9000'])['ok'], 'payload size is bounded');
T::ok(!vpn_probe_validate(['method' => 'tcp', 'dest' => '10.0.0.5', 'port' => '80', 'size' => '100'])['ok'], 'size only applies to ICMP');

T::ok(!validate_action_params('vpn_probe', ['method' => 'icmp', 'target' => 'host.example'])['ok'], 'the catalogue itself rejects a hostname target');
T::ok(!validate_action_params('vpn_probe', ['method' => 'icmp', 'target' => '10.0.0.1', 'extra' => '1'])['ok'], 'and unexpected parameters');
T::ok(!validate_action_params('vpn_probe', ['method' => 'icmp', 'target' => '10.0.0.1', 'source' => '10.0.0.1; id'])['ok'], 'and an injected source');
T::ok(validate_action_params('vpn_probe', ['method' => 'tcp', 'target' => '10.0.0.1', 'source' => '10.0.0.2', 'port' => 22])['ok'], 'and accepts a well-formed one');

// ===========================================================================
T::group('Commands on each platform');

$opn = mkfw('opnsense'); $pf = mkfw('pfsense'); $unk = mkfw('');
$pr = ['method' => 'icmp', 'target' => '10.10.0.5', 'source' => '10.20.0.1'];
foreach ([['OPNsense', $opn], ['pfSense', $pf]] as [$lbl, $fw]) {
    $c = build_structured_command('vpn_probe', $pr, $fw);
    T::ok($c['ok'] && str_contains($c['command'], '/sbin/ping -c 3 -t 10 -S \'10.20.0.1\' \'10.10.0.5\''), "$lbl: ICMP probe pings from the protected-network address");
    T::ok(str_contains($c['command'], 'OPNMGR_VPN_PROBE_EXIT=$?'), "$lbl: the exit status is reported");
    $c = build_structured_command('vpn_probe', ['method' => 'tcp', 'target' => '10.10.0.5', 'source' => '10.20.0.1', 'port' => 443], $fw);
    T::ok(str_contains($c['command'], "/usr/bin/nc -z -w 3 -s '10.20.0.1' '10.10.0.5' 443"), "$lbl: TCP probe is a bounded nc -z with -s");
    $c = build_structured_command('vpn_probe', ['method' => 'udp', 'target' => '10.10.0.5', 'port' => 53], $fw);
    T::ok(str_contains($c['command'], '/usr/bin/nc -z -u -w 3'), "$lbl: UDP probe uses nc -u");
    $c = build_structured_command('vpn_probe', ['method' => 'traceroute', 'target' => '10.10.0.5', 'source' => '10.20.0.1'], $fw);
    T::ok(str_contains($c['command'], '/usr/sbin/traceroute -w 2 -q 1 -m 20 -s \'10.20.0.1\''), "$lbl: traceroute is hop-limited and source-bound");
    $c = build_structured_command('vpn_probe', ['method' => 'icmp', 'target' => '10.10.0.5', 'size' => 1372], $fw);
    T::ok(str_contains($c['command'], '-D -s 1372'), "$lbl: an MTU probe sets don't-fragment and size");
}
T::ok(!build_structured_command('vpn_probe', $pr, $unk)['ok'], 'a firewall of unverified platform refuses the probe');
T::eq('vpn.diagnostics', platform_action_capability('vpn_probe'), 'the action maps to the vpn.diagnostics capability');

// ===========================================================================
T::group('Source address selection');

$d = dev($opn, '10.20.0.1', '198.51.100.30');
$ep = ['protocol' => 'wireguard', 'local_networks' => ['10.20.0.0/24'], 'tunnel_ips' => ['10.99.0.2'], 'tunnel_ifaces' => ['opt2']];
$s = vpn_probe_source($d, $ep, '10.10.0.5');
T::ok($s['ok'] && $s['address'] === '10.20.0.1' && $s['interface'] === 'lan' && !$s['fallback'], 'WireGuard: the probe is bound to the firewall\'s LAN address');
T::ok(str_contains($s['reason'], '10.20.0.0/24'), 'and the reason names the network it protects');
$s = vpn_probe_source($d, $ep, '10.20.0.77');
T::ok($s['warnings'] !== [] && str_contains($s['warnings'][0], 'local host'), 'a destination on the firewall\'s own LAN is called out');
$ipsec = ['protocol' => 'ipsec', 'local_networks' => ['10.20.0.0/24'], 'tunnel_ips' => [], 'tunnel_ifaces' => ['ipsec']];
T::eq('10.20.0.1', vpn_probe_source($d, $ipsec, '172.16.50.5')['address'], 'IPsec: the address inside the phase 2 local selector');
$ipsecOther = ['protocol' => 'ipsec', 'local_networks' => ['10.99.9.0/24'], 'tunnel_ips' => [], 'tunnel_ifaces' => ['ipsec']];
$s = vpn_probe_source($d, $ipsecOther, '172.16.50.5');
T::ok(!$s['ok'] && str_contains($s['reason'], 'selector'), 'IPsec with no address in the selector refuses rather than probing outside the tunnel');
$noLan = $d; unset($noLan['config']['interfaces']['lan']);
$s = vpn_probe_source($noLan, $ep, '10.10.0.5');
T::ok($s['ok'] && $s['fallback'] && $s['address'] === '10.99.0.2' && $s['warnings'], 'with no protected address it falls back to the tunnel address and warns');

// ===========================================================================
T::group('Queueing, coordinated legs, rate limiting');

// A two-firewall IPsec tunnel with mirrored selectors, both managed.
$fa = mkfw('opnsense'); $fb = mkfw('pfsense');
$wanA = '198.51.100.201'; $wanB = '198.51.100.202';
$da = dev($fa, '10.1.0.1', $wanA, ['ipsec' => ['phase1' => [$p1($wanB)], 'phase2' => [$p2('10.1.0.0/24', '10.2.0.0/24')], 'connections' => []]]);
$db = dev($fb, '10.2.0.1', $wanB, ['ipsec' => ['phase1' => [$p1($wanA)], 'phase2' => [$p2('10.2.0.0/24', '10.1.0.0/24')], 'connections' => []]]);
$topo = vpn_build_topology([$da, $db]);
$ctx = ['topology' => $topo, 'devices' => [$da['id'] => $da, $db['id'] => $db]];
$tun = array_values(array_filter($topo['tunnels'], fn($t) => $t['b'] !== null))[0] ?? null;
T::ok($tun !== null, 'the test tunnel is matched');
$actor = ['user_id' => 424242, 'username' => 'vpn-probe-test'];
$_SESSION['user_id'] = 424242; $_SESSION['username'] = 'vpn-probe-test';

$r = vpn_probe_run(['firewall_id' => $da['id'], 'tunnel_id' => $tun['id'], 'dest' => '10.2.0.50', 'method' => 'icmp', 'both_ends' => 1], $actor, $ctx);
T::ok($r['ok'], 'a coordinated probe is accepted: ' . $r['error']);
T::eq(2, count($r['legs']), 'it queues one leg on each managed end');
T::eq('10.1.0.1', $r['legs'][0]['source_address'] ?? '', 'the first leg is sourced from the protected LAN address');
T::eq('lan', $r['legs'][0]['source_interface'] ?? '', 'and reports the interface');
T::eq('10.1.0.1', $r['legs'][1]['target'] ?? '', 'the peer leg probes back to the first leg\'s source');
T::eq('10.2.0.1', $r['legs'][1]['source_address'] ?? '', 'sourced from the peer\'s own protected address');
T::ok(str_contains($r['disclaimer'], 'does NOT prove'), 'the result states that a firewall probe does not prove a client path');
$cmds = db()->prepare('SELECT command, status, action, is_raw, risk_level FROM firewall_commands WHERE firewall_id IN (?,?)');
$cmds->execute([$da['id'], $db['id']]);
$rows = $cmds->fetchAll(PDO::FETCH_ASSOC);
T::eq(2, count($rows), 'two structured commands were queued through the existing path');
T::ok($rows && $rows[0]['action'] === 'vpn_probe' && (int)$rows[0]['is_raw'] === 0 && $rows[0]['risk_level'] === 'LOW', 'as a LOW-risk structured vpn_probe action, not raw shell');
T::ok(str_contains($rows[0]['command'], "-S '10.1.0.1'") || str_contains($rows[1]['command'], "-S '10.1.0.1'"), 'with the source binding in the command text');
$stored = db()->prepare('SELECT COUNT(*) FROM vpn_diagnostic_runs WHERE run_group = ?');
$stored->execute([$r['run_group']]);
T::eq(2, (int)$stored->fetchColumn(), 'both legs are stored as diagnostic runs');
T::ok(!empty($r['policy']), 'a policy note for a hypothetical client accompanies the result');

// Unmanaged far side: no peer leg.
$ext = vpn_probe_run(['firewall_id' => $da['id'], 'dest' => '10.2.0.50', 'method' => 'icmp', 'both_ends' => 1, 'tunnel_id' => $tun['id']], $actor, ['scope_check' => fn($id) => $id === $da['id']] + $ctx);
T::ok(!$ext['ok'] && str_contains($ext['error'], 'access'), 'a leg on a firewall the user may not access refuses the whole run');
$n = db()->prepare('SELECT COUNT(*) FROM vpn_diagnostic_runs WHERE firewall_id = ?'); $n->execute([$da['id']]);
T::eq(1, (int)$n->fetchColumn(), 'and queues nothing partial');

// Unknown platform refuses.
$du = dev($unk, '10.5.0.1', '198.51.100.99');
$ru = vpn_probe_run(['firewall_id' => $du['id'], 'dest' => '10.9.0.5', 'method' => 'icmp'], $actor, ['topology' => vpn_build_topology([$du]), 'devices' => [$du['id'] => $du]]);
T::ok(!$ru['ok'], 'a firewall of unverified platform cannot run probes');

// Invalid input never reaches the queue.
$ri = vpn_probe_run(['firewall_id' => $da['id'], 'dest' => '10.2.0.50; id', 'method' => 'icmp'], $actor, $ctx);
T::ok(!$ri['ok'] && $ri['run_group'] === null, 'invalid input is refused before anything is queued');

// Per-firewall rate limit.
db()->exec("UPDATE settings SET value = '2' WHERE name = 'vpn_probe_rate_firewall_per_hour'");
db()->prepare('DELETE FROM vpn_diagnostic_runs WHERE firewall_id = ?')->execute([$da['id']]);
db()->prepare("UPDATE firewall_commands SET status = 'completed' WHERE firewall_id = ?")->execute([$da['id']]);
db()->prepare("UPDATE vpn_diagnostic_runs SET status = 'completed' WHERE firewall_id = ?")->execute([$da['id']]);
$one = fn() => vpn_probe_run(['firewall_id' => $da['id'], 'dest' => '10.2.0.50', 'method' => 'icmp'], $actor, $ctx);
$a1 = $one(); db()->prepare("UPDATE vpn_diagnostic_runs SET status = 'completed' WHERE firewall_id = ?")->execute([$da['id']]);
$a2 = $one(); db()->prepare("UPDATE vpn_diagnostic_runs SET status = 'completed' WHERE firewall_id = ?")->execute([$da['id']]);
$a3 = $one();
T::ok($a1['ok'] && $a2['ok'], 'two probes within the per-firewall limit are accepted');
T::ok(!$a3['ok'] && ($a3['retry_after'] ?? 0) > 0 && str_contains($a3['error'], 'this firewall'), 'the third is refused with a retry time');
db()->exec("UPDATE settings SET value = '20' WHERE name = 'vpn_probe_rate_firewall_per_hour'");

// Per-user rate limit (different user is unaffected).
db()->prepare('DELETE FROM vpn_diagnostic_runs WHERE firewall_id IN (?,?)')->execute([$da['id'], $db['id']]);
db()->prepare('DELETE FROM firewall_commands WHERE firewall_id IN (?,?)')->execute([$da['id'], $db['id']]);
db()->exec("UPDATE settings SET value = '1' WHERE name = 'vpn_probe_rate_user_per_hour'");
$u1 = $one(); db()->prepare("UPDATE vpn_diagnostic_runs SET status = 'completed' WHERE firewall_id = ?")->execute([$da['id']]);
$u2 = $one();
T::ok($u1['ok'], 'first probe for the user is accepted');
T::ok(!$u2['ok'] && str_contains($u2['error'], 'your account'), 'the second exceeds the per-user limit');
$other = vpn_probe_run(['firewall_id' => $da['id'], 'dest' => '10.2.0.50', 'method' => 'icmp'], ['user_id' => 515151, 'username' => 'other'], $ctx);
T::ok($other['ok'], 'another user is not blocked by the first user\'s limit');
db()->exec("UPDATE settings SET value = '30' WHERE name = 'vpn_probe_rate_user_per_hour'");

// In-flight cap.
db()->prepare('DELETE FROM vpn_diagnostic_runs WHERE firewall_id IN (?,?)')->execute([$da['id'], $db['id']]);
db()->prepare('DELETE FROM firewall_commands WHERE firewall_id IN (?,?)')->execute([$da['id'], $db['id']]);
$res = []; for ($i = 0; $i < 4; $i++) { $res[] = $one(); }
T::eq([true, true, true, false], array_column($res, 'ok'), 'no more than three probes may wait on one firewall\'s agent');

// ===========================================================================
T::group('Result interpretation');

$ping = "PING 10.2.0.50: 56 data bytes\n3 packets transmitted, 3 packets received, 0.0% packet loss\nround-trip min/avg/max/stddev = 1.0/2.0/3.0/0.5 ms\nOPNMGR_VPN_PROBE_EXIT=0";
T::eq('reachable', vpn_probe_interpret('icmp', 'completed', $ping)['outcome'], 'ping with replies is reachable');
T::eq('unreachable', vpn_probe_interpret('icmp', 'completed', "3 packets transmitted, 0 packets received, 100.0% packet loss\nOPNMGR_VPN_PROBE_EXIT=2")['outcome'], 'ping with none is unreachable');
T::eq('inconclusive', vpn_probe_interpret('icmp', 'completed', 'garbage')['outcome'], 'unparseable output is inconclusive');
T::eq('reachable', vpn_probe_interpret('tcp', 'completed', "Connection to 10.2.0.50 443 port [tcp/https] succeeded!\nOPNMGR_VPN_PROBE_EXIT=0")['outcome'], 'TCP success is reachable');
$ref = vpn_probe_interpret('tcp', 'completed', "nc: connect to 10.2.0.50 port 81 (tcp) failed: Connection refused\nOPNMGR_VPN_PROBE_EXIT=1");
T::ok($ref['outcome'] === 'reachable' && str_contains($ref['summary'], 'refusal'), 'a refusal proves the path and is explained');
T::eq('unreachable', vpn_probe_interpret('tcp', 'completed', "OPNMGR_VPN_PROBE_EXIT=1")['outcome'], 'a timeout is unreachable');
T::eq('inconclusive', vpn_probe_interpret('udp', 'completed', "OPNMGR_VPN_PROBE_EXIT=0")['outcome'], 'UDP silence is never reported as success');
T::eq(null, vpn_probe_interpret('icmp', 'pending', null)['outcome'], 'a pending command has no outcome yet');
T::eq('failed', vpn_probe_interpret('icmp', 'failed', null)['status'], 'a failed command is failed');
T::eq('unreachable', vpn_probe_interpret('traceroute', 'completed', " 1  * * *\n 2  * * *")['outcome'], 'an all-silent traceroute is unreachable');

// Sync stores the outcome and feeds it back into state.
$sync = vpn_probe_run(['firewall_id' => $db['id'], 'dest' => '10.1.0.50', 'method' => 'icmp'], ['user_id' => 616161, 'username' => 'x'], $ctx);
T::ok($sync['ok'], 'a probe for the sync test is queued');
$cid = $sync['legs'][0]['command_id'];
db()->prepare("UPDATE firewall_commands SET status = 'completed', result = ?, completed_at = NOW() WHERE id = ?")->execute([$ping, $cid]);
$legs = vpn_probe_sync($sync['run_group']);
T::eq('completed', $legs[0]['status'], 'a finished command completes the run');
T::eq('reachable', $legs[0]['outcome'], 'with its outcome');
$again = vpn_probe_sync($sync['run_group']);
T::eq('reachable', $again[0]['outcome'], 'syncing again is idempotent');

// The UI says what the probe is.
foreach (['vpn_diagnostics.php'] as $page) {
    $src = is_file(TEST_ROOT . '/' . $page) ? file_get_contents(TEST_ROOT . '/' . $page) : '';
    T::ok(str_contains($src, 'VPN_PROBE_DISCLAIMER'), "$page shows the firewall-probe disclaimer");
}

exit(T::summary());
