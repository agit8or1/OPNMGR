<?php
/**
 * VPN troubleshooting: the guided, bounded probe workflow.
 *
 * A technician names a source firewall, a destination, a protocol and (for
 * TCP/UDP) a port, optionally a tunnel. This file validates all of it, picks
 * the source address, applies rate limits, and queues the probe through the
 * existing structured-command path (build_structured_command() +
 * queue_firewall_command()), so the platform gate, the audit log and the agent
 * delivery rules all apply unchanged.
 *
 * What a probe is, and is not:
 *
 *   A probe originates ON THE FIREWALL. It does not traverse the LAN
 *   interface's rule set the way a client's packets do, so a successful probe
 *   does NOT prove a LAN client can reach the destination, and a failed one
 *   does not prove a client cannot. To make it exercise the same tunnel
 *   selector or route a client would, the probe is bound to the firewall's own
 *   address on the protected network (ping -S / nc -s / traceroute -s).
 *
 * Probes are bounded: three ICMP echoes, one connection attempt with a 3
 * second timeout, a 20-hop traceroute. They are never raw shell, and there is
 * no way to run a probe from this module that the command catalogue does not
 * describe.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/topology.php';
require_once __DIR__ . '/policy.php';
require_once __DIR__ . '/../agent_commands.php';

const VPN_PROBE_DISCLAIMER = 'This probe runs on the firewall itself, bound to its own address on the protected network. '
    . 'A successful result shows the tunnel and routes can carry that traffic; it does NOT prove that a LAN client\'s '
    . 'policy path works, because a client\'s packets are also subject to the LAN interface rules, NAT and any '
    . 'policy routing that firewall-originated traffic bypasses.';

if (!function_exists('vpn_probe_validate')) {
    /**
     * Validate operator input. Rejects rather than adjusts.
     * @return array{ok:bool, error:string, params:array, note:?string}
     */
    function vpn_probe_validate(array $in): array {
        $bad = fn(string $e) => ['ok' => false, 'error' => $e, 'params' => [], 'note' => null];
        $method = strtolower(trim((string)($in['method'] ?? '')));
        if (!in_array($method, ['icmp', 'tcp', 'udp', 'traceroute'], true)) {
            return $bad('Protocol must be one of icmp, tcp, udp, traceroute.');
        }
        $dest = trim((string)($in['dest'] ?? ''));
        if ($dest === '') { return $bad('A destination IP address or subnet is required.'); }
        $note = null;
        if (str_contains($dest, '/')) {
            $c = vpn_cidr($dest);
            if (!$c) { return $bad('The destination subnet is not a valid IPv4 CIDR.'); }
            if ($c['bits'] < 8) { return $bad('That subnet is too large to represent with a probe; use /8 or smaller.'); }
            $dest = vpn_first_host($dest);
            $note = 'A subnet was given; the probe targets its first host ' . $dest . '. Probe a known host for a conclusive result.';
        }
        if (str_contains($dest, ':')) { return $bad('IPv6 destinations are not supported by the VPN probe.'); }
        if (filter_var($dest, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return $bad('The destination must be an IPv4 address (names are not resolved by the probe).');
        }
        foreach (['0.0.0.0/8', '127.0.0.0/8', '169.254.0.0/16', '224.0.0.0/4', '240.0.0.0/4'] as $blocked) {
            if (vpn_cidr_contains($blocked, $dest . '/32')) {
                return $bad('That destination (loopback, link-local, multicast or reserved) cannot be probed.');
            }
        }
        $params = ['method' => $method, 'target' => $dest];
        if (in_array($method, ['tcp', 'udp'], true)) {
            $port = $in['port'] ?? '';
            if ($port === '' || $port === null || !ctype_digit((string)$port) || (int)$port < 1 || (int)$port > 65535) {
                return $bad('TCP and UDP probes need a port between 1 and 65535.');
            }
            $params['port'] = (int)$port;
        } elseif (($in['port'] ?? '') !== '' && $in['port'] !== null) {
            return $bad('A port only applies to TCP and UDP probes.');
        }
        if (($in['size'] ?? '') !== '' && ($in['size'] ?? null) !== null) {
            if ($method !== 'icmp') { return $bad('Payload size only applies to ICMP probes.'); }
            if (!ctype_digit((string)$in['size']) || (int)$in['size'] > 1472) { return $bad('Payload size must be 0-1472 bytes.'); }
            $params['size'] = (int)$in['size'];
        }
        return ['ok' => true, 'error' => '', 'params' => $params, 'note' => $note];
    }
}

