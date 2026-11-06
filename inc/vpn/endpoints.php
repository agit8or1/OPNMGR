<?php
/**
 * VPN topology: turn one firewall's configuration slice into endpoints.
 *
 * An endpoint is one managed firewall's side of one tunnel (a WireGuard peer
 * entry, an IPsec phase 1/connection, an OpenVPN instance). Protocol-specific
 * detail is kept under `details`; the common fields exist so endpoints from
 * different products and protocols can be matched and drawn together.
 *
 * Nothing here queries the database or contacts a device: input is a plain
 * "device" array (see vpn_collect_device()), so the whole model is testable
 * from fixtures.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/util.php';

if (!function_exists('vpn_iface_kind')) {
    /** wan | lan | tunnel | loopback, from the normalized interface row. */
    function vpn_iface_kind(string $name, array $if): string {
        $dev = (string)($if['device'] ?? '');
        if ($name === 'lo0' || $dev === 'lo0') { return 'loopback'; }
        if (preg_match('/^(wg\d*|tun_wg\d+|ovpn[sc]\d+|tun\d+|ipsec\d*|enc\d+|wireguard|openvpn)$/', $dev)
            || in_array($name, ['wireguard', 'openvpn', 'ipsec', 'enc0'], true)) {
            return 'tunnel';
        }
        if ($name === 'wan' || preg_match('/^wan\d*$/', $name)
            || preg_match('/\bwan\b/i', (string)($if['descr'] ?? ''))) {
            return 'wan';
        }
        if (vpn_ip_is_public($if['ipv4'] ?? null)) { return 'wan'; }
        return 'lan';
    }
}

if (!function_exists('vpn_protected_networks')) {
    /**
     * Networks behind the firewall: addressed, enabled, non-WAN, non-tunnel
     * interfaces. For WireGuard and OpenVPN the configuration does not say
     * which networks the operator means to share, so this is an inference;
     * for IPsec the phase 2 selectors say it outright and are used instead.
     *
     * @return array<int,array{iface:string,network:string,address:string,descr:string}>
     */
    function vpn_protected_networks(?array $cfg): array {
        $out = [];
        foreach (($cfg['interfaces'] ?? []) as $name => $if) {
            if (empty($if['enabled']) || empty($if['network']) || !vpn_cidr($if['ipv4'] ?? '')) { continue; }
            if (vpn_iface_kind((string)$name, $if) !== 'lan') { continue; }
            $out[] = ['iface' => (string)$name, 'network' => (string)$if['network'],
                      'address' => (string)$if['ipv4'], 'descr' => (string)($if['descr'] ?? $name)];
        }
        return $out;
    }
}

if (!function_exists('vpn_device_wan_ip')) {
    function vpn_device_wan_ip(array $device): ?string {
        if (!empty($device['wan_ip']) && filter_var($device['wan_ip'], FILTER_VALIDATE_IP)) {
            return (string)$device['wan_ip'];
        }
        foreach (($device['config']['interfaces'] ?? []) as $name => $if) {
            if (vpn_iface_kind((string)$name, $if) === 'wan' && vpn_cidr($if['ipv4'] ?? '')) {
                return (string)$if['ipv4'];
            }
        }
        return null;
    }
}

if (!function_exists('vpn_device_addresses')) {
    /** Every IPv4 address the device is known to own. */
    function vpn_device_addresses(array $device): array {
        $a = [];
        if (($w = vpn_device_wan_ip($device))) { $a[$w] = true; }
        foreach (($device['config']['interfaces'] ?? []) as $if) {
            if (vpn_cidr($if['ipv4'] ?? '')) { $a[(string)$if['ipv4']] = true; }
        }
        return array_keys($a);
    }
}

