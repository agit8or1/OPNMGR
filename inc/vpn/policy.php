<?php
/**
 * VPN topology: approximate firewall-rule evaluation for a hypothetical flow.
 *
 * This answers "what would the configured rules most plausibly do to a packet
 * from SRC to DST on these interfaces". It is a reading aid, not a packet
 * simulator, and says so: aliases, schedules, tags, GeoIP and non-quick
 * floating semantics are not resolved, and any rule that cannot be judged
 * makes the answer `uncertain` instead of being assumed to match or not.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/util.php';

if (!function_exists('vpn_rule_addr_match')) {
    /**
     * @return bool|null true/false when decidable, null when it depends on
     *                   something not modelled (an alias, an unknown keyword)
     */
    function vpn_rule_addr_match(array $ep, string $ip, array $cfg) {
        $net = trim((string)($ep['net'] ?? 'any'));
        $not = !empty($ep['not']);
        if ($net === '' || $net === 'any') { return $not ? false : true; }
        $r = null;
        if (vpn_cidr($net)) {
            $r = vpn_cidr_contains($net, $ip . '/32');
        } elseif (preg_match('/^(\w+?)(ip)?$/', $net, $m) && isset($cfg['interfaces'][$m[1]])) {
            $if = $cfg['interfaces'][$m[1]];
            if (!empty($m[2])) { $r = ($if['ipv4'] ?? '') === $ip; }
            elseif (!empty($if['network'])) { $r = vpn_cidr_contains($if['network'], $ip . '/32'); }
        }
        if ($r === null) { return null; }   // alias or unknown token
        return $not ? !$r : $r;
    }
}

if (!function_exists('vpn_rule_port_match')) {
    function vpn_rule_port_match(?string $spec, ?int $port) {
        $spec = trim((string)$spec);
        if ($spec === '' || $spec === 'any') { return true; }
        if ($port === null) { return true; }
        if (ctype_digit($spec)) { return (int)$spec === $port; }
        if (preg_match('/^(\d+)[-:](\d+)$/', $spec, $m)) { return $port >= (int)$m[1] && $port <= (int)$m[2]; }
        return null;   // alias
    }
}

if (!function_exists('vpn_policy_eval')) {
    /**
     * @param array  $cfg    config slice
     * @param array  $ifaces logical interface names the packet arrives on
     * @param string $proto  icmp | tcp | udp | any
     * @return array{verdict:string, rule:?array, uncertain:bool, notes:string[]}
     *         verdict: pass | block | default_deny
     */
    function vpn_policy_eval(array $cfg, array $ifaces, string $src, string $dst, string $proto = 'any', ?int $port = null): array {
        $notes = []; $uncertain = false;
        $rules = array_values(array_filter($cfg['filter_rules'] ?? [], fn($r) => !empty($r['enabled'])));
        // Floating quick rules are evaluated before interface rules.
        usort($rules, function ($a, $b) {
            $fa = !empty($a['floating']) && !empty($a['quick']) ? 0 : 1;
            $fb = !empty($b['floating']) && !empty($b['quick']) ? 0 : 1;
            return [$fa, $a['section'] ?? '', $a['order'] ?? 0] <=> [$fb, $b['section'] ?? '', $b['order'] ?? 0];
        });
        foreach ($rules as $r) {
            $ri = $r['interface'] ?? [];
            $onIf = $ri ? (bool)array_intersect($ri, $ifaces) : !empty($r['floating']);
            if (!empty($r['interface_not'])) { $onIf = !$onIf; }
            if (!$onIf) { continue; }
            if (!in_array($r['direction'] ?? 'in', ['in', 'any'], true)) { continue; }
            if (($r['ipprotocol'] ?? 'inet') === 'inet6') { continue; }
            $rp = strtolower((string)($r['protocol'] ?? 'any'));
            $protoOk = $rp === '' || $rp === 'any' || $rp === $proto
                || ($rp === 'tcp/udp' && in_array($proto, ['tcp', 'udp'], true));
            if (!$protoOk && $proto !== 'any') { continue; }
            if (!empty($r['floating']) && empty($r['quick'])) { $notes[] = 'A non-quick floating rule was skipped (last-match semantics are not modelled).'; continue; }

            $m = [
                vpn_rule_addr_match($r['source'] ?? [], $src, $cfg),
                vpn_rule_addr_match($r['destination'] ?? [], $dst, $cfg),
                in_array($proto, ['tcp', 'udp'], true) ? vpn_rule_port_match($r['destination']['port'] ?? null, $port) : true,
            ];
            if (in_array(false, $m, true)) { continue; }
            if (in_array(null, $m, true)) { $uncertain = true; $notes[] = 'Rule "' . ($r['description'] ?? $r['id'] ?? '?') . '" uses an alias or token that was not resolved.'; continue; }
            $act = strtolower((string)($r['action'] ?? 'pass'));
            return ['verdict' => $act === 'pass' ? 'pass' : 'block', 'rule' => $r, 'uncertain' => $uncertain,
                    'notes' => array_values(array_unique($notes))];
        }
        return ['verdict' => 'default_deny', 'rule' => null, 'uncertain' => $uncertain, 'notes' => array_values(array_unique($notes))];
    }
}