if (!function_exists('vpn_probe_source')) {
    /**
     * Choose the address a probe is bound to: the firewall's own address on the
     * network the tunnel protects, so the probe enters the tunnel the way a
     * client behind that network would (and matches an IPsec local selector).
     *
     * @return array{ok:bool, address:?string, interface:?string, device:?string, network:?string,
     *               reason:string, fallback:bool, warnings:string[]}
     */
    function vpn_probe_source(array $device, ?array $endpoint, string $dest): array {
        $res = fn(bool $ok, ?array $c, string $reason, bool $fb = false, array $w = []) => [
            'ok' => $ok, 'address' => $c['address'] ?? null, 'interface' => $c['iface'] ?? null,
            'device' => $c ? ($device['config']['interfaces'][$c['iface']]['device'] ?? null) : null,
            'network' => $c['network'] ?? null, 'reason' => $reason, 'fallback' => $fb, 'warnings' => $w];
        $cfg = $device['config'] ?? null;
        if (!$cfg) { return $res(false, null, 'No configuration snapshot, so no source address can be chosen.'); }
        $warn = [];
        $cands = vpn_protected_networks($cfg);
        $isIpsec = $endpoint && $endpoint['protocol'] === 'ipsec';
        $sel = $endpoint['local_networks'] ?? [];

        $usable = [];
        foreach ($cands as $c) {
            if (vpn_cidr_contains($c['network'], $dest . '/32')) {
                $warn[] = "The destination is inside {$c['iface']} ({$c['network']}); that is a local host, not across the tunnel.";
                continue;
            }
            $usable[] = $c;
        }
        if ($isIpsec && $sel) {
            $inSel = array_values(array_filter($usable, fn($c) => vpn_cidr_list_covers($sel, $c['address'] . '/32')));
            if (!$inSel) {
                return $res(false, null, 'No address of this firewall lies inside the phase 2 local network(s) ' . implode(', ', $sel)
                    . '. A probe from another address would not match the selector and would not use the tunnel.', false, $warn);
            }
            $usable = $inSel;
        } elseif ($sel) {
            $pref = array_values(array_filter($usable, fn($c) => vpn_cidr_list_overlaps($sel, $c['network'])));
            $usable = $pref ?: $usable;
        }
        usort($usable, fn($a, $b) => [$a['iface'] === 'lan' ? 0 : 1, $a['iface']] <=> [$b['iface'] === 'lan' ? 0 : 1, $b['iface']]);
        if ($usable) {
            $c = $usable[0];
            return $res(true, $c, sprintf('Firewall address %s on %s (%s), inside the network this tunnel protects.', $c['address'], $c['iface'], $c['network']), false, $warn);
        }
        if ($endpoint && !empty($endpoint['tunnel_ips'][0])) {
            $ip = $endpoint['tunnel_ips'][0];
            $warn[] = 'No protected-network address was available, so the probe is bound to the tunnel interface address. That is not what client traffic does.';
            return $res(true, ['address' => $ip, 'iface' => $endpoint['tunnel_ifaces'][0] ?? null, 'network' => null],
                "Tunnel interface address {$ip} (fallback).", true, $warn);
        }
        return $res(false, null, 'No suitable source address was found on this firewall.', false, $warn);
    }
}