if (!function_exists('vpn_tunnel_ifaces_for')) {
    /** Logical interface names (rule/NAT interface values) a tunnel's traffic uses. */
    function vpn_tunnel_ifaces_for(array $cfg, string $protocol, array $hint = []): array {
        $names = [];
        foreach (($cfg['interfaces'] ?? []) as $name => $if) {
            $dev = (string)($if['device'] ?? '');
            foreach ($hint['devices'] ?? [] as $d) {
                if ($d !== '' && $dev === $d) { $names[$name] = true; }
            }
        }
        $groups = ['wireguard' => ['wireguard'], 'ipsec' => ['ipsec', 'enc0'], 'openvpn' => ['openvpn']];
        foreach ($groups[$protocol] ?? [] as $g) {
            // pfSense and OPNsense both evaluate IPsec/OpenVPN/WireGuard
            // group rules under these names whether or not they are listed
            // as <interfaces> entries.
            $names[$g] = true;
        }
        return array_keys($names);
    }
}

if (!function_exists('vpn_wg_endpoints')) {
    function vpn_wg_endpoints(array $device): array {
        $cfg = $device['config'];
        $wg  = $cfg['vpn']['wireguard'] ?? ['instances' => [], 'peers' => []];
        $instances = $wg['instances'] ?? [];
        $out = [];
        $lans = vpn_protected_networks($cfg);

        foreach (($wg['peers'] ?? []) as $p) {
            $inst = null;
            foreach ($instances as $i) {
                if ($p['instance'] !== null && in_array((string)$p['instance'],
                        [(string)($i['interface'] ?? ''), (string)($i['id'] ?? ''), (string)($i['name'] ?? '')], true)) {
                    $inst = $i; break;
                }
            }
            if ($inst === null && count($instances) === 1) { $inst = $instances[0]; }

            $tunNets = []; $tunIps = [];
            foreach (($inst['addresses'] ?? []) as $a) {
                $c = vpn_cidr($a);
                if ($c) { $tunNets[] = $c['text']; $tunIps[] = long2ip($c['ip']); }
            }
            $segments = $tunNets;
            foreach ($tunIps as $ip) { $segments[] = preg_replace('/\.\d+$/', '.0', $ip) . '/24'; }

            $allowed = vpn_norm_list($p['allowed_ips'] ?? []);
            $peerIps = []; $remote = [];
            foreach ($allowed as $n) {
                $c = vpn_cidr($n);
                if ($c['bits'] === 32 && vpn_cidr_list_covers($segments, $n)) { $peerIps[] = $n; }
                else { $remote[] = $n; }
            }
            $netsInRemote = array_filter($remote, fn($n) => vpn_cidr($n)['bits'] < 32);

            $hasEndpoint = trim((string)($p['endpoint'] ?? '')) !== '';
            if ($netsInRemote) {
                $role = 'site_to_site'; $why = 'AllowedIPs include a network, not just host addresses';
            } elseif (!$hasEndpoint) {
                $role = 'remote_access'; $why = 'host-only AllowedIPs and no configured endpoint: a roaming client';
            } else {
                $role = 'unknown'; $why = 'has an endpoint but host-only AllowedIPs: could be a fixed host or a site link';
            }

            $pk = (string)($p['public_key'] ?? '');
            $iface = $inst['interface'] ?? ($p['instance'] ?? null);
            [$host, $port] = vpn_split_hostport($p['endpoint'] ?? null, $p['endpoint_port'] ?? null);
            if (($p['endpoint_port'] ?? '') !== '' && $port === null) { $port = $p['endpoint_port']; }
            $out[] = [
                'key' => 'fw' . $device['id'] . '|wireguard|' . ($pk !== '' ? $pk : ($p['id'] ?? $p['name'] ?? '?')),
                'firewall_id' => $device['id'], 'protocol' => 'wireguard', 'kind' => 'wg_peer',
                'name' => ($p['name'] ?? '') !== '' ? $p['name'] : ($inst['name'] ?? 'wireguard peer'),
                'enabled' => !empty($p['enabled']) && ($inst === null || !empty($inst['enabled'])),
                'role' => $role, 'role_reason' => $why, 'role_source' => 'inferred',
                'instance' => $iface,
                'tunnel_ifaces' => vpn_tunnel_ifaces_for($cfg, 'wireguard', ['devices' => array_filter([$iface])]),
                'tunnel_nets' => $tunNets, 'tunnel_ips' => $tunIps,
                'local_networks' => vpn_norm_list(array_column($lans, 'network')),
                'local_networks_basis' => 'inferred',
                'remote_networks' => vpn_norm_list($remote), 'allowed_ips' => $allowed, 'peer_tunnel_ips' => $peerIps,
                'local_ids' => ['public_key' => $inst['public_key'] ?? null, 'port' => $inst['listen_port'] ?? null],
                'remote_ids' => ['public_key' => $pk !== '' ? $pk : null, 'host' => $host, 'port' => $port],
                'selectors' => [],
                'details' => ['keepalive' => $p['keepalive'] ?? null, 'has_psk' => !empty($p['has_psk']),
                              'mtu' => $inst['mtu'] ?? null, 'listen_port' => $inst['listen_port'] ?? null,
                              'disable_routes' => !empty($inst['disable_routes']),
                              'instance_name' => $inst['name'] ?? null],
                'config_present' => true,
            ];
        }
        return $out;
    }
}

