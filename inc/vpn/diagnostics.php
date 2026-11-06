<?php
/**
 * VPN topology: findings.
 *
 * Every finding carries what a technician needs to judge it without trusting
 * it blindly:
 *
 *   evidence      the configuration values or telemetry that led here
 *   freshness     when the underlying config snapshot / telemetry was taken
 *   affected      firewalls and tunnels involved
 *   basis         observed (telemetry), configured (read straight from config)
 *                 or inferred (a conclusion drawn from them)
 *   confidence    high | medium | low
 *   limitation    what this check cannot see
 *   next_step     the next diagnostic to run
 *
 * Checks are deliberately conservative. A repeated RFC1918 subnet is not a
 * finding by itself: two customers who never connect legitimately share
 * 192.168.1.0/24. Overlap is reported only inside a routing domain - firewalls
 * joined by a configured tunnel - and is softened when NAT is configured.
 *
 * Nothing here changes a firewall or proposes a rewrite of a tunnel.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/topology.php';
require_once __DIR__ . '/policy.php';

if (!function_exists('vpn_finding')) {
    function vpn_finding(array $f): array {
        $f += ['severity' => 'warning', 'evidence' => [], 'freshness' => [], 'affected' => ['firewalls' => [], 'tunnels' => []],
               'basis' => 'inferred', 'confidence' => 'medium', 'limitation' => '', 'next_step' => '', 'probe' => null, 'explanation' => ''];
        $f['affected']['firewalls'] = array_values(array_unique(array_map('intval', $f['affected']['firewalls'] ?? [])));
        $f['affected']['tunnels'] = array_values(array_unique($f['affected']['tunnels'] ?? []));
        sort($f['affected']['firewalls']);
        $f['id'] = $f['type'] . ':' . substr(sha1($f['type'] . '|' . ($f['key'] ?? $f['title'])), 0, 10);
        unset($f['key']);
        return $f;
    }
}

if (!function_exists('vpn_freshness')) {
    function vpn_freshness(array $devices, array $ids): array {
        $out = [];
        foreach (array_unique($ids) as $id) {
            $d = $devices[$id] ?? null;
            if (!$d) { continue; }
            $out[] = sprintf('%s: configuration snapshot %s; telemetry %s', $d['hostname'] ?? ('#' . $id),
                !empty($d['config_time']) ? $d['config_time'] : 'unavailable',
                !empty($d['telemetry_at']) ? $d['telemetry_at'] : 'none');
        }
        return $out;
    }
}

if (!function_exists('vpn_diag_components')) {
    /** Routing domains: firewall ids connected by a configured paired tunnel. */
    function vpn_diag_components(array $topology): array {
        $parent = [];
        $find = function ($x) use (&$parent, &$find) {
            while (($parent[$x] ?? $x) !== $x) { $x = $parent[$x]; }
            return $x;
        };
        foreach ($topology['devices'] as $d) { $parent[$d['id']] = $d['id']; }
        foreach ($topology['tunnels'] as $t) {
            if ($t['b'] === null) { continue; }
            $x = $find($t['a']['firewall_id']); $y = $find($t['b']['firewall_id']);
            if ($x !== $y) { $parent[$y] = $x; }
        }
        $comp = [];
        foreach ($parent as $id => $_) { $comp[$find($id)][] = $id; }
        return array_values($comp);
    }
}