if (!function_exists('vpn_probe_rate_check')) {
    /**
     * Per-user and per-firewall hourly limits plus a cap on probes in flight
     * for one firewall. Counted from stored runs, so it holds across requests.
     * @param array<int,int> $legsByFirewall firewall_id => legs about to be queued
     * @return array{ok:bool, error:string, retry_after:int}
     */
    function vpn_probe_rate_check(?int $userId, array $legsByFirewall, ?int $now = null): array {
        $now = $now ?? time();
        $since = date('Y-m-d H:i:s', $now - 3600);
        $perUser = max(1, (int)vpn_setting('vpn_probe_rate_user_per_hour', '30'));
        $perFw   = max(1, (int)vpn_setting('vpn_probe_rate_firewall_per_hour', '20'));
        $inflightCap = 3;
        $oldest = static function (string $where, array $p) use ($since): int {
            $s = db()->prepare("SELECT UNIX_TIMESTAMP(MIN(created_at)) FROM vpn_diagnostic_runs WHERE created_at >= ? AND status <> 'refused' AND $where");
            $s->execute(array_merge([$since], $p));
            return (int)$s->fetchColumn();
        };
        if ($userId !== null) {
            $s = db()->prepare("SELECT COUNT(*) FROM vpn_diagnostic_runs WHERE user_id = ? AND created_at >= ? AND status <> 'refused'");
            $s->execute([$userId, $since]);
            $used = (int)$s->fetchColumn();
            if ($used + array_sum($legsByFirewall) > $perUser) {
                $o = $oldest('user_id = ?', [$userId]);
                return ['ok' => false, 'error' => "Probe limit reached for your account ($perUser per hour).", 'retry_after' => max(60, $o ? $o + 3600 - $now : 3600)];
            }
        }
        foreach ($legsByFirewall as $fwId => $n) {
            $s = db()->prepare("SELECT COUNT(*) FROM vpn_diagnostic_runs WHERE firewall_id = ? AND created_at >= ? AND status <> 'refused'");
            $s->execute([$fwId, $since]);
            if ((int)$s->fetchColumn() + $n > $perFw) {
                $o = $oldest('firewall_id = ?', [$fwId]);
                return ['ok' => false, 'error' => "Probe limit reached for this firewall ($perFw per hour, across all users).", 'retry_after' => max(60, $o ? $o + 3600 - $now : 3600)];
            }
            $s = db()->prepare("SELECT COUNT(*) FROM vpn_diagnostic_runs WHERE firewall_id = ? AND status = 'queued' AND created_at >= DATE_SUB(?, INTERVAL 15 MINUTE)");
            $s->execute([$fwId, date('Y-m-d H:i:s', $now)]);
            if ((int)$s->fetchColumn() + $n > $inflightCap) {
                return ['ok' => false, 'error' => "This firewall already has $inflightCap probes waiting for its agent; wait for them to finish.", 'retry_after' => 120];
            }
        }
        return ['ok' => true, 'error' => '', 'retry_after' => 0];
    }
}

if (!function_exists('vpn_probe_interpret')) {
    /**
     * Turn raw command output into an outcome.
     * outcome: reachable | unreachable | inconclusive
     * @return array{status:string, outcome:?string, summary:string}
     */
    function vpn_probe_interpret(string $method, string $cmdStatus, ?string $result): array {
        if (in_array($cmdStatus, ['pending'], true)) { return ['status' => 'queued', 'outcome' => null, 'summary' => 'Waiting for the agent to collect the probe.']; }
        if ($cmdStatus === 'sent') { return ['status' => 'queued', 'outcome' => null, 'summary' => 'Sent to the agent; waiting for the result.']; }
        if (in_array($cmdStatus, ['failed', 'cancelled'], true)) {
            return ['status' => 'failed', 'outcome' => 'inconclusive', 'summary' => 'The command ' . $cmdStatus . ' on the agent.'];
        }
        $out = (string)$result;
        $exit = preg_match('/OPNMGR_VPN_PROBE_EXIT=(\d+)/', $out, $m) ? (int)$m[1] : null;
        $done = fn(string $o, string $s) => ['status' => 'completed', 'outcome' => $o, 'summary' => $s];

        if ($method === 'icmp') {
            if (preg_match('/(\d+) packets transmitted, (\d+) (?:packets )?received/', $out, $p)) {
                $rtt = preg_match('/= ([\d.]+)\/([\d.]+)\/([\d.]+)/', $out, $r) ? sprintf(', avg %s ms', $r[2]) : '';
                return (int)$p[2] > 0
                    ? $done('reachable', "{$p[2]} of {$p[1]} echo replies{$rtt}.")
                    : $done('unreachable', "No echo replies ({$p[1]} sent).");
            }
            return $done('inconclusive', 'No ping statistics in the output.');
        }
        if ($method === 'tcp') {
            if ($exit === 0) { return $done('reachable', 'TCP connection succeeded.'); }
            if (stripos($out, 'refused') !== false) { return $done('reachable', 'The host answered with a refusal: the path works but nothing listens on that port (or a rule rejects it).'); }
            if ($exit !== null) { return $done('unreachable', 'No TCP connection within 3 seconds (filtered, no route, or host down).'); }
            return $done('inconclusive', 'No exit status in the output.');
        }
        if ($method === 'udp') {
            if (stripos($out, 'refused') !== false) { return $done('reachable', 'An ICMP port-unreachable came back: the host is reachable; that UDP port is closed.'); }
            if ($exit === 0) { return $done('inconclusive', 'No error came back. UDP has no positive acknowledgement, so this does not show the port is open.'); }
            return $done('inconclusive', 'UDP probe ended without a decisive answer.');
        }
        // traceroute
        $hops = 0; $last = null; $answered = 0;
        foreach (preg_split('/\R/', $out) as $line) {
            if (preg_match('/^\s*(\d+)\s+(.*)$/', $line, $h)) {
                $hops = (int)$h[1];
                if (!preg_match('/^\s*\*(\s+\*)*\s*$/', $h[2])) { $answered++; $last = trim($h[2]); }
            }
        }
        if ($hops === 0) { return $done('inconclusive', 'No hops in the traceroute output.'); }
        if ($answered === 0) { return $done('unreachable', "No hop answered ($hops probed)."); }
        return $done('inconclusive', "Reached hop $hops; last answer: " . substr((string)$last, 0, 120) . '. A traceroute that stops early may be filtered rather than broken.');
    }
}