if (!function_exists('vpn_ipsec_endpoints')) {
    function vpn_ipsec_endpoints(array $device): array {
        $cfg = $device['config'];
        $ip  = $cfg['vpn']['ipsec'] ?? [];
        $out = [];
        $ifaces = $cfg['interfaces'] ?? [];

        foreach (($ip['phase1'] ?? []) as $p1) {
            $sel = []; $loc = []; $rem = [];
            foreach (($ip['phase2'] ?? []) as $p2) {
                if ((string)$p2['ikeid'] !== (string)$p1['ikeid']) { continue; }
                $l = $p2['local']['cidr'] ?? null; $r = $p2['remote']['cidr'] ?? null;
                // Type "lan"/"wan" selectors name an interface, not a network.
                if ($l === null) {
                    $t = $p2['local']['type'] ?? null;
                    if ($t && isset($ifaces[$t]['network'])) { $l = $ifaces[$t]['network']; }
                }
                if ($r === null) {
                    $t = $p2['remote']['type'] ?? null;
                    if ($t && isset($ifaces[$t]['network'])) { $r = $ifaces[$t]['network']; }
                }
                $l = vpn_cidr_norm($l); $r = vpn_cidr_norm($r);
                $sel[] = ['local' => $l ? [$l] : [], 'remote' => $r ? [$r] : [],
                          'nat_local' => $p2['nat_local']['cidr'] ?? null, 'enabled' => !empty($p2['enabled']),
                          'mode' => $p2['mode'] ?? null, 'description' => $p2['description'] ?? null];
                if ($l) { $loc[] = $l; } if ($r) { $rem[] = $r; }
            }
            $rg = $p1['remote_gateway'] ?? null;
            $mobile = !empty($p1['mobile']) || $rg === null || $rg === '' || $rg === '%any';
            $wanIf = $p1['interface'] ?? null;
            $localAddr = ($wanIf && isset($ifaces[$wanIf]['ipv4']) && vpn_cidr($ifaces[$wanIf]['ipv4']))
                ? $ifaces[$wanIf]['ipv4'] : vpn_device_wan_ip($device);
            $out[] = [
                'key' => 'fw' . $device['id'] . '|ipsec|p1-' . $p1['ikeid'],
                'firewall_id' => $device['id'], 'protocol' => 'ipsec', 'kind' => 'ipsec_p1',
                'name' => ($p1['description'] ?? '') !== '' ? $p1['description'] : 'IPsec ' . $p1['ikeid'],
                'enabled' => !empty($p1['enabled']),
                'role' => $mobile ? 'remote_access' : 'site_to_site',
                'role_reason' => $mobile ? 'mobile / no fixed remote gateway' : 'fixed remote gateway with phase 2 selectors',
                'role_source' => 'config',
                'instance' => 'ikeid ' . $p1['ikeid'],
                'tunnel_ifaces' => vpn_tunnel_ifaces_for($cfg, 'ipsec'),
                'tunnel_nets' => [], 'tunnel_ips' => [],
                'local_networks' => vpn_norm_list($loc), 'local_networks_basis' => 'configured',
                'remote_networks' => vpn_norm_list($rem), 'allowed_ips' => [], 'peer_tunnel_ips' => [],
                'local_ids' => ['ids' => array_filter([$p1['my_id'] ?? '']), 'address' => $localAddr],
                'remote_ids' => ['host' => $mobile ? null : $rg, 'gateway' => $mobile ? null : $rg,
                                 'ids' => array_filter([$p1['peer_id'] ?? ''])],
                'selectors' => $sel,
                'details' => ['ikeid' => $p1['ikeid'], 'iketype' => $p1['iketype'] ?? null,
                              'auth_method' => $p1['auth_method'] ?? null, 'has_psk' => !empty($p1['has_psk']),
                              'interface' => $wanIf, 'description' => $p1['description'] ?? null],
                'config_present' => true,
            ];
        }

        foreach (($ip['connections'] ?? []) as $c) {
            $sel = []; $loc = []; $rem = [];
            foreach (($c['children'] ?? []) as $ch) {
                if (empty($ch['enabled'])) { continue; }
                $l = vpn_norm_list($ch['local_ts'] ?? []); $r = vpn_norm_list($ch['remote_ts'] ?? []);
                $sel[] = ['local' => $l, 'remote' => $r, 'nat_local' => null, 'enabled' => true,
                          'mode' => $ch['mode'] ?? null, 'description' => $ch['description'] ?? null];
                $loc = array_merge($loc, $l); $rem = array_merge($rem, $r);
            }
            $rg = $c['remote_addrs'][0] ?? null;
            $mobile = $rg === null || $rg === '' || $rg === '%any' || str_contains((string)$rg, '/');
            $out[] = [
                'key' => 'fw' . $device['id'] . '|ipsec|' . $c['id'],
                'firewall_id' => $device['id'], 'protocol' => 'ipsec', 'kind' => 'ipsec_conn',
                'name' => ($c['description'] ?? '') !== '' ? $c['description'] : 'IPsec ' . substr((string)$c['id'], 0, 8),
                'enabled' => !empty($c['enabled']),
                'role' => $mobile ? 'remote_access' : 'site_to_site',
                'role_reason' => $mobile ? 'no fixed remote address' : 'fixed remote address with child selectors',
                'role_source' => 'config',
                'instance' => $c['id'],
                'tunnel_ifaces' => vpn_tunnel_ifaces_for($cfg, 'ipsec'),
                'tunnel_nets' => [], 'tunnel_ips' => [],
                'local_networks' => vpn_norm_list($loc), 'local_networks_basis' => 'configured',
                'remote_networks' => vpn_norm_list($rem), 'allowed_ips' => [], 'peer_tunnel_ips' => [],
                'local_ids' => ['ids' => array_values(array_filter($c['local_ids'] ?? [])),
                                'address' => $c['local_addrs'][0] ?? vpn_device_wan_ip($device)],
                'remote_ids' => ['host' => $mobile ? null : $rg, 'gateway' => $mobile ? null : $rg, 'ids' => []],
                'selectors' => $sel,
                'details' => ['version' => $c['version'] ?? null, 'connection' => $c['id'], 'description' => $c['description'] ?? null],
                'config_present' => true,
            ];
        }
        return $out;
    }
}

