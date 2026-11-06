<?php
/**
 * VPN topology: state classification, peer matching, mixed-fleet topology,
 * overlap and mismatch findings, operator pairing, scoping and secrecy.
 *
 *   tests/lib/make_test_db.sh opnsense_fw_test_vpn
 *   DB_NAME=opnsense_fw_test_vpn php tests/vpn_topology_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/vpn/vpn.php';

$FIX = TEST_ROOT . '/tests/fixtures/configs/';
$NOW = time();
$ago = fn(int $s) => date('Y-m-d H:i:s', $NOW - $s);

register_shutdown_function(function () {
    db()->exec("DELETE FROM vpn_peer_pairings WHERE created_by = 'vpn-test'");
    db()->exec('DELETE FROM vpn_layout_prefs WHERE user_id = 987654');
});

// ---- helpers ---------------------------------------------------------------
function ifc(string $dev, string $ip, int $bits, string $descr = ''): array {
    return ['name' => $dev, 'device' => $dev, 'descr' => $descr ?: strtoupper($dev), 'enabled' => true,
            'ipv4' => $ip, 'subnet' => (string)$bits, 'network' => platform_cidr_network($ip, $bits), 'ipv6' => null];
}
function wgpeer(string $name, string $pk, array $allowed, ?string $endpoint = null, ?string $port = null, string $ka = '25', string $inst = 'wg0'): array {
    return ['id' => $name, 'name' => $name, 'enabled' => true, 'public_key' => $pk, 'has_psk' => false, 'allowed_ips' => $allowed,
            'endpoint' => $endpoint, 'endpoint_port' => $port, 'keepalive' => $ka, 'instance' => $inst];
}
function wginst(string $pk, array $addrs, string $port = '51820', string $mtu = ''): array {
    return ['id' => 'wg0', 'name' => 'wg0', 'interface' => 'wg0', 'enabled' => true, 'public_key' => $pk, 'listen_port' => $port,
            'addresses' => $addrs, 'mtu' => $mtu, 'disable_routes' => false, 'peer_ids' => []];
}
function mkdev(int $id, string $host, array $cfg, array $extra = []): array {
    $cfg += ['interfaces' => [], 'routes' => [], 'gateways' => [], 'nat' => ['port_forwards' => [], 'outbound' => ['mode' => 'automatic', 'rules' => []], 'one_to_one' => []],
             'filter_rules' => [], 'alias_names' => [], 'vpn' => ['wireguard' => ['instances' => [], 'peers' => []], 'ipsec' => ['phase1' => [], 'phase2' => [], 'connections' => []], 'openvpn' => ['servers' => [], 'clients' => []]]];
    return $extra + ['id' => $id, 'hostname' => $host, 'customer_id' => 1, 'customer_name' => 'Acme', 'site_id' => null, 'site_name' => null,
        'platform' => 'opnsense', 'platform_label' => 'OPNsense', 'wan_ip' => null, 'telemetry_at' => null, 'config' => $cfg,
        'config_time' => date('Y-m-d H:i:s'), 'runtime' => [], 'activity' => []];
}
function wgcfg(string $wan, string $lan, int $lanBits, string $pk, array $addrs, array $peers, string $port = '51820', string $mtu = ''): array {
    return ['interfaces' => ['wan' => ifc('em0', $wan, 24, 'WAN') + [], 'lan' => ifc('em1', $lan, $lanBits, 'LAN'), 'opt2' => ifc('wg0', explode('/', $addrs[0])[0], 30, 'WG')],
            'vpn' => ['wireguard' => ['instances' => [wginst($pk, $addrs, $port, $mtu)], 'peers' => $peers], 'ipsec' => ['phase1' => [], 'phase2' => [], 'connections' => []], 'openvpn' => ['servers' => [], 'clients' => []]]];
}
function dev_from_xml(int $id, string $host, string $file, array $extra = []): array {
    $m = platform_normalize_config(file_get_contents($file));
    return mkdev($id, $host, vpn_slice_from_model($m), ['platform' => $m['platform']] + $extra);
}
function tunnels_of(array $topo, string $kind = null): array {
    return array_values(array_filter($topo['tunnels'], fn($t) => $kind === null || $t['kind'] === $kind));
}
function ftypes(array $f): array { return array_values(array_unique(array_column($f, 'type'))); }

$K_HQ = 'SFFQdWJLZXlGaXh0dXJlQUFBQUFBQUFBQUFBQUFBQUE=';       // pfSense fixture peer key (the HQ side)
$K_BR = 'QnJhbmNoUHViS2V5Rml4dHVyZUFBQUFBQUFBQUFBQUE=';       // pfSense fixture tunnel key (the branch)
$th = ['stale_s' => 1800, 'wg_up_s' => 180, 'wg_idle_s' => 900, 'probe_ttl_s' => 1800, 'traffic_recent_s' => 300];

// ===========================================================================
T::group('State: idle vs stale vs unknown vs down');

$st = function (array $ep, array $row = null, array $ctx = []) use ($NOW, $th, $ago) {
    $ep += ['protocol' => 'wireguard', 'role' => 'site_to_site', 'enabled' => true, 'details' => [], 'activity' => null];
    $ep['runtime'] = $row;
    return vpn_endpoint_state($ep, $ctx + ['now' => $NOW, 'thresholds' => $th, 'telemetry_at' => $ago(60)])['state'];
};
$wg = fn(?int $hsAgo, int $rx, int $tx) => ['vpn_type' => 'wireguard', 'name' => 'wg0:p', 'status' => 'x',
    'latest_handshake' => $hsAgo === null ? null : $ago($hsAgo), 'rx_bytes' => $rx, 'tx_bytes' => $tx];
$days = 86400;

T::eq('idle', $st(['role' => 'remote_access'], $wg(5 * $days, 9000, 4000)), 'remote-access user silent for 5 days is idle, not down');
T::eq('idle', $st(['role' => 'remote_access'], $wg(null, 0, 0)), 'remote-access user who never connected is idle');
T::eq('idle', $st(['role' => 'remote_access'], $wg(40 * $days, 10, 10)), 'even after 40 days idle');
T::eq('up', $st(['role' => 'remote_access'], $wg(5 * $days, 9000, 4000), ['probe' => ['outcome' => 'reachable', 'at' => $ago(60)]]), 'an approved probe that reaches the peer shows it up');
T::eq('idle', $st(['role' => 'remote_access'], $wg(5 * $days, 9000, 4000), ['probe' => ['outcome' => 'unreachable', 'at' => $ago(60)]]), 'a failed probe does not turn a roaming user into a failure');
$act = ['last_change_at' => $ago(30)];
T::eq('up', $st(['role' => 'remote_access', 'activity' => $act], $wg(3600, 9000, 4000)), 'counters moving now mean the user is active even without a recent handshake');
T::eq('down', $st([], $wg(null, 0, 0)), 'site-to-site with no handshake and zero counters is down');
T::eq('down', $st([], $wg(5 * $days, 9000, 4000)), 'site-to-site silent for days is down');
T::eq('up', $st([], $wg(30, 100, 100)), 'site-to-site with a fresh handshake is up');
T::eq('degraded', $st(['details' => ['keepalive' => '25']], $wg(400, 100, 100)), 'keepalive peer with a 400s-old handshake is degraded');
T::eq('idle', $st([], $wg(400, 100, 100)), 'no keepalive configured: 400s quiet is idle');
T::eq('degraded', $st([], $wg(null, 500, 500)), 'counters moving with no handshake is degraded, not down');
T::eq('degraded', $st([], $wg(30, 100, 100), ['probe' => ['outcome' => 'unreachable', 'at' => $ago(30)]]), 'fresh handshake but a failed probe through it: degraded');
T::eq('stale', $st([], $wg(30, 100, 100), ['telemetry_at' => $ago(7200)]), 'telemetry older than the threshold is stale, whatever it says');
T::eq('stale', $st(['role' => 'remote_access'], $wg(30, 1, 1), ['telemetry_at' => $ago(7200)]), 'stale beats up for remote-access too');
T::eq('unknown', $st([], null), 'no telemetry at all is unknown');
T::eq('disabled', $st(['enabled' => false], $wg(30, 1, 1)), 'disabled in configuration is disabled');
T::eq('down', $st(['protocol' => 'ipsec'], ['vpn_type' => 'ipsec', 'name' => 'c', 'status' => 'down']), 'IPsec site tunnel reported down is down');
T::eq('idle', $st(['protocol' => 'ipsec', 'role' => 'remote_access'], ['vpn_type' => 'ipsec', 'name' => 'c', 'status' => 'down']), 'IPsec remote-access service with no sessions is idle');
T::eq('up', $st(['protocol' => 'openvpn'], ['vpn_type' => 'openvpn', 'name' => 'c', 'status' => 'up']), 'OpenVPN up is up');
$s = vpn_endpoint_state(['protocol' => 'wireguard', 'role' => 'site_to_site', 'enabled' => true, 'details' => [], 'runtime' => null],
    ['now' => $NOW, 'thresholds' => $th]);
T::ok($s['limitations'] !== [], 'unknown carries a limitation saying why nothing is claimed');
T::eq('degraded', vpn_combine_states('up', 'down'), 'one end up and one end down combine to degraded');
T::eq('up', vpn_combine_states('up', null), 'a tunnel with an unmanaged far end keeps the managed end state');
T::eq('up', vpn_combine_states('up', 'unknown'), 'an unknown far end does not drag a known state down');
T::eq('idle', vpn_combine_states('idle', 'idle'), 'idle plus idle is idle');

// ===========================================================================
T::group('Mixed OPNsense / pfSense topology from fixtures');

// An OPNsense HQ built to be the other end of the pfSense CE fixture's WireGuard link.
$hq = mkdev(101, 'hq-opn', wgcfg('198.51.100.5', '10.10.0.1', 24, $K_HQ, ['10.99.0.1/30'],
    [wgpeer('Branch', $K_BR, ['10.99.0.2/32', '10.20.0.0/24'], null, null, '25')]), ['wan_ip' => '198.51.100.5', 'customer_name' => 'Acme']);
$ce = dev_from_xml(102, 'branch-ce', $FIX . 'pfsense-ce-2.7.2-synthetic.xml', ['wan_ip' => '198.51.100.20']);
$plus = dev_from_xml(103, 'branch-plus', $FIX . 'pfsense-plus-25.07-synthetic.xml', ['wan_ip' => '198.51.100.20']);
$edge = dev_from_xml(104, 'edge-opn', $FIX . 'opnsense-26.7-edge-sanitized.xml');
$home = dev_from_xml(105, 'home-opn', $FIX . 'opnsense-26.7-home-sanitized.xml');

T::eq('pfsense', $ce['platform'], 'CE fixture is a pfSense device');
T::eq('opnsense', $edge['platform'], 'edge fixture is an OPNsense device');
$cePeer = $ce['config']['vpn']['wireguard']['peers'][0];
T::ok(in_array('10.10.0.0/24', $cePeer['allowed_ips'], true) && in_array('10.99.0.1/32', $cePeer['allowed_ips'], true),
      'the pfSense fixture peer carries AllowedIPs 10.99.0.1/32 and 10.10.0.0/24');

$topo = vpn_build_topology([$hq, $ce]);
$links = array_values(array_filter($topo['tunnels'], fn($t) => $t['b'] !== null && $t['protocol'] === 'wireguard'));
T::eq(1, count($links), 'OPNsense HQ and pfSense branch are matched into one WireGuard tunnel');
T::eq('inferred', $links[0]['relationship'] ?? '', 'with no telemetry the relationship is inferred from configuration');
T::eq('site_to_site', $links[0]['kind'] ?? '', 'and it is a site-to-site tunnel');
T::ok(($links[0]['match_score'] ?? 0) >= 100, 'public keys match in both directions');
T::eq('unknown', $links[0]['state'] ?? '', 'no telemetry: the state is unknown, not guessed');
T::eq(['opnsense', 'pfsense'], array_values(array_unique($links[0]['platforms'] ?? [])) === ['opnsense', 'pfsense'] ? ['opnsense', 'pfsense'] : $links[0]['platforms'], 'the tunnel spans both platforms');

$ipsec = array_values(array_filter($topo['tunnels'], fn($t) => $t['protocol'] === 'ipsec'));
T::eq('external', $ipsec[0]['kind'] ?? '', 'the partner IPsec tunnel (remote gateway 203.0.113.50) is an external peer');
T::ok($ipsec[0]['b'] === null && $ipsec[0]['partial'] === true, 'and its far side is not invented');
$ra = tunnels_of($topo, 'remote_access');
T::eq('openvpn', $ra[0]['protocol'] ?? '', 'the OpenVPN server is a remote-access service');

// Telemetry on one side upgrades the relationship to observed.
$hq2 = $hq; $hq2['telemetry_at'] = $ago(30);
$hq2['runtime'] = [['vpn_type' => 'wireguard', 'name' => 'wg0:Branch', 'peer' => $K_BR, 'status' => 'up', 'enabled' => 1, 'latest_handshake' => $ago(20), 'rx_bytes' => 5000, 'tx_bytes' => 6000, 'updated_at' => $ago(30)]];
$topo2 = vpn_build_topology([$hq2, $ce], [], ['now' => $NOW]);
$l2 = array_values(array_filter($topo2['tunnels'], fn($t) => $t['b'] !== null && $t['protocol'] === 'wireguard'))[0];
T::eq('observed', $l2['relationship'], 'a handshake seen on one end makes the relationship observed');
T::eq('up', $l2['state'], 'the tunnel carries the observed end state');
T::eq('unknown', $l2['state_b']['state'], 'while the unobserved pfSense end stays unknown (nothing fabricated)');

// The real OPNsense fixtures: keys are REDACTED, so they must not be merged on that.
$real = vpn_build_topology([$edge, $home]);
T::eq(0, count(array_filter($real['tunnels'], fn($t) => $t['b'] !== null)), 'redacted identical keys in the real fixtures do not pair edge with home');
T::ok(count(tunnels_of($real, 'ambiguous')) >= 1, 'the likely-but-unproven pair is reported ambiguous for the operator');
$raEdge = array_filter($real['tunnels'], fn($t) => $t['kind'] === 'remote_access' && $t['protocol'] === 'wireguard');
T::ok(count($raEdge) >= 2, 'host-only WireGuard peers with no endpoint are classified remote-access');
$graph = vpn_graph($real);
T::ok((bool)array_filter($graph['nodes'], fn($n) => $n['type'] === 'remote_access'), 'remote-access peers are grouped into a node on the map');

// ===========================================================================
T::group('Peer matching: shared addresses, keys, operator pairing');

$KB1 = 'KB1pubkeyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='; $KB2 = 'KB2pubkeyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='; $KHQ = 'KHQpubkeyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='; $KX = 'KXunmanagedAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
$hqA = fn(string $peerKey) => mkdev(201, 'hq', wgcfg('198.51.100.5', '10.10.0.1', 24, $KHQ, ['10.99.0.1/30'],
    [wgpeer('Site', $peerKey, ['10.99.0.2/32', '172.20.0.0/24'], '203.0.113.9', '51820')]), ['wan_ip' => '198.51.100.5']);
$b1 = mkdev(202, 'b1', wgcfg('10.0.0.2', '172.20.0.1', 24, $KB1, ['10.99.0.2/30'], [wgpeer('HQ', $KHQ, ['10.99.0.1/32', '10.10.0.0/24'], '198.51.100.5', '51820')]), ['wan_ip' => '203.0.113.9']);
$b2 = mkdev(203, 'b2', wgcfg('10.0.1.2', '172.21.0.1', 24, $KB2, ['10.99.0.6/30'], [wgpeer('HQ', $KHQ, ['10.99.0.5/32', '10.10.0.0/24'], '198.51.100.5', '51820')]), ['wan_ip' => '203.0.113.9']);

// Same NAT address, port matches both; HQ's peer key belongs to neither and neither
// carries HQ's current key (HQ was re-keyed) and their own keys are not in the snapshots,
// so only the shared address remains.
$KOLD = 'KHQoldAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
$b1o = mkdev(202, 'b1', wgcfg('10.0.0.2', '172.20.0.1', 24, '', ['10.99.0.2/30'], [wgpeer('HQ', $KOLD, ['10.88.0.1/32', '10.10.0.0/24'], '198.51.100.5', '51820')]), ['wan_ip' => '203.0.113.9']);
$b2o = mkdev(203, 'b2', wgcfg('10.0.1.2', '172.21.0.1', 24, '', ['10.99.0.6/30'], [wgpeer('HQ', $KOLD, ['10.88.0.1/32', '10.10.0.0/24'], '198.51.100.5', '51820')]), ['wan_ip' => '203.0.113.9']);
$hqNoTip = fn(string $k) => mkdev(201, 'hq', wgcfg('198.51.100.5', '10.10.0.1', 24, $KHQ, ['10.99.0.1/30'],
    [wgpeer('Site', $k, ['172.20.0.0/24'], '203.0.113.9', '51820')]), ['wan_ip' => '198.51.100.5']);
$t = vpn_build_topology([$hqNoTip($KX), $b1o, $b2o]);
T::eq(0, count(array_filter($t['tunnels'], fn($x) => $x['b'] !== null)), 'two firewalls behind one public IP are not merged on the address');
$hqT = array_values(array_filter($t['tunnels'], fn($x) => $x['a']['firewall_id'] === 201))[0];
T::eq('ambiguous', $hqT['kind'], 'the endpoint is reported ambiguous rather than guessed');
T::ok(count($t['ambiguous']) > 0, 'with the candidates listed for the operator');

// If both sides' keys are known and do not correspond, the shared address cannot pair them.
$b1k = mkdev(202, 'b1', wgcfg('10.0.0.2', '172.20.0.1', 24, $KB1, ['10.99.0.2/30'], [wgpeer('HQ', $KOLD, ['10.88.0.1/32'], '198.51.100.5', '51820')]), ['wan_ip' => '203.0.113.9']);
$tk = vpn_build_topology([$hqNoTip($KX), $b1k]);
T::eq(0, count(array_filter($tk['tunnels'], fn($x) => $x['b'] !== null)), 'known, non-corresponding keys veto an address-only match');

// Public key decides it.
$t = vpn_build_topology([$hqA($KB1), $b1, $b2]);
$pairs = array_values(array_filter($t['tunnels'], fn($x) => $x['b'] !== null));
T::eq(1, count($pairs), 'a public-key match pairs exactly one tunnel');
T::eq([201, 202], $pairs[0]['firewall_ids'] ?? [], 'HQ pairs with b1 (key match), not b2 (same public IP)');
$b2t = array_values(array_filter($t['tunnels'], fn($x) => $x['a']['firewall_id'] === 203));
T::ok($b2t && $b2t[0]['b'] === null, 'b2 stays unpaired');
T::eq('inferred', $pairs[0]['relationship'], 'no telemetry: inferred');

// Operator pairing overrides the automatic result, and is persisted.
$hqEp = 'fw201|wireguard|' . $KB1; $b2Ep = 'fw203|wireguard|' . $KHQ; $b1Ep = 'fw202|wireguard|' . $KHQ;
$known = vpn_build_topology([$hqA($KB1), $b1, $b2])['endpoints'];
T::ok(isset($known[$hqEp], $b2Ep, $known[$b2Ep]) || (isset($known[$hqEp]) && isset($known[$b2Ep])), 'endpoint keys are stable and derived from firewall, protocol and peer key');
$r = vpn_pairing_apply('pair', ['a' => $hqEp, 'b' => $b2Ep, 'note' => 'behind same NAT'], 'vpn-test', $known);
T::ok($r['ok'], 'an operator can pair two endpoints');
$t = vpn_build_topology([$hqA($KB1), $b1, $b2], vpn_pairings_load());
$pairs = array_values(array_filter($t['tunnels'], fn($x) => $x['b'] !== null));
T::eq([201, 203], $pairs[0]['firewall_ids'] ?? [], 'the manual pairing overrides the key-based match');
T::eq('manual', $pairs[0]['relationship'] ?? '', 'and is shown as manually configured');
$t3 = vpn_build_topology([$hqA($KB1), $b1, $b2], vpn_pairings_load());
T::eq(1, count(array_filter($t3['tunnels'], fn($x) => $x['b'] !== null)), 'persisted: a re-build from storage keeps the pairing');
$r = vpn_pairing_apply('clear_pair', ['a' => $hqEp, 'b' => $b2Ep], 'vpn-test', $known);
$r = vpn_pairing_apply('unpair', ['a' => $hqEp, 'b' => $b1Ep], 'vpn-test', $known);
$t = vpn_build_topology([$hqA($KB1), $b1, $b2], vpn_pairings_load());
T::eq(0, count(array_filter($t['tunnels'], fn($x) => $x['b'] !== null && in_array(202, $x['firewall_ids'], true))), 'an operator un-pair removes the automatic match with b1 (b2 may still match on its own evidence)');
vpn_pairing_apply('clear_pair', ['a' => $hqEp, 'b' => $b1Ep], 'vpn-test', $known);
$t = vpn_build_topology([$hqA($KB1), $b1, $b2], vpn_pairings_load());
T::eq(1, count(array_filter($t['tunnels'], fn($x) => $x['b'] !== null && in_array(202, $x['firewall_ids'], true))), 'clearing the un-pair restores it');
vpn_pairing_apply('classify', ['a' => $hqEp, 'classification' => 'remote_access'], 'vpn-test', $known);
$t = vpn_build_topology([$hqA($KB1), $b1, $b2], vpn_pairings_load());
T::eq(['remote_access', 'operator'], [$t['endpoints'][$hqEp]['role'], $t['endpoints'][$hqEp]['role_source']], 'an operator classification overrides the inferred role and is marked as such');
vpn_pairing_apply('clear_classify', ['a' => $hqEp], 'vpn-test', $known);
T::ok(!vpn_pairing_apply('pair', ['a' => $hqEp, 'b' => 'fw201|wireguard|x'], 'vpn-test')['ok'], 'both ends on one firewall is refused');
T::ok(!vpn_pairing_apply('pair', ['a' => 'junk', 'b' => $b1Ep], 'vpn-test')['ok'], 'a malformed endpoint reference is refused');
T::ok(!vpn_pairing_apply('pair', ['a' => $hqEp, 'b' => 'fw202|ipsec|p1-1'], 'vpn-test')['ok'], 'different protocols cannot be paired');

// Identical fixtures on two firewalls must not both claim the HQ peer.
$tp = vpn_build_topology([$hq, $ce, $plus]);
T::eq(0, count(array_filter($tp['tunnels'], fn($x) => $x['b'] !== null && $x['protocol'] === 'wireguard')), 'two branches presenting the same keys tie, so neither is paired automatically');

// ===========================================================================
T::group('Overlap: routing domains, not repeated RFC1918');

$mk = fn(int $id, string $name, string $lan, int $cust, array $peers, string $pk, array $addrs, ?string $wan = null) =>
    mkdev($id, $name, wgcfg($wan ?? "198.51.100.$id", $lan, 24, $pk, $addrs, $peers), ['customer_id' => $cust, 'customer_name' => "C$cust", 'wan_ip' => $wan ?? "198.51.100.$id"]);
$pkA = 'PKAaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa='; $pkB = 'PKBbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb='; $pkC = 'PKCcccccccccccccccccccccccccccccccccccccccc='; $pkD = 'PKDddddddddddddddddddddddddddddddddddddddd=';
// Two customers each use 192.168.1.0/24 and never connect.
$iso1 = $mk(301, 'cust1-fw', '192.168.1.1', 1, [wgpeer('x', 'ZZ1', ['10.50.0.9/32'])], $pkA, ['10.50.0.1/30']);
$iso2 = $mk(302, 'cust2-fw', '192.168.1.1', 2, [wgpeer('y', 'ZZ2', ['10.51.0.9/32'])], $pkB, ['10.51.0.1/30']);
$t = vpn_build_topology([$iso1, $iso2]);
T::eq([], array_values(array_filter(vpn_diagnose($t, [$iso1, $iso2]), fn($f) => $f['type'] === 'subnet_overlap')), 'identical 192.168.1.0/24 at two isolated customers is not flagged');
// Same range, connected by a tunnel.
$c1 = $mk(311, 'site1', '192.168.1.1', 1, [wgpeer('s2', $pkD, ['10.60.0.2/32', '192.168.1.0/24'], '198.51.100.312', '51820')], $pkC, ['10.60.0.1/30']);
$c2 = $mk(312, 'site2', '192.168.1.1', 1, [wgpeer('s1', $pkC, ['10.60.0.1/32', '192.168.1.0/24'], '198.51.100.311', '51820')], $pkD, ['10.60.0.2/30']);
$t = vpn_build_topology([$c1, $c2]);
T::eq(1, count(array_filter($t['tunnels'], fn($x) => $x['b'] !== null)), 'the connected pair is matched');
$ov = array_values(array_filter(vpn_diagnose($t, [$c1, $c2]), fn($f) => $f['type'] === 'subnet_overlap' && count($f['affected']['firewalls']) === 2));
T::ok(count($ov) >= 1, 'the same range across a connected tunnel IS flagged');
T::eq('critical', $ov[0]['severity'] ?? '', 'as critical when nothing translates it');
T::ok(!empty($ov[0]['evidence']) && !empty($ov[0]['freshness']) && !empty($ov[0]['limitation']) && !empty($ov[0]['next_step']) && isset($ov[0]['confidence']) && isset($ov[0]['basis']),
      'the finding carries evidence, freshness, limitation, confidence, basis and a next step');
T::eq([311, 312], $ov[0]['affected']['firewalls'] ?? [], 'and names the affected firewalls');
// 1:1 NAT softens it.
$c2n = $c2; $c2n['config']['nat']['one_to_one'] = [['interface' => 'wan', 'external' => '172.31.0.0/24', 'internal' => '192.168.1.0/24', 'enabled' => true]];
$ov2 = array_values(array_filter(vpn_diagnose(vpn_build_topology([$c1, $c2n]), [$c1, $c2n]), fn($f) => $f['type'] === 'subnet_overlap' && count($f['affected']['firewalls']) === 2));
T::eq('info', $ov2[0]['severity'] ?? '', 'configured 1:1 NAT downgrades the overlap to informational');
// Customers joined.
$c2x = $c2; $c2x['customer_id'] = 2; $c2x['customer_name'] = 'C2';
$cc = vpn_diagnose(vpn_build_topology([$c1, $c2x]), [$c1, $c2x]);
T::ok(in_array('cross_customer_domain', ftypes($cc), true), 'a tunnel between two customers is called out');
// Remote network overlapping the firewall's own LAN.
$self = $mk(321, 'selfov', '10.10.0.1', 1, [wgpeer('p', 'ZZ3', ['10.70.0.2/32', '10.10.0.0/24'], '203.0.113.77', '51820')], $pkA, ['10.70.0.1/30']);
T::ok(in_array('subnet_overlap', ftypes(vpn_diagnose(vpn_build_topology([$self]), [$self])), true), 'AllowedIPs overlapping a local LAN are flagged on one firewall');

// ===========================================================================
T::group('WireGuard AllowedIPs and IPsec selector mismatch');

$clean = vpn_diagnose(vpn_build_topology([$hq, $ce]), [$hq, $ce]);
T::eq([], array_values(array_filter($clean, fn($f) => $f['type'] === 'wg_allowedips_mismatch' && $f['severity'] === 'critical')), 'the consistent OPNsense/pfSense pair has no critical AllowedIPs mismatch');
$hqBad = mkdev(101, 'hq-opn', wgcfg('198.51.100.5', '10.10.0.1', 24, $K_HQ, ['10.99.0.1/30'],
    [wgpeer('Branch', $K_BR, ['10.77.0.0/24'], null, null, '25')]), ['wan_ip' => '198.51.100.5']);
$bad = vpn_diagnose(vpn_build_topology([$hqBad, $ce]), [$hqBad, $ce]);
$m = array_values(array_filter($bad, fn($f) => $f['type'] === 'wg_allowedips_mismatch'));
T::ok(count($m) >= 2, 'AllowedIPs that omit the peer tunnel address and the branch LAN are reported');
T::ok((bool)array_filter($m, fn($f) => $f['severity'] === 'critical' && str_contains($f['title'], '10.99.0.2')), 'missing peer tunnel address is critical');
T::ok((bool)array_filter($m, fn($f) => str_contains($f['title'], '10.20.0.0/24')), 'the branch LAN missing from AllowedIPs is reported');
T::ok((bool)array_filter($m, fn($f) => str_contains($f['title'], '10.77.0.0/24')), 'and a network AllowedIPs sends that the far side does not own');
T::eq('configured', $m[0]['basis'], 'AllowedIPs findings read from configuration are labelled configured, not observed');

$p1 = fn(string $ike, string $gw, string $myid = '') => ['ikeid' => $ike, 'enabled' => true, 'iketype' => 'ikev2', 'interface' => 'wan', 'remote_gateway' => $gw, 'auth_method' => 'psk',
    'my_id' => $myid, 'peer_id' => '', 'mobile' => false, 'has_psk' => true, 'description' => "tun$ike"];
$p2 = fn(string $ike, string $l, string $r) => ['ikeid' => $ike, 'uniqid' => "u$ike", 'reqid' => $ike, 'enabled' => true, 'mode' => 'tunnel',
    'local' => ['type' => 'network', 'address' => explode('/', $l)[0], 'netbits' => explode('/', $l)[1], 'cidr' => $l],
    'remote' => ['type' => 'network', 'address' => explode('/', $r)[0], 'netbits' => explode('/', $r)[1], 'cidr' => $r], 'nat_local' => null, 'description' => ''];
$ipcfg = fn(string $wan, string $lan, string $gw, array $phase2) => ['interfaces' => ['wan' => ifc('em0', $wan, 24, 'WAN'), 'lan' => ifc('em1', $lan, 24, 'LAN')],
    'vpn' => ['wireguard' => ['instances' => [], 'peers' => []], 'ipsec' => ['phase1' => [$p1('1', $gw)], 'phase2' => $phase2, 'connections' => []], 'openvpn' => ['servers' => [], 'clients' => []]]];
$ia = mkdev(401, 'ipsec-a', $ipcfg('198.51.100.10', '10.1.0.1', '198.51.100.20', [$p2('1', '10.1.0.0/24', '10.2.0.0/24')]), ['wan_ip' => '198.51.100.10', 'platform' => 'pfsense']);
$ib = mkdev(402, 'ipsec-b', $ipcfg('198.51.100.20', '10.2.0.1', '198.51.100.10', [$p2('1', '10.2.0.0/24', '10.1.0.0/24')]), ['wan_ip' => '198.51.100.20', 'platform' => 'pfsense']);
$ti = vpn_build_topology([$ia, $ib]);
T::eq(1, count(array_filter($ti['tunnels'], fn($x) => $x['b'] !== null)), 'mirrored phase 2 plus mirrored gateways pair the IPsec tunnel');
T::eq([], array_values(array_filter(vpn_diagnose($ti, [$ia, $ib]), fn($f) => $f['type'] === 'ipsec_selector_mismatch')), 'mirrored selectors raise nothing');
$ibBad = mkdev(402, 'ipsec-b', $ipcfg('198.51.100.20', '10.2.0.1', '198.51.100.10', [$p2('1', '10.2.0.0/24', '10.9.0.0/24')]), ['wan_ip' => '198.51.100.20', 'platform' => 'pfsense']);
$tb = vpn_build_topology([$ia, $ibBad]);
T::eq(1, count(array_filter($tb['tunnels'], fn($x) => $x['b'] !== null)), 'a selector mismatch still pairs on the mirrored gateways');
$sm = array_values(array_filter(vpn_diagnose($tb, [$ia, $ibBad]), fn($f) => $f['type'] === 'ipsec_selector_mismatch'));
T::eq(1, count($sm), 'a mismatched phase 2 selector is reported once');
T::eq('critical', $sm[0]['severity'] ?? '', 'as critical');
T::ok(str_contains(implode(' ', $sm[0]['evidence'] ?? []), '10.9.0.0/24'), 'with both sides\' selectors as evidence');
// Shared gateway IP: two B-side firewalls, only the one with mirrored selectors pairs.
$ib2 = mkdev(403, 'ipsec-b2', $ipcfg('198.51.100.20', '10.3.0.1', '198.51.100.10', [$p2('1', '10.3.0.0/24', '10.1.0.0/24')]), ['wan_ip' => '198.51.100.20', 'platform' => 'pfsense']);
$ts = vpn_build_topology([$ia, $ib, $ib2]);
$ab = array_values(array_filter($ts['tunnels'], fn($x) => $x['b'] !== null));
T::ok(count($ab) <= 1, 'two IPsec peers with one shared address are never both merged');

// ===========================================================================
T::group('Other findings: routes, NAT, policy, MTU, one-sided');

$ceR = $ce; $ceR['config']['nat']['outbound']['rules'][] = ['order' => 1, 'enabled' => true, 'interface' => 'opt2', 'source' => ['net' => '10.20.0.0/24'], 'destination' => ['net' => 'any'], 'target' => null, 'nonat' => false, 'description' => 'masq tunnel'];
$f = vpn_diagnose(vpn_build_topology([$ceR]), [$ceR]);
T::ok(in_array('nat_interference', ftypes($f), true), 'outbound NAT on the tunnel interface without a no-NAT rule is flagged');
T::ok(!in_array('nat_interference', ftypes(vpn_diagnose(vpn_build_topology([$ce]), [$ce])), true), 'the fixture\'s own no-NAT rule is not mistaken for interference');
$ceN = $ceR; array_unshift($ceN['config']['nat']['outbound']['rules'], ['order' => 0, 'enabled' => true, 'interface' => 'opt2', 'source' => ['net' => 'any'], 'destination' => ['net' => 'any'], 'nonat' => true, 'description' => 'nonat']);
T::ok(!in_array('nat_interference', ftypes(vpn_diagnose(vpn_build_topology([$ceN]), [$ceN])), true), 'an earlier no-NAT rule on the tunnel interface clears it');

$ceRt = $ce; $ceRt['config']['routes'][] = ['network' => '10.10.0.0/24', 'gateway' => 'WANGW', 'enabled' => true, 'description' => 'oops'];
$f = vpn_diagnose(vpn_build_topology([$ceRt]), [$ceRt]);
T::ok(in_array('route_conflict', ftypes($f), true), 'a static route sending a tunnel network out the WAN is a route conflict');
T::ok(!in_array('route_conflict', ftypes(vpn_diagnose(vpn_build_topology([$ce]), [$ce])), true), 'the fixture\'s route via the tunnel gateway is consistent');
$ceRn = $ce; $ceRn['config']['routes'][] = ['network' => '10.55.0.0/24', 'gateway' => 'WG_HQ', 'enabled' => true, 'description' => 'x'];
T::ok(in_array('route_not_in_allowedips', ftypes(vpn_diagnose(vpn_build_topology([$ceRn]), [$ceRn])), true), 'a route into WireGuard for a network no peer allows is flagged');

$ceP = $ce; $ceP['config']['filter_rules'] = [];
$ceP['config']['filter_rules'][] = ['id' => 'r1', 'section' => 'legacy', 'order' => 0, 'action' => 'pass', 'enabled' => true, 'quick' => true, 'interface' => ['lan'], 'direction' => 'in', 'ipprotocol' => 'inet', 'protocol' => 'any', 'source' => ['net' => 'any'], 'destination' => ['net' => 'any'], 'floating' => false];
$f = vpn_diagnose(vpn_build_topology([$ceP]), [$ceP]);
T::ok((bool)array_filter($f, fn($x) => $x['type'] === 'policy' && str_contains($x['title'], 'opt2')), 'rules exist but none on the tunnel interface: reported');
$ev = vpn_policy_eval($ceP['config'], ['opt2'], '10.10.0.5', '10.20.0.5', 'tcp', 443);
T::eq('default_deny', $ev['verdict'], 'policy evaluation: nothing passes on the tunnel interface');
$ev = vpn_policy_eval($ceP['config'], ['lan'], '10.20.0.5', '10.10.0.5', 'tcp', 443);
T::eq('pass', $ev['verdict'], 'policy evaluation: the LAN pass rule matches');
$ceB = $ceP; array_unshift($ceB['config']['filter_rules'], ['id' => 'b1', 'section' => 'legacy', 'order' => 0, 'action' => 'block', 'enabled' => true, 'quick' => true, 'interface' => ['lan'], 'direction' => 'in', 'ipprotocol' => 'inet', 'protocol' => 'tcp', 'source' => ['net' => 'lan'], 'destination' => ['net' => '10.10.0.0/24', 'port' => '443'], 'floating' => false, 'description' => 'no https to hq']);
$ev = vpn_policy_eval($ceB['config'], ['lan'], '10.20.0.5', '10.10.0.5', 'tcp', 443);
T::eq('block', $ev['verdict'], 'policy evaluation: a specific block rule wins on 443');
$ev = vpn_policy_eval($ceB['config'], ['lan'], '10.20.0.5', '10.10.0.5', 'tcp', 22);
T::eq('pass', $ev['verdict'], 'and does not match port 22');
$ceA = $ceP; $ceA['config']['filter_rules'][0]['destination'] = ['net' => 'HQ_ALIAS'];
T::ok(vpn_policy_eval($ceA['config'], ['lan'], '10.20.0.5', '10.10.0.5', 'tcp', 22)['uncertain'], 'an unresolved alias makes the answer uncertain instead of assumed');

$hqM = mkdev(101, 'hq-mtu', wgcfg('198.51.100.5', '10.10.0.1', 24, $K_HQ, ['10.99.0.1/30'], [wgpeer('Branch', $K_BR, ['10.99.0.2/32', '10.20.0.0/24'])], '51820', '1500'), ['wan_ip' => '198.51.100.5']);
$mt = array_values(array_filter(vpn_diagnose(vpn_build_topology([$hqM]), [$hqM]), fn($x) => $x['type'] === 'mtu_mss'));
T::eq(1, count($mt), 'a WireGuard MTU above 1420 raises a possible-MTU note');
T::eq('low', $mt[0]['confidence'] ?? '', 'at low confidence, requiring confirmation');
T::eq('inferred', $mt[0]['basis'] ?? '', 'labelled inferred');

// One-sided establishment.
$hqOne = mkdev(101, 'hq-opn', wgcfg('198.51.100.5', '10.10.0.1', 24, $K_HQ, ['10.99.0.1/30'], [wgpeer('Other', 'KOtherAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', ['10.99.0.9/32'])]), ['wan_ip' => '198.51.100.5']);
$f = vpn_diagnose(vpn_build_topology([$hqOne, $ce]), [$hqOne, $ce]);
T::ok((bool)array_filter($f, fn($x) => $x['type'] === 'one_sided'), 'a peer whose key the far side does not carry is a one-sided configuration');
$hqU = $hq; $hqU['telemetry_at'] = $ago(20);
$hqU['runtime'] = [['vpn_type' => 'wireguard', 'name' => 'wg0:Branch', 'peer' => $K_BR, 'status' => 'up', 'latest_handshake' => $ago(10), 'rx_bytes' => 10, 'tx_bytes' => 10, 'updated_at' => $ago(20)]];
$ceD = $ce; $ceD['telemetry_at'] = $ago(20);
$ceD['runtime'] = [['vpn_type' => 'wireguard', 'name' => 'tun_wg0:HQ', 'peer' => $K_HQ, 'status' => 'down', 'latest_handshake' => null, 'rx_bytes' => 0, 'tx_bytes' => 0, 'updated_at' => $ago(20)]];
$tt = vpn_build_topology([$hqU, $ceD], [], ['now' => $NOW]);
T::eq('degraded', array_values(array_filter($tt['tunnels'], fn($x) => $x['b'] !== null))[0]['state'] ?? '', 'up on one end and down on the other combines to degraded');
T::ok(in_array('one_sided', ftypes(vpn_diagnose($tt, [$hqU, $ceD])), true), 'and is explained as established on one side only');

// ===========================================================================
T::group('No secrets, scoping, preferences');

$all = vpn_build_topology([$hq, $ce, $plus, $edge, $home]);
$dump = json_encode([$all, vpn_graph($all), vpn_diagnose($all, [$hq, $ce, $plus, $edge, $home]), $ce['config']]);
T::ok(!str_contains($dump, 'MUST-NEVER-APPEAR'), 'no marker secret from the fixtures reaches topology, graph, findings or the cached slice');
T::ok(!preg_match('/privatekey|presharedkey|pre-shared-key|"privkey"|"psk"\s*:\s*"/i', $dump), 'no private-key or PSK fields exist in the output');
T::ok(str_contains($dump, '"has_psk"'), 'only the boolean has_psk is kept');
$scrub = vpn_scrub(['privatekey' => 'x', 'psk' => 'y', 'has_psk' => true, 'a' => ['password' => 'p', 'ok' => 1], 's' => 'FIXTURE-MUST-NEVER-APPEAR']);
T::eq(['has_psk' => true, 'a' => ['ok' => 1], 's' => '[redacted]'], $scrub, 'vpn_scrub drops secret-shaped keys and marker values');

$fnd = vpn_diagnose($all, [$hq, $ce, $plus, $edge, $home]);
$payload = json_encode(vpn_payload($all, $fnd), JSON_INVALID_UTF8_SUBSTITUTE);
T::ok($payload !== false && strlen($payload) > 1000, 'the browser payload builds and encodes');
T::ok(!str_contains($payload, 'MUST-NEVER-APPEAR') && !preg_match('/privatekey|presharedkey/i', $payload), 'and carries no secrets (public keys appear only as endpoint ids and abbreviated in detail)');
$sc = vpn_scope_filter($all, $fnd, [102]);
T::ok(!str_contains(json_encode($sc['topology']), 'hq-opn'), 'a scoped user does not see the name of a firewall outside their scope');
T::ok(count($sc['topology']['devices']) === 1, 'scoped devices are limited');
$sc2 = vpn_scope_filter(vpn_build_topology([$hq, $ce]), [], [102]);
$t2 = array_values(array_filter($sc2['topology']['tunnels'], fn($x) => $x['protocol'] === 'wireguard'))[0];
T::ok($t2['b'] === null && $t2['kind'] === 'external', 'a tunnel to an out-of-scope firewall is shown as an external peer');
$sc3 = vpn_scope_filter($all, $fnd, null);
T::eq(count($fnd), count($sc3['findings']), 'an unrestricted user sees every finding');

$r = vpn_prefs_save(987654, ['view' => 'table', 'positions' => ['fw1' => ['x' => 10.5, 'y' => 20], 'bad id!' => ['x' => 1, 'y' => 1], 'fw2' => ['x' => 'NaN', 'y' => 1]],
    'filters' => ['protocol' => 'wireguard', 'evil' => 'x', 'q' => str_repeat('a', 500)]]);
T::ok($r['ok'], 'layout preferences save per user');
$g = vpn_prefs_get(987654);
T::eq('table', $g['view'], 'and read back from the server');
T::eq(['fw1'], array_keys($g['positions']), 'invalid node ids and coordinates are dropped');
T::eq(['protocol', 'q'], array_keys($g['filters']), 'unknown filter keys are dropped');
T::eq(80, strlen($g['filters']['q']), 'values are bounded');
T::eq('map', vpn_prefs_get(111222)['view'], 'another user gets defaults');

// ===========================================================================
T::group('Endpoints and page hygiene');

foreach (['api/vpn_topology.php' => 'GET', 'api/vpn_prefs.php' => 'GET', 'api/vpn_pairing.php' => 'POST', 'api/vpn_probe.php' => 'POST'] as $ep => $m) {
    $r = call_endpoint($ep, ['method' => $m]);
    T::eq(401, $r['status'], "$ep refuses an unauthenticated caller");
}
foreach (['vpn_topology.php', 'vpn_diagnostics.php'] as $pg) {
    T::eq(302, call_endpoint($pg, ['method' => 'GET'])['status'], "$pg redirects an unauthenticated visitor to log in");
}
foreach (['api/vpn_pairing.php', 'api/vpn_probe.php', 'api/vpn_prefs.php'] as $ep) {
    T::ok(str_contains(file_get_contents(TEST_ROOT . '/' . $ep), 'csrf_verify('), "$ep verifies a CSRF token on writes");
}
T::ok(str_contains(file_get_contents(TEST_ROOT . '/api/vpn_pairing.php'), "require_permission('vpn.manage')"), 'pairing needs vpn.manage');
T::ok(str_contains(file_get_contents(TEST_ROOT . '/api/vpn_probe.php'), "require_permission('vpn.diagnose')"), 'probing needs vpn.diagnose');
T::ok(!preg_match('/innerHTML|outerHTML|document\.write|insertAdjacentHTML/', file_get_contents(TEST_ROOT . '/assets/js/vpn_topology.js')), 'the map script never inserts markup from data');
T::ok(!preg_match('/#[0-9a-fA-F]{3,6}\b|rgb\(/', file_get_contents(TEST_ROOT . '/assets/css/vpn_topology.css')), 'the stylesheet hardcodes no colours');
T::ok(!preg_match('~https?://(?!cdn\.jsdelivr\.net|cdnjs\.cloudflare\.com|www\.w3\.org)~', file_get_contents(TEST_ROOT . '/assets/js/vpn_topology.js')), 'no external script origins beyond the CSP allow-list');
T::ok(!preg_match('/\b(48|51)\b\s*=>|firewall_id\s*=\s*(48|51)\b/', file_get_contents(TEST_ROOT . '/inc/vpn/probe.php')), 'no production firewall ids in the code');

exit(T::summary());