if (!function_exists('vpn_probe_plan')) {
    /**
     * Resolve a request into legs without queueing anything.
     *
     * @param array $in firewall_id, dest, method, port?, size?, tunnel_id?, both_ends?
     * @param array $ctx topology, devices (by id)
     * @return array{ok:bool, error:string, legs:array, warnings:string[], params:array, tunnel:?array, policy:array}
     */
    function vpn_probe_plan(array $in, array $ctx): array {
        $fail = fn(string $e) => ['ok' => false, 'error' => $e, 'legs' => [], 'warnings' => [], 'params' => [], 'tunnel' => null, 'policy' => []];
        $v = vpn_probe_validate($in);
        if (!$v['ok']) { return $fail($v['error']); }
        $params = $v['params'];
        $warnings = $v['note'] ? [$v['note']] : [];
        $devices = $ctx['devices'] ?? [];
        $fwId = (int)($in['firewall_id'] ?? 0);
        $dev = $devices[$fwId] ?? null;
        if (!$dev) { return $fail('Unknown source firewall.'); }

        $tunnel = null;
        if (!empty($in['tunnel_id'])) {
            foreach ($ctx['topology']['tunnels'] ?? [] as $t) {
                if ($t['id'] === $in['tunnel_id']) { $tunnel = $t; break; }
            }
            if (!$tunnel) { return $fail('That tunnel is not in the current topology.'); }
            if (!in_array($fwId, $tunnel['firewall_ids'], true)) { return $fail('The chosen firewall is not an endpoint of that tunnel.'); }
        }
        $own = $peer = null;
        if ($tunnel) {
            [$own, $peer] = $tunnel['a']['firewall_id'] === $fwId ? [$tunnel['a'], $tunnel['b']] : [$tunnel['b'], $tunnel['a']];
        } else {
            foreach ($ctx['topology']['endpoints'] ?? [] as $ep) {
                if ($ep['firewall_id'] === $fwId && vpn_cidr_list_covers($ep['remote_networks'], $params['target'] . '/32')) { $own = $ep; break; }
            }
            if ($own) { $warnings[] = 'No tunnel was chosen; ' . $own['name'] . ' was inferred from the destination.'; }
        }

        $src = vpn_probe_source($dev, $own, $params['target']);
        $warnings = array_merge($warnings, $src['warnings']);
        if (!$src['ok']) { return $fail($src['reason']); }
        $leg1 = ['leg' => 'source', 'firewall_id' => $fwId, 'hostname' => $dev['hostname'],
                 'params' => $params + ($src['address'] ? ['source' => $src['address']] : []),
                 'source' => $src, 'tunnel_key' => $own['key'] ?? null];
        $legs = [$leg1];

        if (!empty($in['both_ends'])) {
            if ($peer && ($peerDev = $devices[$peer['firewall_id']] ?? null) && $src['address']) {
                // The reverse path: the far firewall pings the address the first probe came from.
                $psrc = vpn_probe_source($peerDev, $peer, $src['address']);
                if ($psrc['ok']) {
                    $pm = $params['method'] === 'traceroute' ? 'traceroute' : 'icmp';
                    $legs[] = ['leg' => 'peer', 'firewall_id' => $peer['firewall_id'], 'hostname' => $peerDev['hostname'],
                               'params' => ['method' => $pm, 'target' => $src['address'], 'source' => $psrc['address']],
                               'source' => $psrc, 'tunnel_key' => $peer['key']];
                } else { $warnings[] = 'Peer leg skipped: ' . $psrc['reason']; }
            } else {
                $warnings[] = 'Only one end of this path is a managed firewall, so no coordinated check on the far side is possible.';
            }
        }

        // What the rules say about a client doing the same thing (not what the probe tests).
        $policy = [];
        if (!empty($dev['config']) && $src['address']) {
            $lanIfs = array_column(vpn_protected_networks($dev['config']), 'iface');
            $r = vpn_policy_eval($dev['config'], $lanIfs, $src['address'], $params['target'], $params['method'] === 'traceroute' ? 'udp' : $params['method'], $params['port'] ?? null);
            $policy[] = ['where' => $dev['hostname'] . ' (LAN-side rules, hypothetical client at ' . $src['address'] . ')',
                         'verdict' => $r['verdict'], 'rule' => $r['rule']['description'] ?? ($r['rule']['id'] ?? null),
                         'uncertain' => $r['uncertain'], 'notes' => $r['notes']];
        }
        return ['ok' => true, 'error' => '', 'legs' => $legs, 'warnings' => $warnings, 'params' => $params,
                'tunnel' => $tunnel, 'policy' => $policy];
    }
}

