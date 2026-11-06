<?php
/**
 * VPN topology: match endpoints into tunnels and build the map model.
 *
 * Matching uses several independent identifiers and a score, because no one of
 * them is safe alone:
 *
 *   - Public IP addresses are shared. Two firewalls behind one NAT device, or
 *     a carrier-grade NAT, present the same address, so an endpoint IP (even
 *     with a port) is weak evidence and can never merge peers by itself.
 *   - Public keys, mirrored IPsec selectors with a mirrored gateway or ID, and
 *     matching OpenVPN tunnel networks are strong.
 *   - When the evidence ties between candidates the endpoint is left
 *     "ambiguous" with the candidates listed, for an operator to resolve.
 *
 * Operator decisions (vpn_peer_pairings) override the automatic result in
 * both directions: a pairing forces a link, an un-pair forbids one, and a
 * classification fixes how an endpoint is described.
 *
 * Remote-side state is never invented: when only one end is managed the
 * tunnel carries that end's state and says the other end was not observed.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/endpoints.php';
require_once __DIR__ . '/state.php';

const VPN_PAIR_THRESHOLD = 60;
const VPN_SUGGEST_THRESHOLD = 25;
const VPN_PAIR_MARGIN = 20;

if (!function_exists('vpn_pair_key')) {
    /** Order-independent key for a pair of endpoint keys. */
    function vpn_pair_key(string $a, string $b): array {
        return strcmp($a, $b) <= 0 ? [$a, $b] : [$b, $a];
    }
}

if (!function_exists('vpn_id_data')) {
    /** "fqdn:gw.example" -> "gw.example"; address-type placeholders -> null. */
    function vpn_id_data(string $id): ?string {
        $id = trim($id);
        if ($id === '' || preg_match('/^(myaddress|peeraddress|any|dyn_dns)\b/i', $id)) { return null; }
        $p = strpos($id, ':');
        $d = $p === false ? $id : substr($id, $p + 1);
        return $d === '' ? null : strtolower($d);
    }
}

if (!function_exists('vpn_selectors_mirror')) {
    /** Compare two endpoints' selector lists; exact | partial | none. */
    function vpn_selectors_mirror(array $a, array $b): string {
        $key = fn($l, $r) => implode(',', $l) . '>' . implode(',', $r);
        $bset = [];
        foreach ($b as $s) { $bset[$key($s['local'], $s['remote'])] = true; }
        $hit = 0;
        foreach ($a as $s) {
            // A's local->remote must equal B's remote->local... expressed as B's (local=A.remote, remote=A.local).
            if (isset($bset[$key($s['remote'], $s['local'])])) { $hit++; }
        }
        if ($a && $hit === count($a) && count($a) === count($b)) { return 'exact'; }
        return $hit > 0 ? 'partial' : 'none';
    }
}

