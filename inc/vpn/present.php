<?php
/**
 * VPN topology: the shape sent to the browser.
 *
 * Only fields the UI needs, and only fields that are safe to show: the
 * normalized model has no secrets, and this adds a second filter by building
 * the payload from an allowlist rather than by trimming the model.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/topology.php';

if (!function_exists('vpn_endpoint_public')) {
    function vpn_endpoint_public(?array $e): ?array {
        if ($e === null) { return null; }
        $rt = $e['runtime'] ?? null;
        $pk = $e['remote_ids']['public_key'] ?? null;
        return [
            'key' => $e['key'], 'firewall_id' => $e['firewall_id'], 'firewall_name' => $e['firewall_name'] ?? '',
            'name' => $e['name'], 'kind' => $e['kind'], 'protocol' => $e['protocol'], 'enabled' => (bool)$e['enabled'],
            'role' => $e['role'], 'role_reason' => $e['role_reason'], 'role_source' => $e['role_source'],
            'instance' => $e['instance'], 'tunnel_ifaces' => $e['tunnel_ifaces'],
            'tunnel_addresses' => array_merge($e['tunnel_nets'] ?? [], array_map(fn($i) => $i . '/32', $e['tunnel_ips'] ?? [])),
            'local_networks' => $e['local_networks'], 'local_networks_basis' => $e['local_networks_basis'],
            'remote_networks' => $e['remote_networks'], 'allowed_ips' => $e['allowed_ips'],
            'selectors' => $e['selectors'],
            'remote' => ['host' => $e['remote_ids']['host'] ?? null, 'port' => $e['remote_ids']['port'] ?? null,
                         'gateway' => $e['remote_ids']['gateway'] ?? null,
                         'public_key' => $pk ? (strlen($pk) > 12 ? substr($pk, 0, 8) . '…' . substr($pk, -4) : $pk) : null],
            'details' => $e['details'], 'config_present' => $e['config_present'], 'source' => $e['source'],
            'state' => $e['state'],
            'runtime' => $rt ? ['status' => $rt['status'] ?? null, 'latest_handshake' => $rt['latest_handshake'] ?? null,
                                'rx_bytes' => $rt['rx_bytes'] ?? null, 'tx_bytes' => $rt['tx_bytes'] ?? null,
                                'endpoint' => $rt['endpoint'] ?? null, 'connected_since' => $rt['connected_since'] ?? null] : null,
        ];
    }
}

if (!function_exists('vpn_open_vpn_incidents')) {
    /** Open incidents per firewall, for linking from tunnels. */
    function vpn_open_vpn_incidents(array $firewallIds): array {
        if (!$firewallIds) { return []; }
        $out = [];
        try {
            $in = implode(',', array_fill(0, count($firewallIds), '?'));
            $s = db()->prepare("SELECT id, firewall_id, alert_type, object_key, severity, title FROM alert_incidents
                                 WHERE status IN ('open','acknowledged') AND firewall_id IN ($in) ORDER BY id DESC LIMIT 500");
            $s->execute(array_values($firewallIds));
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[(int)$r['firewall_id']][] = $r; }
        } catch (Throwable $e) {}
        return $out;
    }
}

if (!function_exists('vpn_payload')) {
    /**
     * @param array      $topology scoped topology
     * @param array      $findings scoped findings
     */
    function vpn_payload(array $topology, array $findings, int $hiddenFindings = 0): array {
        $fwIds = array_column($topology['devices'], 'id');
        $inc = vpn_open_vpn_incidents($fwIds);
        $tunnels = [];
        $counts = ['state' => [], 'kind' => [], 'relationship' => []];
        foreach ($topology['tunnels'] as $t) {
            $related = [];
            foreach ($t['firewall_ids'] as $fid) {
                foreach ($inc[$fid] ?? [] as $i) {
                    $mine = str_starts_with((string)$i['alert_type'], 'vpn') || in_array($i['object_key'], [$t['a']['name'] ?? null, $t['b']['name'] ?? null], true);
                    if ($mine) { $related[] = ['id' => (int)$i['id'], 'severity' => $i['severity'], 'title' => $i['title'], 'url' => '/incidents.php?incident=' . (int)$i['id']]; }
                }
            }
            $links = ['health' => [], 'details' => [], 'diagnostics' => '/vpn_diagnostics.php?firewall=' . $t['firewall_ids'][0] . '&tunnel=' . $t['id']];
            foreach ($t['firewall_ids'] as $fid) {
                $links['health'][$fid] = '/firewall_health.php?firewall=' . $fid;
                $links['details'][$fid] = '/firewall_details.php?id=' . $fid;
            }
            $tunnels[] = [
                'id' => $t['id'], 'kind' => $t['kind'], 'protocol' => $t['protocol'], 'name' => $t['name'],
                'relationship' => $t['relationship'], 'confidence' => $t['confidence'], 'match_score' => $t['match_score'],
                'state' => $t['state'], 'partial' => $t['partial'], 'evidence' => $t['evidence'],
                'firewall_ids' => $t['firewall_ids'], 'customer_ids' => $t['customer_ids'], 'site_ids' => $t['site_ids'], 'platforms' => $t['platforms'],
                'a' => vpn_endpoint_public($t['a']), 'b' => vpn_endpoint_public($t['b']),
                'collected' => $t['collected'], 'incidents' => array_slice($related, 0, 5), 'links' => $links,
                'findings' => [],
            ];
            foreach (['state' => $t['state'], 'kind' => $t['kind'], 'relationship' => $t['relationship']] as $k => $v) {
                $counts[$k][$v] = ($counts[$k][$v] ?? 0) + 1;
            }
        }
        $byTunnel = [];
        foreach ($findings as $f) { foreach ($f['affected']['tunnels'] as $tid) { $byTunnel[$tid][] = $f['id']; } }
        foreach ($tunnels as &$t) { $t['findings'] = $byTunnel[$t['id']] ?? []; }
        unset($t);

        $custs = []; $sites = [];
        foreach ($topology['devices'] as $d) {
            if ($d['customer_id']) { $custs[$d['customer_id']] = $d['customer_name']; }
            if ($d['site_id']) { $sites[$d['site_id']] = $d['site_name']; }
        }
        return [
            'generated_at' => $topology['generated_at'], 'tunnels' => $tunnels, 'graph' => vpn_graph($topology),
            'devices' => $topology['devices'], 'findings' => $findings, 'hidden_findings' => $hiddenFindings,
            'counts' => $counts, 'customers' => $custs, 'sites' => $sites, 'states' => vpn_state_meta(),
        ];
    }
}