if (!function_exists('vpn_probe_run')) {
    /**
     * Validate, rate-limit and queue a probe (and its coordinated peer leg).
     *
     * @param array $in  see vpn_probe_plan(); also incident_id?
     * @param array $actor user_id, username
     * @param array $ctx topology, devices; built live when omitted
     * @return array{ok:bool, error:string, run_group:?string, legs:array, warnings:string[], disclaimer:string, policy:array, retry_after?:int}
     */
    function vpn_probe_run(array $in, array $actor, array $ctx = []): array {
        $base = ['ok' => false, 'error' => '', 'run_group' => null, 'legs' => [], 'warnings' => [], 'disclaimer' => VPN_PROBE_DISCLAIMER, 'policy' => []];
        if (!$ctx) {
            $live = vpn_topology_live();
            $devices = [];
            foreach ($live['devices'] as $d) { $devices[$d['id']] = $d; }
            $ctx = ['topology' => $live['topology'], 'devices' => $devices];
        }
        $plan = vpn_probe_plan($in, $ctx);
        if (!$plan['ok']) { return ['error' => $plan['error']] + $base; }
        $base['warnings'] = $plan['warnings'];
        $base['policy'] = $plan['policy'];

        $userId = isset($actor['user_id']) ? (int)$actor['user_id'] : null;
        $scopeFn = $ctx['scope_check'] ?? 'scope_can_firewall';
        $perFw = [];
        $fws = [];
        foreach ($plan['legs'] as $leg) {
            $fw = platform_firewall($leg['firewall_id']);
            if (!$fw) { return ['error' => 'Firewall not found.'] + $base; }
            if (!$scopeFn($leg['firewall_id'])) { return ['error' => 'You do not have access to ' . $leg['hostname'] . '.'] + $base; }
            $allow = platform_capability_allows($fw, 'vpn.diagnostics');
            if (!$allow['allowed']) { return ['error' => $leg['hostname'] . ': ' . $allow['reason']] + $base; }
            $cmd = build_structured_command('vpn_probe', $leg['params'], $fw);
            if (!$cmd['ok']) { return ['error' => $leg['hostname'] . ': ' . $cmd['error']] + $base; }
            $fws[$leg['firewall_id']] = [$fw, $cmd];
            $perFw[$leg['firewall_id']] = ($perFw[$leg['firewall_id']] ?? 0) + 1;
        }
        $rate = vpn_probe_rate_check($userId, $perFw);
        if (!$rate['ok']) {
            audit_log('vpn.probe.rate_limited', ['success' => false, 'message' => $rate['error'], 'metadata' => ['firewalls' => array_keys($perFw)]]);
            return ['error' => $rate['error'], 'retry_after' => $rate['retry_after']] + $base;
        }

        $group = bin2hex(random_bytes(16));
        $incident = !empty($in['incident_id']) ? (int)$in['incident_id'] : null;
        foreach ($plan['legs'] as $leg) {
            [$fw, $cmd] = $fws[$leg['firewall_id']];
            $desc = sprintf('VPN probe %s -> %s%s', $leg['params']['method'], $leg['params']['target'], isset($leg['params']['port']) ? ':' . $leg['params']['port'] : '');
            $q = queue_firewall_command($leg['firewall_id'], $cmd['command'], $desc,
                ['action' => 'vpn_probe', 'parameters' => $leg['params'], 'is_raw' => false, 'risk' => 'LOW']);
            db()->prepare(
                'INSERT INTO vpn_diagnostic_runs
                   (run_group, leg, firewall_id, tunnel_key, method, target, port, source_address, source_interface, source_reason,
                    command_id, status, summary, incident_id, user_id, username)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$group, $leg['leg'], $leg['firewall_id'], $leg['tunnel_key'], $leg['params']['method'], $leg['params']['target'],
                $leg['params']['port'] ?? null, $leg['params']['source'] ?? null, $leg['source']['interface'] ?? null,
                substr($leg['source']['reason'], 0, 255), $q['ok'] ? $q['command_id'] : null, $q['ok'] ? 'queued' : 'refused',
                $q['ok'] ? 'Waiting for the agent to collect the probe.' : substr($q['error'], 0, 500),
                $incident, $userId, $actor['username'] ?? null]);
            $base['legs'][] = ['leg' => $leg['leg'], 'firewall_id' => $leg['firewall_id'], 'hostname' => $leg['hostname'],
                'method' => $leg['params']['method'], 'target' => $leg['params']['target'], 'port' => $leg['params']['port'] ?? null,
                'source_address' => $leg['params']['source'] ?? null, 'source_interface' => $leg['source']['interface'] ?? null,
                'source_reason' => $leg['source']['reason'], 'queued' => $q['ok'], 'error' => $q['error'] ?? '', 'command_id' => $q['command_id'] ?? 0];
        }
        audit_log('vpn.diagnostic.run', ['success' => true, 'message' => 'VPN probe queued: ' . count($base['legs']) . ' leg(s)',
            'metadata' => ['run_group' => $group, 'params' => $plan['params'], 'legs' => array_map(fn($l) => [$l['firewall_id'], $l['source_address']], $base['legs']), 'incident_id' => $incident]]);
        $base['ok'] = true;
        $base['run_group'] = $group;
        return $base;
    }
}