if (!function_exists('vpn_openvpn_endpoints')) {
    function vpn_openvpn_endpoints(array $device): array {
        $cfg = $device['config'];
        $ov  = $cfg['vpn']['openvpn'] ?? ['servers' => [], 'clients' => []];
        $out = [];
        $lans = vpn_protected_networks($cfg);
        foreach (['servers' => 'server', 'clients' => 'client'] as $listKey => $role) {
            foreach (($ov[$listKey] ?? []) as $o) {
                $id = ($o['vpnid'] ?? '') !== '' ? $o['vpnid'] : ($o['id'] ?? '?');
                $mode = (string)($o['mode'] ?? '');
                $remoteNets = vpn_norm_list($o['remote_networks'] ?? []);
                if ($role === 'client') {
                    $r = 'site_to_site'; $why = 'OpenVPN client instance connecting to a fixed server';
                } elseif (str_starts_with($mode, 'p2p') || $remoteNets) {
                    $r = 'site_to_site'; $why = 'peer-to-peer mode or remote networks configured';
                } else {
                    $r = 'remote_access'; $why = 'OpenVPN server accepting user clients';
                }
                $localNets = vpn_norm_list($o['local_networks'] ?? []);
                $basis = 'configured';
                if (!$localNets) { $localNets = vpn_norm_list(array_column($lans, 'network')); $basis = 'inferred'; }
                [$rh, $rp] = vpn_split_hostport($o['remote'][0] ?? null, $o['port'] ?? null);
                $out[] = [
                    'key' => 'fw' . $device['id'] . '|openvpn|' . $role . '-' . $id,
                    'firewall_id' => $device['id'], 'protocol' => 'openvpn', 'kind' => 'ovpn_' . $role,
                    'name' => ($o['description'] ?? '') !== '' ? $o['description'] : 'OpenVPN ' . $role . ' ' . $id,
                    'enabled' => !empty($o['enabled']),
                    'role' => $r, 'role_reason' => $why, 'role_source' => 'config',
                    'instance' => ($role === 'server' ? 'ovpns' : 'ovpnc') . $id,
                    'tunnel_ifaces' => vpn_tunnel_ifaces_for($cfg, 'openvpn',
                        ['devices' => [($role === 'server' ? 'ovpns' : 'ovpnc') . $id]]),
                    'tunnel_nets' => vpn_norm_list([$o['tunnel_network'] ?? '']), 'tunnel_ips' => [],
                    'local_networks' => $localNets, 'local_networks_basis' => $basis,
                    'remote_networks' => $remoteNets, 'allowed_ips' => [], 'peer_tunnel_ips' => [],
                    'local_ids' => ['port' => $role === 'server' ? ($o['port'] ?? null) : null,
                                    'address' => $role === 'server' ? vpn_device_wan_ip($device) : null],
                    'remote_ids' => ['host' => $role === 'client' ? $rh : null, 'port' => $role === 'client' ? $rp : null],
                    'selectors' => [],
                    'details' => ['mode' => $mode, 'protocol' => $o['protocol'] ?? null, 'port' => $o['port'] ?? null,
                                  'tunnel_network' => $o['tunnel_network'] ?? null, 'vpnid' => $id],
                    'config_present' => true,
                ];
            }
        }
        return $out;
    }
}