if (!function_exists('vpn_diagnose')) {
    /**
     * @param array $topology vpn_build_topology()
     * @param array $devices  devices by id (or list)
     * @return array[] findings, most severe first
     */
    function vpn_diagnose(array $topology, array $devices, array $opts = []): array {
        $now = $opts['now'] ?? time();
        $byId = [];
        foreach ($devices as $d) { $byId[$d['id']] = $d; }
        $F = [];
        $epTunnel = [];
        foreach ($topology['tunnels'] as $t) {
            $epTunnel[$t['a']['key']] = $t['id'];
            if ($t['b']) { $epTunnel[$t['b']['key']] = $t['id']; }
        }
        $name = fn($id) => $byId[$id]['hostname'] ?? ('#' . $id);
        $cfgOf = fn($id) => $byId[$id]['config'] ?? null;

        // ---- data gaps ------------------------------------------------------
        foreach ($byId as $id => $d) {
            if (empty($d['config'])) {
                $F[] = vpn_finding(['type' => 'data_gap', 'severity' => 'info', 'key' => "nocfg$id",
                    'title' => $name($id) . ': no configuration snapshot',
                    'explanation' => 'Without a parsed backup this firewall contributes only runtime telemetry; configuration-based checks and peer matching skip it.',
                    'evidence' => [$d['config_error'] ?? 'No backup available.'], 'basis' => 'observed', 'confidence' => 'high',
                    'limitation' => 'Topology for this firewall is partial.', 'next_step' => 'Take a configuration backup from the firewall, then refresh the topology.',
                    'affected' => ['firewalls' => [$id]], 'freshness' => vpn_freshness($byId, [$id])]);
            }
        }

        // ---- subnet overlap within routing domains --------------------------
        $comps = vpn_diag_components($topology);
        foreach ($comps as $members) {
            if (count($members) < 2) { continue; }
            $nets = [];
            foreach ($members as $id) {
                foreach (vpn_protected_networks($cfgOf($id)) as $n) { $nets[] = $n + ['fw' => $id]; }
            }
            $custs = array_unique(array_filter(array_map(fn($id) => $byId[$id]['customer_id'] ?? null, $members)));
            if (count($custs) > 1) {
                $F[] = vpn_finding(['type' => 'cross_customer_domain', 'severity' => 'warning', 'key' => 'cc' . implode(',', $members),
                    'title' => 'A tunnel joins firewalls of different customers',
                    'explanation' => 'These firewalls are in one routing domain, so subnet reuse across them stops being harmless and customer separation depends entirely on tunnel policy.',
                    'evidence' => array_map(fn($id) => $name($id) . ' (customer ' . ($byId[$id]['customer_name'] ?? 'unassigned') . ')', $members),
                    'basis' => 'configured', 'confidence' => 'high', 'limitation' => 'Intentional shared-services links are possible.',
                    'next_step' => 'Confirm the link is intended and review the filter rules on the tunnel interfaces.',
                    'affected' => ['firewalls' => $members], 'freshness' => vpn_freshness($byId, $members)]);
            }
            $cnt = count($nets);
            for ($i = 0; $i < $cnt; $i++) {
                for ($j = $i + 1; $j < $cnt; $j++) {
                    $x = $nets[$i]; $y = $nets[$j];
                    if ($x['fw'] === $y['fw'] || !vpn_cidr_overlap($x['network'], $y['network'])) { continue; }
                    $nat = [];
                    foreach ([$x['fw'], $y['fw']] as $fid) {
                        foreach (($cfgOf($fid)['nat']['one_to_one'] ?? []) as $o) {
                            if (!empty($o['enabled']) && (vpn_cidr_overlap((string)($o['internal'] ?? ''), $x['network']) || vpn_cidr_overlap((string)($o['external'] ?? ''), $x['network']))) {
                                $nat[] = $name($fid) . ' has 1:1 NAT for ' . ($o['internal'] ?? '?') . ' -> ' . ($o['external'] ?? '?');
                            }
                        }
                    }
                    foreach ($topology['endpoints'] as $ep) {
                        if (in_array($ep['firewall_id'], [$x['fw'], $y['fw']], true)) {
                            foreach ($ep['selectors'] as $s) {
                                if (!empty($s['nat_local'])) { $nat[] = $name($ep['firewall_id']) . ' phase 2 translates local ' . $s['nat_local']; }
                            }
                        }
                    }
                    $F[] = vpn_finding(['type' => 'subnet_overlap', 'severity' => $nat ? 'info' : 'critical', 'key' => $x['fw'] . $x['network'] . $y['fw'] . $y['network'],
                        'title' => sprintf('Overlapping subnets in one routing domain: %s %s vs %s %s', $name($x['fw']), $x['network'], $name($y['fw']), $y['network']),
                        'explanation' => 'These firewalls are joined by tunnels, so a host in one range cannot be told apart from a host in the other when traffic is routed between sites.'
                            . ($nat ? ' NAT is configured, which may already translate one side.' : ''),
                        'evidence' => array_merge([
                            sprintf('%s interface %s: %s', $name($x['fw']), $x['iface'], $x['network']),
                            sprintf('%s interface %s: %s', $name($y['fw']), $y['iface'], $y['network']),
                            'Routing domain: ' . implode(', ', array_map($name, $members))], $nat),
                        'basis' => 'configured', 'confidence' => $nat ? 'low' : 'high',
                        'limitation' => 'Only tunnels the topology matched are treated as connections; routed links through external devices are not seen.',
                        'next_step' => 'Run a traceroute from each site to a host in the overlapping range, or plan NAT / renumbering.',
                        'affected' => ['firewalls' => [$x['fw'], $y['fw']], 'tunnels' => []],
                        'freshness' => vpn_freshness($byId, [$x['fw'], $y['fw']])]);
                }
            }
        }
        // A remote network that overlaps a local one on the SAME firewall.
        foreach ($topology['endpoints'] as $ep) {
            $cfg = $cfgOf($ep['firewall_id']);
            if (!$cfg || !$ep['config_present'] || !$ep['enabled']) { continue; }
            $local = array_column(vpn_protected_networks($cfg), 'network');
            foreach ($ep['remote_networks'] as $r) {
                foreach ($local as $l) {
                    if (!vpn_cidr_overlap($r, $l)) { continue; }
                    $nat = array_filter($ep['selectors'], fn($s) => !empty($s['nat_local']));
                    $F[] = vpn_finding(['type' => 'subnet_overlap', 'severity' => $nat ? 'info' : 'critical',
                        'key' => $ep['key'] . $r . $l,
                        'title' => sprintf('%s: tunnel "%s" routes %s, which overlaps local %s', $ep['firewall_name'], $ep['name'], $r, $l),
                        'explanation' => 'The firewall would be asked to reach the same range both locally and through the tunnel.',
                        'evidence' => [($ep['protocol'] === 'wireguard' ? 'AllowedIPs' : 'Remote network') . ' ' . $r, 'Local interface network ' . $l],
                        'basis' => 'configured', 'confidence' => $ep['local_networks_basis'] === 'configured' ? 'high' : 'medium',
                        'limitation' => 'The local network list is inferred from interface addresses for WireGuard/OpenVPN.',
                        'next_step' => 'Compare against the remote site\'s actual LAN and the firewall routing table.',
                        'affected' => ['firewalls' => [$ep['firewall_id']], 'tunnels' => array_filter([$epTunnel[$ep['key']] ?? null])],
                        'freshness' => vpn_freshness($byId, [$ep['firewall_id']])]);
                }
            }
        }

        $warned = [];
        // ---- routes ---------------------------------------------------------
        foreach ($topology['endpoints'] as $ep) {
            $cfg = $cfgOf($ep['firewall_id']);
            if (!$cfg || !$ep['config_present'] || !$ep['enabled'] || $ep['role'] === 'remote_access') { continue; }
            $gw = [];
            foreach (($cfg['gateways'] ?? []) as $g) { $gw[(string)$g['name']] = $g; }
            $routes = array_filter($cfg['routes'] ?? [], fn($r) => !empty($r['enabled']) && vpn_cidr($r['network'] ?? ''));
            $tunnelIfs = $ep['tunnel_ifaces'];
            $tid = $epTunnel[$ep['key']] ?? null;
            foreach ($ep['remote_networks'] as $n) {
                if (vpn_cidr($n)['bits'] === 32) { continue; }
                foreach ($routes as $r) {
                    if (!vpn_cidr_overlap($r['network'], $n)) { continue; }
                    $g = $gw[$r['gateway']] ?? null;
                    $ifc = $g['interface'] ?? null;
                    if ($g && $ifc && !in_array($ifc, $tunnelIfs, true) && !in_array($ifc, ['wireguard', 'ipsec', 'openvpn', 'enc0'], true)) {
                        $F[] = vpn_finding(['type' => 'route_conflict', 'severity' => 'warning', 'key' => $ep['key'] . $n . $r['network'],
                            'title' => sprintf('%s: static route for %s bypasses tunnel "%s"', $ep['firewall_name'], $r['network'], $ep['name']),
                            'explanation' => 'A static route sends traffic for a network the tunnel is meant to carry out through a different gateway, so replies or requests may leave the tunnel.',
                            'evidence' => [sprintf('Tunnel remote network %s', $n), sprintf('Static route %s via gateway %s (interface %s)', $r['network'], $r['gateway'], $ifc)],
                            'basis' => 'configured', 'confidence' => 'high',
                            'limitation' => 'Static routes only; dynamic routing (FRR/OSPF/BGP) and policy-based routing rules are not evaluated.',
                            'next_step' => 'Run a traceroute to a host in ' . $n . ' to see which path it takes.',
                            'probe' => ['method' => 'traceroute', 'target' => vpn_first_host($n)],
                            'affected' => ['firewalls' => [$ep['firewall_id']], 'tunnels' => array_filter([$tid])],
                            'freshness' => vpn_freshness($byId, [$ep['firewall_id']])]);
                    }
                }
                if ($ep['protocol'] === 'wireguard' && !empty($ep['details']['disable_routes'])) {
                    $covered = false;
                    foreach ($routes as $r) {
                        $g = $gw[$r['gateway']] ?? null;
                        if (vpn_cidr_contains($r['network'], $n) && $g && in_array($g['interface'] ?? '', $tunnelIfs, true)) { $covered = true; }
                    }
                    if (!$covered) {
                        $F[] = vpn_finding(['type' => 'route_missing', 'severity' => 'warning', 'key' => $ep['key'] . $n,
                            'title' => sprintf('%s: no route to %s through WireGuard "%s"', $ep['firewall_name'], $n, $ep['name']),
                            'explanation' => 'The WireGuard instance is set not to install routes for AllowedIPs, and no static route sends this network into the tunnel.',
                            'evidence' => ['Instance option "disable routes" is on', 'AllowedIPs contain ' . $n, 'No static route to ' . $n . ' via a tunnel gateway'],
                            'basis' => 'configured', 'confidence' => 'medium',
                            'limitation' => 'Routes added by scripts or dynamic routing are not visible in the snapshot.',
                            'next_step' => 'Check the live routing table (Interfaces > Diagnostics > Routes) for ' . $n . '.',
                            'probe' => ['method' => 'traceroute', 'target' => vpn_first_host($n)],
                            'affected' => ['firewalls' => [$ep['firewall_id']], 'tunnels' => array_filter([$tid])],
                            'freshness' => vpn_freshness($byId, [$ep['firewall_id']])]);
                    }
                }
            }
            if ($ep['protocol'] === 'wireguard') {
                // A route into the tunnel for a network that no peer on the instance allows.
                $allowed = [];
                foreach ($topology['endpoints'] as $o) {
                    if ($o['firewall_id'] === $ep['firewall_id'] && $o['protocol'] === 'wireguard' && $o['instance'] === $ep['instance']) {
                        $allowed = array_merge($allowed, $o['allowed_ips']);
                    }
                }
                foreach ($routes as $r) {
                    $g = $gw[$r['gateway']] ?? null;
                    if ($g && in_array($g['interface'] ?? '', $tunnelIfs, true) && !vpn_cidr_list_overlaps($allowed, $r['network'])
                        && !isset($warned[$ep['firewall_id'] . $r['network']])) {
                        $warned[$ep['firewall_id'] . $r['network']] = true;
                        $F[] = vpn_finding(['type' => 'route_not_in_allowedips', 'severity' => 'warning', 'key' => $ep['firewall_id'] . $r['network'],
                            'title' => sprintf('%s: static route %s enters WireGuard but no peer allows it', $ep['firewall_name'], $r['network']),
                            'explanation' => 'WireGuard only sends a packet to a peer whose AllowedIPs contain the destination, so traffic routed here is dropped.',
                            'evidence' => ['Static route ' . $r['network'] . ' via ' . $r['gateway'], 'AllowedIPs on instance ' . $ep['instance'] . ': ' . implode(', ', $allowed)],
                            'basis' => 'configured', 'confidence' => 'high', 'limitation' => 'Based on the configuration snapshot only.',
                            'next_step' => 'Add the network to the correct peer\'s AllowedIPs (or remove the stale route) after checking with the site owner.',
                            'affected' => ['firewalls' => [$ep['firewall_id']], 'tunnels' => array_filter([$tid])],
                            'freshness' => vpn_freshness($byId, [$ep['firewall_id']])]);
                    }
                }
            }
        }

        // ---- paired-tunnel checks -------------------------------------------
        foreach ($topology['tunnels'] as $t) {
            if ($t['b'] === null) { continue; }
            $a = $t['a']; $b = $t['b'];
            $ids = [$a['firewall_id'], $b['firewall_id']];
            $aff = ['firewalls' => $ids, 'tunnels' => [$t['id']]];
            $fresh = vpn_freshness($byId, $ids);
            $nm = $t['name'];

            if ($t['protocol'] === 'wireguard') {
                foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
                    // X's view of Y: what X sends to Y, and whether Y can answer.
                    $own = array_filter(array_merge($y['local_networks'], array_map(fn($ip) => $ip . '/32', $y['tunnel_ips'])));
                    foreach ($x['remote_networks'] as $n) {
                        if (vpn_cidr($n)['bits'] === 32 && !vpn_cidr_list_covers($own, $n)) {
                            // A bare host route: judged below only if network-sized.
                            continue;
                        }
                        if (!vpn_cidr_list_overlaps($own, $n) && !vpn_cidr_list_overlaps($y['remote_networks'], $n)) {
                            $F[] = vpn_finding(['type' => 'wg_allowedips_mismatch', 'severity' => 'warning', 'key' => $x['key'] . $n,
                                'title' => sprintf('%s sends %s to %s, which has no such network', $x['firewall_name'], $n, $y['firewall_name']),
                                'explanation' => 'The AllowedIPs entry points at a range that does not exist on the other managed firewall (by its interfaces, tunnel addresses or its own peer entries).',
                                'evidence' => [sprintf('%s peer AllowedIPs: %s', $x['firewall_name'], implode(', ', $x['allowed_ips'])),
                                               sprintf('%s local networks: %s', $y['firewall_name'], implode(', ', $y['local_networks']) ?: 'none inferred')],
                                'basis' => 'configured', 'confidence' => 'medium',
                                'limitation' => 'The far side\'s networks are inferred from its interface addresses; networks behind a further router would not show.',
                                'next_step' => 'Confirm the intended remote network with the site, then compare AllowedIPs on both ends.',
                                'affected' => $aff, 'freshness' => $fresh]);
                        }
                    }
                    // Y's AllowedIPs for X must include X's tunnel address, or replies are dropped.
                    foreach ($x['tunnel_ips'] as $ip) {
                        if (!vpn_cidr_list_covers($y['allowed_ips'], $ip . '/32')) {
                            $F[] = vpn_finding(['type' => 'wg_allowedips_mismatch', 'severity' => 'critical', 'key' => 'tip' . $y['key'] . $ip,
                                'title' => sprintf('%s does not allow %s\'s tunnel address %s', $y['firewall_name'], $x['firewall_name'], $ip),
                                'explanation' => 'Packets from the peer\'s tunnel address (including tunnel-IP pings and monitoring) arrive from a source the AllowedIPs do not cover, so WireGuard drops them even when the handshake succeeds.',
                                'evidence' => [sprintf('%s peer entry for %s has AllowedIPs: %s', $y['firewall_name'], $x['firewall_name'], implode(', ', $y['allowed_ips'])),
                                               sprintf('%s interface address %s', $x['firewall_name'], $ip)],
                                'basis' => 'configured', 'confidence' => 'high', 'limitation' => 'Both configurations must be current.',
                                'next_step' => 'Ping the peer\'s tunnel address from each side.',
                                'probe' => ['method' => 'icmp', 'target' => $ip],
                                'affected' => $aff, 'freshness' => $fresh]);
                        }
                    }
                    // Expected symmetry: X exchanges site networks with Y, but Y's primary LAN is not allowed back.
                    $xHasNet = array_filter($x['remote_networks'], fn($n) => vpn_cidr($n)['bits'] < 32);
                    if ($xHasNet) {
                        foreach (vpn_protected_networks($cfgOf($x['firewall_id'])) as $ln) {
                            if ($ln['iface'] !== 'lan') { continue; }
                            if (!vpn_cidr_list_overlaps($y['allowed_ips'], $ln['network']) && !vpn_cidr_list_overlaps($y['remote_networks'], $ln['network'])) {
                                $F[] = vpn_finding(['type' => 'wg_allowedips_mismatch', 'severity' => 'warning', 'key' => 'lan' . $y['key'] . $ln['network'],
                                    'title' => sprintf('%s: %s\'s LAN %s is not in AllowedIPs', $y['firewall_name'], $x['firewall_name'], $ln['network']),
                                    'explanation' => sprintf('%s routes site networks through this tunnel, but %s does not allow %s\'s LAN back, so site-to-site traffic from that LAN would be dropped at %s.', $x['firewall_name'], $y['firewall_name'], $x['firewall_name'], $y['firewall_name']),
                                    'evidence' => [sprintf('%s AllowedIPs: %s', $y['firewall_name'], implode(', ', $y['allowed_ips'])), 'Missing: ' . $ln['network']],
                                    'basis' => 'configured', 'confidence' => 'low',
                                    'limitation' => 'Whether that LAN is meant to be reachable is not recorded in the configuration; confirm intent.',
                                    'next_step' => 'Confirm whether ' . $ln['network'] . ' should be reachable from ' . $y['firewall_name'] . '.',
                                    'affected' => $aff, 'freshness' => $fresh]);
                            }
                        }
                    }
                }
            }

            if ($t['protocol'] === 'ipsec' && $a['selectors'] && $b['selectors']) {
                $m = vpn_selectors_mirror($a['selectors'], $b['selectors']);
                if ($m !== 'exact') {
                    $fmt = fn($ep) => implode('; ', array_map(fn($s) => implode(',', $s['local']) . ' -> ' . implode(',', $s['remote']), $ep['selectors']));
                    $F[] = vpn_finding(['type' => 'ipsec_selector_mismatch', 'severity' => 'critical', 'key' => $t['id'],
                        'title' => sprintf('IPsec phase 2 selectors do not mirror: %s', $nm),
                        'explanation' => 'Each side\'s local network must equal the other side\'s remote network. A mismatch makes the child SA fail to install (or install for only part of the traffic) even though phase 1 is established. IKEv2 traffic-selector narrowing can hide part of this.',
                        'evidence' => [sprintf('%s: %s', $a['firewall_name'], $fmt($a)), sprintf('%s: %s', $b['firewall_name'], $fmt($b)),
                                       $m === 'partial' ? 'Some selectors mirror; others do not.' : 'No selector has a mirror on the other side.'],
                        'basis' => 'configured', 'confidence' => 'high',
                        'limitation' => 'NAT-translated selectors (BINAT) are compared as configured, not as translated.',
                        'next_step' => 'Compare the child SA in the IPsec status on both firewalls; probe a host in each protected network.',
                        'affected' => $aff, 'freshness' => $fresh]);
                }
            }

            // ---- state-based: one-sided establishment, one-way counters -------
            $healthy = ['up', 'idle', 'degraded'];
            $sa = $t['state_a']['state']; $sb = $t['state_b']['state'];
            if ((in_array($sa, $healthy, true) && in_array($sb, ['down', 'disabled'], true))
             || (in_array($sb, $healthy, true) && in_array($sa, ['down', 'disabled'], true))) {
                $F[] = vpn_finding(['type' => 'one_sided', 'severity' => 'warning', 'key' => 'st' . $t['id'],
                    'title' => sprintf('Established on one side only: %s', $nm),
                    'explanation' => 'One firewall reports the tunnel working while the other reports it down or disabled. The tunnel cannot carry traffic both ways.',
                    'evidence' => [sprintf('%s: %s - %s', $a['firewall_name'], $sa, $t['state_a']['reason']), sprintf('%s: %s - %s', $b['firewall_name'], $sb, $t['state_b']['reason'])],
                    'basis' => 'observed', 'confidence' => 'medium',
                    'limitation' => 'Each side\'s telemetry may have a different age.',
                    'next_step' => 'Run a coordinated probe from both ends to see which direction fails.',
                    'affected' => $aff, 'freshness' => $fresh]);
            }
            $ra = $a['runtime'] ?? null; $rb = $b['runtime'] ?? null;
            if ($ra && $rb && $t['protocol'] === 'wireguard'
                && $t['state_a']['state'] !== 'stale' && $t['state_b']['state'] !== 'stale') {
                foreach ([[$a, $b, $ra, $rb], [$b, $a, $rb, $ra]] as [$x, $y, $rx, $ry]) {
                    if ((int)($rx['tx_bytes'] ?? 0) > 4096 && (int)($ry['rx_bytes'] ?? 0) === 0) {
                        $F[] = vpn_finding(['type' => 'asymmetric_routing', 'severity' => 'warning', 'key' => 'ow' . $x['key'],
                            'title' => sprintf('%s transmits but %s receives nothing', $x['firewall_name'], $y['firewall_name']),
                            'explanation' => 'Encrypted packets leave one end and never arrive at the other; typical of an upstream block, wrong endpoint, or the return path taking another route.',
                            'evidence' => [sprintf('%s tx %d B', $x['firewall_name'], $rx['tx_bytes']), sprintf('%s rx %d B', $y['firewall_name'], $ry['rx_bytes'])],
                            'basis' => 'observed', 'confidence' => 'low',
                            'limitation' => 'Counters are cumulative since the interface came up and sampled at different times.',
                            'next_step' => 'Probe in both directions and check the UDP port is reachable on the receiving WAN.',
                            'affected' => $aff, 'freshness' => $fresh]);
                    }
                }
            }
            // The far side returns via a non-tunnel gateway.
            foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
                $cy = $cfgOf($y['firewall_id']);
                if (!$cy) { continue; }
                $gw = [];
                foreach (($cy['gateways'] ?? []) as $g) { $gw[(string)$g['name']] = $g; }
                foreach (($cy['routes'] ?? []) as $r) {
                    if (empty($r['enabled']) || !vpn_cidr($r['network'] ?? '')) { continue; }
                    foreach ($x['local_networks'] as $ln) {
                        if (!vpn_cidr_overlap($r['network'], $ln)) { continue; }
                        $ifc = $gw[$r['gateway']]['interface'] ?? null;
                        if ($ifc && !in_array($ifc, $y['tunnel_ifaces'], true) && !in_array($ifc, ['wireguard', 'ipsec', 'openvpn', 'enc0'], true)) {
                            $F[] = vpn_finding(['type' => 'asymmetric_routing', 'severity' => 'warning', 'key' => 'rt' . $y['key'] . $r['network'],
                                'title' => sprintf('%s returns traffic for %s via %s instead of the tunnel', $y['firewall_name'], $ln, $ifc),
                                'explanation' => 'Requests may arrive through the tunnel while replies leave by another interface: apparent asymmetric routing.',
                                'evidence' => [sprintf('%s static route %s via %s (%s)', $y['firewall_name'], $r['network'], $r['gateway'], $ifc), sprintf('%s protected network %s', $x['firewall_name'], $ln)],
                                'basis' => 'inferred', 'confidence' => 'medium',
                                'limitation' => 'Dynamic routing and policy routing are not evaluated.',
                                'next_step' => 'Traceroute from ' . $y['firewall_name'] . ' to a host in ' . $ln . '.',
                                'probe' => ['method' => 'traceroute', 'target' => vpn_first_host($ln)],
                                'affected' => $aff, 'freshness' => $fresh]);
                        }
                    }
                }
            }
        }

        // ---- one-sided configuration (independent of pairing) ---------------
        foreach ($topology['endpoints'] as $ep) {
            if (!$ep['config_present'] || !$ep['enabled']) { continue; }
            if ($ep['protocol'] === 'wireguard' && !empty($ep['remote_ids']['public_key'])) {
                foreach ($topology['endpoints'] as $o) {
                    if ($o['firewall_id'] === $ep['firewall_id'] || $o['protocol'] !== 'wireguard') { continue; }
                    if (($o['local_ids']['public_key'] ?? null) !== $ep['remote_ids']['public_key']) { continue; }
                    $back = false;
                    foreach ($topology['endpoints'] as $p) {
                        if ($p['firewall_id'] === $o['firewall_id'] && $p['protocol'] === 'wireguard'
                            && ($p['remote_ids']['public_key'] ?? null) === ($ep['local_ids']['public_key'] ?? '-')) { $back = true; }
                    }
                    if (!$back) {
                        $F[] = vpn_finding(['type' => 'one_sided', 'severity' => 'warning', 'key' => 'cfg' . $ep['key'],
                            'title' => sprintf('%s has a peer for %s, but %s has none for it', $ep['firewall_name'], $o['firewall_name'], $o['firewall_name']),
                            'explanation' => 'WireGuard authenticates both ways. Without a peer entry carrying this firewall\'s public key, the far side rejects the handshake.',
                            'evidence' => [sprintf('%s peer "%s" uses the public key of %s\'s interface', $ep['firewall_name'], $ep['name'], $o['firewall_name']),
                                           sprintf('%s has no peer with %s\'s public key', $o['firewall_name'], $ep['firewall_name'])],
                            'basis' => 'configured', 'confidence' => 'high',
                            'limitation' => 'Based on configuration snapshots that may differ in age.',
                            'next_step' => 'Check the peer list on ' . $o['firewall_name'] . ' and the age of its configuration snapshot.',
                            'affected' => ['firewalls' => [$ep['firewall_id'], $o['firewall_id']], 'tunnels' => array_filter([$epTunnel[$ep['key']] ?? null])],
                            'freshness' => vpn_freshness($byId, [$ep['firewall_id'], $o['firewall_id']])]);
                    }
                }
            }
        }

        // ---- NAT, policy, MTU per endpoint ---------------------------------
        foreach ($topology['endpoints'] as $ep) {
            $cfg = $cfgOf($ep['firewall_id']);
            if (!$cfg || !$ep['config_present'] || !$ep['enabled']) { continue; }
            $tid = $epTunnel[$ep['key']] ?? null;
            $aff = ['firewalls' => [$ep['firewall_id']], 'tunnels' => array_filter([$tid])];
            $fresh = vpn_freshness($byId, [$ep['firewall_id']]);
            $ifs = $ep['tunnel_ifaces'];

            // NAT on tunnel traffic without a covering no-NAT rule.
            $rules = $cfg['nat']['outbound']['rules'] ?? [];
            foreach ($rules as $i => $r) {
                if (!empty($r['nonat']) || empty($r['enabled']) || !in_array((string)$r['interface'], $ifs, true)) { continue; }
                $covered = false;
                foreach (array_slice($rules, 0, $i) as $p) {
                    if (!empty($p['nonat']) && !empty($p['enabled']) && in_array((string)$p['interface'], array_merge($ifs, ['']), true)) { $covered = true; }
                }
                if ($covered) { continue; }
                $F[] = vpn_finding(['type' => 'nat_interference', 'severity' => 'warning', 'key' => $ep['key'] . $i,
                    'title' => sprintf('%s: outbound NAT translates traffic leaving tunnel interface %s', $ep['firewall_name'], $r['interface']),
                    'explanation' => 'Traffic crossing "' . $ep['name'] . '" is source-translated, so the far side sees the tunnel address instead of the real client. Selectors, AllowedIPs, and return routing that expect the original source can then fail.',
                    'evidence' => [sprintf('Outbound NAT rule #%d on %s (mode %s): %s', $i + 1, $r['interface'], $cfg['nat']['outbound']['mode'] ?? '?', $r['description'] ?? 'no description'),
                                   'No earlier enabled no-NAT rule on that interface'],
                    'basis' => 'configured', 'confidence' => in_array($cfg['nat']['outbound']['mode'] ?? '', ['manual', 'hybrid', 'advanced'], true) ? 'high' : 'medium',
                    'limitation' => 'Rule matching on source/destination is not evaluated; automatic NAT is not expanded.',
                    'next_step' => 'Review the outbound NAT rule; add a no-NAT rule for the tunnel networks if translation is not intended.',
                    'affected' => $aff, 'freshness' => $fresh]);
            }
            foreach (($cfg['nat']['one_to_one'] ?? []) as $o) {
                if (!empty($o['enabled']) && in_array((string)$o['interface'], $ifs, true)) {
                    $F[] = vpn_finding(['type' => 'nat_interference', 'severity' => 'info', 'key' => $ep['key'] . '11' . ($o['external'] ?? ''),
                        'title' => sprintf('%s: 1:1 NAT on tunnel interface %s', $ep['firewall_name'], $o['interface']),
                        'explanation' => '1:1 NAT rewrites addresses on this tunnel; peers must use the translated addresses.',
                        'evidence' => [sprintf('%s <-> %s', $o['internal'] ?? '?', $o['external'] ?? '?')],
                        'basis' => 'configured', 'confidence' => 'medium', 'limitation' => 'Informational.',
                        'next_step' => 'Confirm both ends agree on the translated ranges.', 'affected' => $aff, 'freshness' => $fresh]);
                }
            }

            // MTU / MSS: possible only.
            if ($ep['protocol'] === 'wireguard' && ($ep['details']['mtu'] ?? '') !== '' && (int)$ep['details']['mtu'] > 1420) {
                $F[] = vpn_finding(['type' => 'mtu_mss', 'severity' => 'info', 'key' => 'mtu' . $ep['key'],
                    'title' => sprintf('%s: WireGuard MTU %d is above the usual 1420', $ep['firewall_name'], (int)$ep['details']['mtu']),
                    'explanation' => 'WireGuard adds about 80 bytes over IPv4 (more over IPv6 or PPPoE). A larger interface MTU can fragment or black-hole large packets while small pings and handshakes still work. This is a possibility to confirm, not a finding of fault.',
                    'evidence' => ['Configured MTU ' . $ep['details']['mtu']],
                    'basis' => 'inferred', 'confidence' => 'low',
                    'limitation' => 'The path MTU is not known from configuration. Confirm with a don\'t-fragment ping of increasing size.',
                    'next_step' => 'Send a don\'t-fragment ICMP probe at size 1372 and 1420 across the tunnel and compare.',
                    'probe' => ['method' => 'icmp', 'target' => null, 'size' => 1372],
                    'affected' => $aff, 'freshness' => $fresh]);
            }

            // Policy on the tunnel interface.
            if (!$cfg['filter_rules']) { continue; }
            $onTunnel = array_values(array_filter($cfg['filter_rules'], fn($r) => !empty($r['enabled'])
                && (array_intersect($r['interface'] ?? [], $ifs) || (!empty($r['floating']) && empty($r['interface'])))));
            if (!$onTunnel) {
                $F[] = vpn_finding(['type' => 'policy', 'severity' => 'warning', 'key' => 'nopass' . $ep['key'],
                    'title' => sprintf('%s: no firewall rules on tunnel interface(s) %s', $ep['firewall_name'], implode(', ', $ifs)),
                    'explanation' => 'Traffic arriving from the tunnel is denied by default when no rule passes it.',
                    'evidence' => ['Rules scanned: ' . count($cfg['filter_rules']), 'Interfaces checked: ' . implode(', ', $ifs)],
                    'basis' => 'configured', 'confidence' => 'medium',
                    'limitation' => 'Interface-group membership and automatic rules (e.g. OPNsense VPN defaults) are not resolved.',
                    'next_step' => 'Open the rule list for the tunnel interface, then probe a protected host to see whether it answers.',
                    'affected' => $aff, 'freshness' => $fresh]);
                continue;
            }
            foreach ($onTunnel as $r) {
                if (strtolower((string)$r['action']) === 'pass') { break; }   // first pass ends the "blocked before any pass" window
                $nets = array_merge($ep['remote_networks'], $ep['local_networks']);
                $src = $r['source']['net'] ?? 'any';
                $dstNet = $r['destination']['net'] ?? 'any';
                $dstHit = $dstNet === 'any' || (vpn_cidr($dstNet) && vpn_cidr_list_overlaps($nets, $dstNet));
                $broad = in_array(strtolower((string)($r['protocol'] ?? 'any')), ['any', ''], true) && empty($r['destination']['port']);
                if (($src === 'any' || vpn_cidr_list_overlaps($nets, $src)) && $dstHit && $broad) {
                    $F[] = vpn_finding(['type' => 'policy', 'severity' => 'warning', 'key' => 'blk' . $ep['key'] . ($r['id'] ?? ''),
                        'title' => sprintf('%s: a block rule precedes any pass rule on %s', $ep['firewall_name'], implode('/', $ifs)),
                        'explanation' => 'The rule "' . ($r['description'] ?? $r['id'] ?? '?') . '" can match tunnel traffic before a pass rule is reached.',
                        'evidence' => [sprintf('Rule %s: %s %s -> %s port %s', $r['id'] ?? '', $r['action'], $src, $r['destination']['net'] ?? 'any', $r['destination']['port'] ?? 'any')],
                        'basis' => 'configured', 'confidence' => 'medium',
                        'limitation' => 'Protocol/port narrowing, aliases and schedules are not fully evaluated.',
                        'next_step' => 'Use the guided diagnostic with the real destination and port; it evaluates the rules for that flow.',
                        'affected' => $aff, 'freshness' => $fresh]);
                    break;
                }
            }

        }

        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($F, fn($x, $y) => [$rank[$x['severity']], $x['title']] <=> [$rank[$y['severity']], $y['title']]);
        $seen = []; $out = [];
        foreach ($F as $f) { if (!isset($seen[$f['id']])) { $seen[$f['id']] = true; $out[] = $f; } }
        return $out;
    }
}

if (!function_exists('vpn_first_host')) {
    /** A representative host address for a CIDR (first usable); /32 returns itself. */
    function vpn_first_host(string $cidr): ?string {
        $c = vpn_cidr($cidr);
        if (!$c) { return null; }
        return long2ip($c['bits'] >= 31 ? $c['first'] : $c['first'] + 1);
    }
}