if (!function_exists('vpn_pair_score')) {
    /**
     * How strongly two endpoints on different firewalls look like the two ends
     * of one tunnel.
     * @return array{score:int, evidence:string[], contradicted:bool}
     */
    function vpn_pair_score(array $a, array $b, array $devA, array $devB, array $owners = []): array {
        $score = 0; $ev = []; $contra = false;
        if ($a['protocol'] !== $b['protocol'] || !$a['config_present'] || !$b['config_present']) {
            return ['score' => 0, 'evidence' => [], 'contradicted' => false];
        }
        $addrA = vpn_device_addresses($devA); $addrB = vpn_device_addresses($devB);
        $nameA = $devA['hostname'] ?? $a['firewall_id']; $nameB = $devB['hostname'] ?? $b['firewall_id'];

        if ($a['protocol'] === 'wireguard') {
            $ignore = ['', 'REDACTED'];
            $ra = (string)($a['remote_ids']['public_key'] ?? ''); $la = (string)($a['local_ids']['public_key'] ?? '');
            $rb = (string)($b['remote_ids']['public_key'] ?? ''); $lb = (string)($b['local_ids']['public_key'] ?? '');
            $ab = !in_array($ra, $ignore, true) && $ra === $lb;
            $ba = !in_array($rb, $ignore, true) && $rb === $la;
            if ($ab && $ba) { $score += 100; $ev[] = "Public keys match in both directions ({$nameA} peer = {$nameB} interface key, and the reverse)."; }
            elseif ($ab)    { $score += 60;  $ev[] = "{$nameA}'s peer public key equals {$nameB}'s interface key; {$nameB} has no peer entry carrying {$nameA}'s key."; }
            elseif ($ba)    { $score += 60;  $ev[] = "{$nameB}'s peer public key equals {$nameA}'s interface key; {$nameA} has no peer entry carrying {$nameB}'s key."; }
            elseif (!in_array($ra, $ignore, true) && !in_array($lb, $ignore, true)
                    && !in_array($rb, $ignore, true) && !in_array($la, $ignore, true)) {
                $contra = true;   // both sides know their keys and neither matches
            }
            foreach ([[$a, $b, $addrB, $nameA, $nameB], [$b, $a, $addrA, $nameB, $nameA]] as [$x, $y, $addrY, $nx, $ny]) {
                $h = $x['remote_ids']['host'] ?? null; $p = $x['remote_ids']['port'] ?? null;
                if ($h && in_array($h, $addrY, true)) {
                    if ($p && (string)$p === (string)($y['local_ids']['port'] ?? '')) {
                        $score += 30; $ev[] = "{$nx} targets {$h}:{$p}, which is {$ny}'s address and listen port (shared addresses make this weak).";
                    } else { $score += 10; $ev[] = "{$nx} targets {$h}, an address of {$ny} (no port match; weak)."; }
                }
            }
            $tipsA = $a['tunnel_ips']; $tipsB = $b['tunnel_ips'];
            $m1 = $tipsB && array_intersect(array_map(fn($i) => $i . '/32', $tipsB), $a['peer_tunnel_ips']);
            $m2 = $tipsA && array_intersect(array_map(fn($i) => $i . '/32', $tipsA), $b['peer_tunnel_ips']);
            if ($m1 && $m2) { $score += 30; $ev[] = "Each side's AllowedIPs contain the other side's tunnel address."; }
            elseif ($m1 || $m2) { $score += 10; $ev[] = "One side's AllowedIPs contain the other's tunnel address."; }
        } elseif ($a['protocol'] === 'ipsec') {
            $gwA = $a['remote_ids']['gateway'] ?? null; $gwB = $b['remote_ids']['gateway'] ?? null;
            $g1 = $gwA && in_array($gwA, $addrB, true); $g2 = $gwB && in_array($gwB, $addrA, true);
            // Each gateway address must belong to exactly one managed firewall to
            // count as strong: behind a shared NAT address it identifies nobody.
            $uniq = $g1 && $g2 && ($owners[$gwA] ?? 1) === 1 && ($owners[$gwB] ?? 1) === 1;
            if ($g1 && $g2 && $uniq) { $score += 60; $ev[] = 'Remote gateways point at each other, and each address belongs to exactly one managed firewall.'; }
            elseif ($g1 && $g2) { $score += 40; $ev[] = 'Remote gateways point at each other, but an address is shared by several firewalls (NAT), so it is not decisive.'; }
            elseif ($g1 || $g2) { $score += 25; $ev[] = 'One remote gateway matches the other firewall\'s address (addresses can be shared).'; }
            $ia = array_filter(array_map('vpn_id_data', $a['remote_ids']['ids'] ?? []));
            $la = array_filter(array_map('vpn_id_data', $a['local_ids']['ids'] ?? []));
            $ib = array_filter(array_map('vpn_id_data', $b['remote_ids']['ids'] ?? []));
            $lb = array_filter(array_map('vpn_id_data', $b['local_ids']['ids'] ?? []));
            $i1 = $ia && array_intersect($ia, $lb); $i2 = $ib && array_intersect($ib, $la);
            if ($i1 && $i2) { $score += 40; $ev[] = 'IKE identities match in both directions.'; }
            elseif ($i1 || $i2) { $score += 20; $ev[] = 'An IKE identity matches in one direction.'; }
            if ($a['selectors'] && $b['selectors']) {
                $m = vpn_selectors_mirror($a['selectors'], $b['selectors']);
                if ($m === 'exact') { $score += 50; $ev[] = 'Phase 2 selectors mirror exactly.'; }
                elseif ($m === 'partial') { $score += 25; $ev[] = 'Some phase 2 selectors mirror.'; }
            }
        } else { // openvpn
            $c = $a['kind'] === 'ovpn_client' ? $a : ($b['kind'] === 'ovpn_client' ? $b : null);
            $s = $a['kind'] === 'ovpn_server' ? $a : ($b['kind'] === 'ovpn_server' ? $b : null);
            if (!$c || !$s) { return ['score' => 0, 'evidence' => [], 'contradicted' => false]; }
            $dc = $c === $a ? $devA : $devB; $ds = $s === $a ? $devA : $devB;
            $h = $c['remote_ids']['host'] ?? null; $p = $c['remote_ids']['port'] ?? null;
            if ($h && in_array($h, vpn_device_addresses($ds), true)) {
                if ($p && (string)$p === (string)($s['details']['port'] ?? '')) { $score += 35; $ev[] = "Client targets {$h}:{$p}, the server's address and port (shared addresses make this weak)."; }
                else { $score += 10; $ev[] = "Client targets {$h}, an address of the server (weak)."; }
            }
            if (($c['details']['protocol'] ?? 'x') === ($s['details']['protocol'] ?? 'y')) { $score += 5; }
            if ($c['tunnel_nets'] && $c['tunnel_nets'] === $s['tunnel_nets']) { $score += 30; $ev[] = 'Same tunnel network on both instances.'; }
            $mc = array_filter($c['remote_networks'], fn($n) => vpn_cidr_list_overlaps($s['local_networks'], $n));
            $ms = array_filter($s['remote_networks'], fn($n) => vpn_cidr_list_overlaps($c['local_networks'], $n));
            if ($mc && $ms) { $score += 25; $ev[] = 'Remote and local networks mirror.'; }
        }
        if ($contra && $score > 20) { $score = 20; $ev[] = 'Both sides know their WireGuard keys and they do not correspond: capped.'; }
        return ['score' => $score, 'evidence' => $ev, 'contradicted' => $contra];
    }
}