if (!function_exists('vpn_probe_sync')) {
    /**
     * Fold finished command results into the run rows. Idempotent; also expires
     * legs the agent never collected.
     * @return array[] the run's legs as stored
     */
    function vpn_probe_sync(string $runGroup): array {
        $s = db()->prepare('SELECT r.*, c.status AS cmd_status, c.result AS cmd_result, f.hostname
                              FROM vpn_diagnostic_runs r
                              LEFT JOIN firewall_commands c ON c.id = r.command_id
                              LEFT JOIN firewalls f ON f.id = r.firewall_id
                             WHERE r.run_group = ? ORDER BY r.id');
        $s->execute([$runGroup]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            if (in_array($r['status'], ['completed', 'failed', 'refused', 'expired'], true) || $r['command_id'] === null) { continue; }
            $i = vpn_probe_interpret($r['method'], (string)$r['cmd_status'], $r['cmd_result']);
            if ($i['status'] === 'queued' && strtotime($r['created_at']) < time() - 1800) {
                $i = ['status' => 'expired', 'outcome' => null, 'summary' => 'The agent did not collect this probe within 30 minutes.'];
            }
            if ($i['status'] !== 'queued') {
                db()->prepare('UPDATE vpn_diagnostic_runs SET status = ?, outcome = ?, summary = ?, result_excerpt = ?, completed_at = NOW() WHERE id = ?')
                    ->execute([$i['status'], $i['outcome'], $i['summary'], mb_substr((string)$r['cmd_result'], 0, 2000), $r['id']]);
                $r['status'] = $i['status']; $r['outcome'] = $i['outcome']; $r['summary'] = $i['summary'];
                $r['result_excerpt'] = mb_substr((string)$r['cmd_result'], 0, 2000);
            } else { $r['summary'] = $i['summary']; }
        }
        unset($r);
        return array_map(function ($r) { unset($r['cmd_result']); return $r; }, $rows);
    }
}