if (!function_exists('vpn_match_runtime')) {
    /**
     * The telemetry row describing an endpoint, or null. Matching is by the
     * identifiers the agent reports: for WireGuard the peer's public key (or
     * a fingerprint prefix of it) and the peer name; for IPsec and OpenVPN the
     * connection description or id. A row that matches nothing is surfaced by
     * the caller as a runtime-only endpoint rather than dropped.
     */
    function vpn_match_runtime(array $ep, array $rows, array $taken = []): ?array {
        foreach ($rows as $i => $r) {
            if (isset($taken[$i]) || $r['vpn_type'] !== $ep['protocol']) { continue; }
            $name = strtolower((string)$r['name']);
            if ($ep['protocol'] === 'wireguard') {
                $pk = (string)($ep['remote_ids']['public_key'] ?? '');
                $peer = (string)($r['peer'] ?? '');
                if ($pk !== '' && $peer !== '' && ($peer === $pk || (strlen($peer) >= 8 && str_starts_with($pk, $peer)))) { return ['i' => $i, 'row' => $r]; }
                $tail = strtolower(substr(strrchr(':' . $r['name'], ':'), 1));
                if ($ep['name'] !== '' && $tail === strtolower($ep['name'])) { return ['i' => $i, 'row' => $r]; }
                continue;
            }
            $cands = [];
            foreach ([$ep['details']['description'] ?? null, $ep['name'], $ep['details']['connection'] ?? null,
                      isset($ep['details']['ikeid']) ? 'con' . $ep['details']['ikeid'] : null,
                      $ep['details']['ikeid'] ?? null,
                      isset($ep['details']['vpnid']) ? 'server' . $ep['details']['vpnid'] : null,
                      isset($ep['details']['vpnid']) ? 'ovpns' . $ep['details']['vpnid'] : null,
                      isset($ep['details']['vpnid']) ? 'ovpnc' . $ep['details']['vpnid'] : null] as $c) {
                if ($c !== null && $c !== '') { $cands[] = strtolower((string)$c); }
            }
            foreach ($cands as $c) {
                if ($name === $c || (strlen($c) >= 4 && str_contains($name, $c))
                    || (ctype_digit($c) && preg_match('/(^|[^0-9])0*' . $c . '([^0-9]|$)/', $name))) {
                    return ['i' => $i, 'row' => $r];
                }
            }
        }
        return null;
    }
}