if (!function_exists('vpn_pairings_apply')) {
    /** Split stored pairings into lookup structures. */
    function vpn_pairings_index(array $rows): array {
        $idx = ['pair' => [], 'unpair' => [], 'classify' => []];
        foreach ($rows as $r) {
            if ($r['kind'] === 'classify') { $idx['classify'][$r['endpoint_a']] = $r['classification']; }
            else { [$x, $y] = vpn_pair_key($r['endpoint_a'], $r['endpoint_b']); $idx[$r['kind']][$x . "\0" . $y] = $r; }
        }
        return $idx;
    }
}

if (!function_exists('vpn_match_endpoints')) {
    /**
     * @param array[] $endpoints all endpoints, keyed by endpoint key
     * @param array   $devices   id => device
     * @param array   $idx       vpn_pairings_index()
     * @return array{links:array, ambiguous:array, suggestions:array}
     */
    function vpn_match_endpoints(array $endpoints, array $devices, array $idx): array {
        $owners = [];
        foreach ($devices as $d) { foreach (vpn_device_addresses($d) as $ad) { $owners[$ad] = ($owners[$ad] ?? 0) + 1; } }
        $cands = [];                       // key => [ [other, score, evidence] ]
        $byKey = $endpoints;
        $keys = array_keys($endpoints);
        $n = count($keys);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $endpoints[$keys[$i]]; $b = $endpoints[$keys[$j]];
                if ($a['firewall_id'] === $b['firewall_id'] || $a['protocol'] !== $b['protocol']) { continue; }
                [$x, $y] = vpn_pair_key($a['key'], $b['key']);
                if (isset($idx['unpair'][$x . "\0" . $y])) { continue; }
                $r = vpn_pair_score($a, $b, $devices[$a['firewall_id']], $devices[$b['firewall_id']], $owners);
                if ($r['score'] < VPN_SUGGEST_THRESHOLD) { continue; }
                $cands[$a['key']][] = ['other' => $b['key'], 'score' => $r['score'], 'evidence' => $r['evidence']];
                $cands[$b['key']][] = ['other' => $a['key'], 'score' => $r['score'], 'evidence' => $r['evidence']];
            }
        }

        $links = []; $used = [];
        // Operator pairings first: they win and make both ends exclusive.
        foreach ($idx['pair'] as $row) {
            $a = $row['endpoint_a']; $b = $row['endpoint_b'];
            if (!isset($byKey[$a], $byKey[$b]) || isset($used[$a]) || isset($used[$b])) { continue; }
            $ev = ['Paired by an operator' . (!empty($row['created_by']) ? ' (' . $row['created_by'] . ')' : '')
                   . (!empty($row['note']) ? ': ' . $row['note'] : '') . '.'];
            $auto = vpn_pair_score($byKey[$a], $byKey[$b], $devices[$byKey[$a]['firewall_id']], $devices[$byKey[$b]['firewall_id']], $owners);
            foreach ($auto['evidence'] as $e) { $ev[] = 'Configuration also shows: ' . $e; }
            $links[] = ['a' => $a, 'b' => $b, 'basis' => 'manual', 'score' => max(100, $auto['score']),
                        'evidence' => $ev, 'confidence' => 'operator'];
            $used[$a] = $used[$b] = true;
        }

        $best = [];
        foreach ($cands as $k => $list) {
            if (isset($used[$k])) { continue; }
            $list = array_values(array_filter($list, fn($c) => !isset($used[$c['other']])));
            usort($list, fn($p, $q) => $q['score'] <=> $p['score']);
            $best[$k] = $list;
        }
        $ambiguous = []; $suggestions = [];
        foreach ($best as $k => $list) {
            if (!$list) { continue; }
            $top = $list[0];
            $ok = $top['score'] >= VPN_PAIR_THRESHOLD
               && (!isset($list[1]) || $top['score'] - $list[1]['score'] >= VPN_PAIR_MARGIN)
               && isset($best[$top['other']][0]) && $best[$top['other']][0]['other'] === $k
               && (!isset($best[$top['other']][1]) || $best[$top['other']][0]['score'] - $best[$top['other']][1]['score'] >= VPN_PAIR_MARGIN);
            if ($ok) {
                if (strcmp($k, $top['other']) < 0) {
                    $ev = $top['evidence'];
                    $links[] = ['a' => $k, 'b' => $top['other'], 'basis' => 'inferred', 'score' => $top['score'],
                                'evidence' => $ev, 'confidence' => $top['score'] >= 100 ? 'high' : ($top['score'] >= 80 ? 'medium' : 'low')];
                }
                continue;
            }
            $ambiguous[$k] = array_map(fn($c) => ['key' => $c['other'], 'score' => $c['score'], 'evidence' => $c['evidence']], $list);
        }
        return ['links' => $links, 'ambiguous' => $ambiguous];
    }
}

if (!function_exists('vpn_build_topology')) {
    /**
     * Build the full model from devices.
     *
     * @param array[] $devices  see vpn_collect_device()
     * @param array[] $pairings rows of vpn_peer_pairings
     * @param array   $opts     now, probes (endpoint key => {outcome, at}), thresholds
     */
    function vpn_build_topology(array $devices, array $pairings = [], array $opts = []): array {
        $now = $opts['now'] ?? time();
        $idx = vpn_pairings_index($pairings);
        $thr = $opts['thresholds'] ?? vpn_thresholds();
        $byId = [];
        foreach ($devices as $d) { $byId[$d['id']] = $d; }

        $endpoints = [];
        foreach ($byId as $d) {
            foreach (vpn_build_endpoints($d) as $ep) {
                if (isset($idx['classify'][$ep['key']])) {
                    $c = $idx['classify'][$ep['key']];
                    if (in_array($c, ['site_to_site', 'remote_access', 'unknown'], true)) { $ep['role'] = $c; }
                    $ep['classified'] = $c; $ep['role_source'] = 'operator';
                    $ep['role_reason'] = 'Classified by an operator as ' . str_replace('_', ' ', $c) . '.';
                }
                $ep['state'] = vpn_endpoint_state($ep, [
                    'now' => $now, 'thresholds' => $thr, 'telemetry_at' => $d['telemetry_at'] ?? null,
                    'probe' => $opts['probes'][$ep['key']] ?? null,
                ]);
                $endpoints[$ep['key']] = $ep;
            }
        }

        $match = vpn_match_endpoints($endpoints, $byId, $idx);
        $tunnels = []; $inLink = [];
        $mk = function (array $eps) {
            $k = array_column($eps, 'key'); sort($k);
            return substr(sha1(implode('~', $k)), 0, 12);
        };

        foreach ($match['links'] as $l) {
            $a = $endpoints[$l['a']]; $b = $endpoints[$l['b']];
            $inLink[$a['key']] = $inLink[$b['key']] = true;
            $observed = false;
            if ($l['basis'] !== 'manual') {
                foreach ([$a, $b] as $e) {
                    $r = $e['runtime'] ?? null;
                    if ($r && (vpn_ts($r['latest_handshake'] ?? null) !== null
                        || in_array(strtolower((string)($r['status'] ?? '')), ['up', 'connected', 'established'], true))) { $observed = true; }
                }
            }
            $relationship = $l['basis'] === 'manual' ? 'manual' : ($observed ? 'observed' : 'inferred');
            $sa = $a['state']; $sb = $b['state'];
            $combined = vpn_combine_states($sa['state'], $sb['state']);
            $kind = ($a['role'] === 'remote_access' && $b['role'] === 'remote_access') ? 'remote_access' : 'site_to_site';
            $tunnels[] = [
                'id' => $mk([$a, $b]), 'kind' => $kind, 'protocol' => $a['protocol'],
                'name' => $a['firewall_name'] . ' <-> ' . $b['firewall_name'],
                'relationship' => $relationship, 'match_score' => $l['score'], 'confidence' => $l['confidence'],
                'a' => $a, 'b' => $b, 'state' => $combined, 'state_a' => $sa, 'state_b' => $sb,
                'partial' => false, 'evidence' => $l['evidence'],
                'firewall_ids' => [$a['firewall_id'], $b['firewall_id']],
            ];
        }

        foreach ($endpoints as $key => $e) {
            if (isset($inLink[$key])) { continue; }
            $kind = null; $evidence = []; $relationship = 'inferred'; $conf = 'medium';
            $classified = $e['classified'] ?? null;
            if ($classified === 'external') {
                $kind = 'external'; $relationship = 'manual'; $evidence[] = 'Marked as an external peer by an operator.'; $conf = 'operator';
            } elseif ($e['role'] === 'remote_access') {
                $kind = 'remote_access'; $evidence[] = 'Role: ' . $e['role_reason'] . '.';
            } elseif (isset($match['ambiguous'][$key])) {
                $kind = 'ambiguous'; $conf = 'low';
                $evidence[] = 'Several managed endpoints could be the other end, or the evidence is not decisive; nothing was merged.';
                foreach (array_slice($match['ambiguous'][$key], 0, 4) as $c) {
                    $o = $endpoints[$c['key']] ?? null;
                    $evidence[] = sprintf('Candidate: %s / %s (score %d). %s', $o['firewall_name'] ?? '?', $o['name'] ?? '?', $c['score'], implode(' ', $c['evidence']));
                }
            } elseif (!$e['config_present']) {
                $kind = 'unknown'; $conf = 'low'; $evidence[] = $e['role_reason'] . '.';
            } else {
                $host = $e['remote_ids']['host'] ?? null;
                $managedAddr = false;
                if ($host) {
                    foreach ($byId as $d) {
                        if ($d['id'] !== $e['firewall_id'] && in_array($host, vpn_device_addresses($d), true)) { $managedAddr = true; }
                    }
                }
                $hasIdentity = $host || !empty($e['remote_ids']['public_key']) || !empty($e['remote_ids']['gateway']);
                if ($managedAddr) {
                    $kind = 'ambiguous'; $conf = 'low';
                    $evidence[] = 'The remote address belongs to a managed firewall but no key, identity or selector confirms it is the other end of this tunnel. Pair it manually if it is.';
                } elseif ($hasIdentity) {
                    $kind = 'external'; $evidence[] = 'No managed endpoint matches the remote identity; treated as an external (unmanaged) peer. Its state is not observable.';
                } else {
                    $kind = 'unknown'; $conf = 'low'; $evidence[] = 'The configuration names no remote endpoint or key.';
                }
            }
            $tunnels[] = [
                'id' => $mk([$e]), 'kind' => $kind, 'protocol' => $e['protocol'],
                'name' => $e['firewall_name'] . ' / ' . $e['name'],
                'relationship' => $relationship, 'match_score' => 0, 'confidence' => $conf,
                'a' => $e, 'b' => null, 'state' => $e['state']['state'], 'state_a' => $e['state'], 'state_b' => null,
                'partial' => true, 'evidence' => $evidence, 'firewall_ids' => [$e['firewall_id']],
            ];
        }

        foreach ($tunnels as &$t) {
            $t['customer_ids'] = []; $t['site_ids'] = []; $t['platforms'] = [];
            foreach ($t['firewall_ids'] as $fid) {
                $d = $byId[$fid];
                $t['customer_ids'][] = $d['customer_id'] ?? null; $t['site_ids'][] = $d['site_id'] ?? null;
                $t['platforms'][] = $d['platform'] ?? 'unknown';
            }
            $t['collected'] = [];
            foreach (['a' => 0, 'b' => 1] as $side => $i) {
                if (empty($t[$side])) { continue; }
                $d = $byId[$t['firewall_ids'][$i] ?? $t['firewall_ids'][0]];
                $t['collected'][$side] = [
                    'config_time' => $d['config_time'] ?? null, 'telemetry_at' => $d['telemetry_at'] ?? null,
                    'source' => $t[$side]['source'],
                ];
            }
        }
        unset($t);
        usort($tunnels, fn($x, $y) => strcmp($x['name'], $y['name']));

        return [
            'generated_at' => $now,
            'tunnels' => $tunnels,
            'devices' => array_values(array_map(fn($d) => [
                'id' => $d['id'], 'hostname' => $d['hostname'] ?? '', 'customer_id' => $d['customer_id'] ?? null,
                'customer_name' => $d['customer_name'] ?? null, 'site_id' => $d['site_id'] ?? null,
                'site_name' => $d['site_name'] ?? null, 'platform' => $d['platform'] ?? 'unknown',
                'platform_label' => $d['platform_label'] ?? ($d['platform'] ?? 'unknown'),
                'config_time' => $d['config_time'] ?? null, 'config_error' => $d['config_error'] ?? null,
                'has_config' => !empty($d['config']), 'telemetry_at' => $d['telemetry_at'] ?? null,
            ], $byId)),
            'endpoints' => $endpoints,
            'ambiguous' => $match['ambiguous'],
        ];
    }
}