if (!function_exists('vpn_build_endpoints')) {
    /**
     * All endpoints of one device: configured ones with their telemetry
     * attached, plus runtime-only ones for telemetry rows with no
     * configuration (flagged as such - the configuration snapshot may simply
     * be older than the tunnel).
     */
    function vpn_build_endpoints(array $device): array {
        $eps = [];
        if (!empty($device['config'])) {
            $eps = array_merge(vpn_wg_endpoints($device), vpn_ipsec_endpoints($device), vpn_openvpn_endpoints($device));
        }
        $rows = $device['runtime'] ?? [];
        $taken = [];
        foreach ($eps as &$ep) {
            $m = vpn_match_runtime($ep, $rows, $taken);
            $ep['runtime'] = $m['row'] ?? null;
            if ($m) { $taken[$m['i']] = true; }
            $act = $device['activity'] ?? [];
            $ep['activity'] = $m ? ($act[$m['row']['vpn_type'] . '|' . $m['row']['name']] ?? null) : null;
            $ep['firewall_name'] = $device['hostname'] ?? ('#' . $device['id']);
            $ep['source'] = $m ? 'config_snapshot+runtime_telemetry' : (empty($device['config']) ? 'none' : 'config_snapshot');
        }
        unset($ep);
        foreach ($rows as $i => $r) {
            if (isset($taken[$i])) { continue; }
            [$h, $p] = vpn_split_hostport($r['endpoint'] ?? null);
            $act = $device['activity'] ?? [];
            $eps[] = [
                'key' => 'fw' . $device['id'] . '|' . $r['vpn_type'] . '|rt-' . $r['name'],
                'firewall_id' => $device['id'], 'firewall_name' => $device['hostname'] ?? ('#' . $device['id']),
                'protocol' => $r['vpn_type'], 'kind' => 'runtime_only', 'name' => (string)$r['name'],
                'enabled' => !isset($r['enabled']) || (int)$r['enabled'] === 1,
                'role' => 'unknown', 'role_reason' => 'seen in telemetry only; not in the latest configuration snapshot',
                'role_source' => 'inferred', 'instance' => null, 'tunnel_ifaces' => [], 'tunnel_nets' => [], 'tunnel_ips' => [],
                'local_networks' => [], 'local_networks_basis' => 'none', 'remote_networks' => [], 'allowed_ips' => [],
                'peer_tunnel_ips' => [], 'local_ids' => [],
                'remote_ids' => ['host' => $h, 'port' => $p,
                                 'public_key' => $r['vpn_type'] === 'wireguard' ? ($r['peer'] ?? null) : null],
                'selectors' => [], 'details' => [], 'config_present' => false,
                'runtime' => $r, 'activity' => $act[$r['vpn_type'] . '|' . $r['name']] ?? null,
                'source' => 'runtime_telemetry',
            ];
        }
        // Keys must be unique per device: duplicate or redacted public keys
        // (sanitized exports, or a peer entered twice) would otherwise collapse
        // distinct endpoints into one.
        $seen = [];
        foreach ($eps as &$e) {
            $n = $seen[$e['key']] = ($seen[$e['key']] ?? 0) + 1;
            if ($n > 1) { $e['key'] .= '#' . $n; }
        }
        unset($e);
        return $eps;
    }
}