if (!function_exists('vpn_graph')) {
    /**
     * Nodes and edges for the map. Remote-access peers are grouped per firewall
     * and protocol (a node per phone would bury the site links); external and
     * ambiguous peers get one node each and are never merged by address.
     */
    function vpn_graph(array $topology): array {
        $nodes = []; $edges = [];
        $devs = [];
        foreach ($topology['devices'] as $d) {
            $devs[$d['id']] = $d;
            $nodes['fw' . $d['id']] = ['id' => 'fw' . $d['id'], 'type' => 'firewall', 'label' => $d['hostname'] ?: ('#' . $d['id']),
                'firewall_id' => $d['id'], 'customer_id' => $d['customer_id'], 'customer' => $d['customer_name'],
                'site' => $d['site_name'], 'platform' => $d['platform'], 'platform_label' => $d['platform_label']];
        }
        $groups = [];
        foreach ($topology['tunnels'] as $t) {
            $fa = 'fw' . $t['a']['firewall_id'];
            if ($t['kind'] === 'remote_access' && $t['b'] === null) {
                $gid = 'ra:' . $t['a']['firewall_id'] . ':' . $t['protocol'];
                $nodes[$gid] ??= ['id' => $gid, 'type' => 'remote_access', 'label' => 'Remote access (' . $t['protocol'] . ')',
                    'firewall_id' => $t['a']['firewall_id'], 'protocol' => $t['protocol'], 'count' => 0];
                $groups[$gid][] = $t;
                $nodes[$gid]['count']++;
                continue;
            }
            if ($t['b'] !== null) {
                $edges[] = ['id' => 'e' . $t['id'], 'from' => $fa, 'to' => 'fw' . $t['b']['firewall_id'], 'tunnel' => $t['id'],
                            'state' => $t['state'], 'relationship' => $t['relationship'], 'protocol' => $t['protocol'], 'kind' => $t['kind']];
            } else {
                $nid = 'x:' . $t['id'];
                $nodes[$nid] = ['id' => $nid, 'type' => $t['kind'], 'label' => $t['kind'] === 'external' ? 'External peer' : ($t['kind'] === 'ambiguous' ? 'Ambiguous peer' : 'Unknown peer'),
                    'detail' => $t['a']['remote_ids']['host'] ?? ($t['a']['name'] ?? ''), 'protocol' => $t['protocol']];
                $edges[] = ['id' => 'e' . $t['id'], 'from' => $fa, 'to' => $nid, 'tunnel' => $t['id'],
                            'state' => $t['state'], 'relationship' => $t['relationship'], 'protocol' => $t['protocol'], 'kind' => $t['kind']];
            }
        }
        foreach ($groups as $gid => $ts) {
            $states = array_column($ts, 'state');
            $agg = 'unknown';
            foreach (['up', 'degraded', 'idle', 'stale', 'down', 'disabled'] as $s) {
                if (in_array($s, $states, true)) { $agg = $s; break; }
            }
            $edges[] = ['id' => 'e' . $gid, 'from' => 'fw' . $ts[0]['a']['firewall_id'], 'to' => $gid, 'tunnel' => null,
                        'tunnels' => array_column($ts, 'id'), 'state' => $agg, 'relationship' => 'inferred',
                        'protocol' => $ts[0]['protocol'], 'kind' => 'remote_access', 'count' => count($ts)];
        }
        return ['nodes' => array_values($nodes), 'edges' => $edges];
    }
}
