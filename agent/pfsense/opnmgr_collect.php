<?php
/*
 * OPNManager agent for pfSense - telemetry collector.
 *
 * Prints one JSON object: the check-in payload minus identity fields, which
 * the shell loop adds. Read-only: nothing here writes configuration.
 *
 * Uses pfSense's own PHP includes (config.inc, gwlb.inc, service-utils.inc,
 * ipsec.inc, openvpn.inc) where they exist, and plain FreeBSD tools
 * otherwise. Every collector is independent: a failure omits that section
 * (meaning "unknown") rather than reporting it empty (meaning "none").
 *
 * Private keys and pre-shared secrets are never read. Certificates are parsed
 * from <crt> only; <prv> is not touched.
 *
 * Usage: /usr/local/bin/php -f opnmgr_collect.php [--platform-only]
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

$have_pfsense = @include_once('config.inc');
if ($have_pfsense) {
    @include_once('util.inc');
    @include_once('gwlb.inc');
    @include_once('service-utils.inc');
    @include_once('interfaces.inc');
}

function sh(string $cmd): string {
    $out = @shell_exec($cmd . ' 2>/dev/null');
    return is_string($out) ? trim($out) : '';
}

function cfg(string $path, $default = null) {
    if (function_exists('config_get_path')) {
        return config_get_path($path, $default);
    }
    global $config;
    $node = $config ?? [];
    foreach (explode('/', $path) as $k) {
        if (!is_array($node) || !array_key_exists($k, $node)) {
            return $default;
        }
        $node = $node[$k];
    }
    return $node;
}

// --- platform ---------------------------------------------------------------
function collect_platform(): array {
    $version = trim((string)@file_get_contents('/etc/version'));
    $patch = trim((string)@file_get_contents('/etc/version.patch'));
    if ($patch !== '' && $patch !== '0' && ctype_digit($patch)) {
        $version .= '-p' . $patch;
    }
    $globals = (string)@file_get_contents('/etc/inc/globals.inc');
    $restore = false;
    if (@include_once('config.lib.inc')) {
        $restore = function_exists('config_restore');
    }
    $pkgs = array_values(array_filter(explode("\n", sh("pkg query '%n-%v'")), function ($p) {
        return preg_match('/^(pfSense-|wireguard|strongswan|openvpn|frr|haproxy|nexus)/i', $p);
    }));
    return [
        'family'          => 'pfsense',
        'agent_flavor'    => 'pfsense-agent',
        'product_version' => $version,
        'base_version'    => sh('freebsd-version -u'),
        'config_version'  => (string)cfg('version', ''),
        'markers'         => [
            // Product evidence observed on this box, not assumed by the agent.
            'pfsense_globals'      => (bool)preg_match("/'xml_rootobj'\s*=>\s*'pfsense'/", $globals),
            'plus_globals'         => file_exists('/etc/inc/globals.plus.inc'),
            'opnsense_version_bin' => is_executable('/usr/local/sbin/opnsense-version'),
        ],
        'packages'        => array_slice($pkgs, 0, 80),
        'probes'          => [
            'php'                    => true,
            'pfssh'                  => is_executable('/usr/local/sbin/pfSsh.php'),
            'pfsense_config_restore' => $restore,
            'config_get_path'        => function_exists('config_get_path'),
            'wg'                     => is_executable('/usr/local/bin/wg'),
            'swanctl'                => is_executable('/usr/local/sbin/swanctl'),
            'openssl'                => is_executable('/usr/bin/openssl'),
            'daemon'                 => is_executable('/usr/sbin/daemon'),
            'configctl'              => is_executable('/usr/local/sbin/configctl'),
            // Recorded so the manager can say Nexus is present; OPNMGR does
            // not use the Nexus API (see docs/PLATFORMS.md).
            'netgate_nexus_pkg'      => (bool)preg_grep('/nexus/i', $pkgs),
        ],
    ];
}

// --- system -----------------------------------------------------------------
function dotted_mask(string $m): string {
    if (strpos($m, '0x') === 0) {
        $n = hexdec(substr($m, 2));
        return long2ip((int)$n);
    }
    return $m;
}

function collect_system(): array {
    $wan_if = sh("route -n get default | awk '/interface:/ {print \$2}'");
    $wan_ip = $wan_if !== '' ? sh("ifconfig " . escapeshellarg($wan_if) . " | awk '/inet /{print \$2; exit}'") : '';
    $wan_mask = $wan_if !== '' ? dotted_mask(sh("ifconfig " . escapeshellarg($wan_if) . " | awk '/inet /{print \$4; exit}'")) : '';
    $gw = sh("route -n get default | awk '/gateway:/ {print \$2}'");
    $dns = array_values(array_filter(array_map(function ($l) {
        return preg_match('/^nameserver\s+(\S+)/', $l, $m) ? $m[1] : null;
    }, @file('/etc/resolv.conf') ?: [])));

    // LAN: pfSense names it; no guessing from RFC1918 order.
    $lan_ip = (string)cfg('interfaces/lan/ipaddr', '');
    $lan_bits = (string)cfg('interfaces/lan/subnet', '');
    $lan_mask = $lan_network = '';
    if (filter_var($lan_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && ctype_digit($lan_bits)) {
        $mask = $lan_bits === '0' ? 0 : (~0 << (32 - (int)$lan_bits)) & 0xFFFFFFFF;
        $lan_mask = long2ip($mask);
        $lan_network = long2ip(ip2long($lan_ip) & $mask);
    } else {
        $lan_ip = '';
    }

    $v6 = sh("ifconfig | awk '/inet6 / && \$2 !~ /^fe80|^::1/ {print \$2; exit}'");
    $load = preg_split('/\s+/', trim(sh('sysctl -n vm.loadavg'), '{} '));
    $physmem = (int)sh('sysctl -n hw.physmem');
    $pages = (int)sh('sysctl -n vm.stats.vm.v_active_count') + (int)sh('sysctl -n vm.stats.vm.v_inactive_count')
           + (int)sh('sysctl -n vm.stats.vm.v_laundry_count');
    $psize = (int)sh('sysctl -n hw.pagesize') ?: 4096;
    $total_mb = (int)($physmem / 1048576);
    $used_mb = (int)($pages * $psize / 1048576);
    $df = preg_split('/\s+/', sh('df -k / | tail -1'));

    return [
        'wan_ip' => $wan_ip, 'lan_ip' => $lan_ip, 'ipv6_address' => $v6,
        // Same key the OPNsense agent uses; the server stores it as the
        // product version for either platform.
        'opnsense_version' => trim((string)@file_get_contents('/etc/version')),
        'uptime' => sh("uptime | sed 's/.*up //; s/,.*//'"),
        'hostname' => sh('hostname'),
        'wan_interfaces' => $wan_if, 'wan_groups' => '',
        'wan_netmask' => $wan_mask, 'wan_gateway' => $gw,
        'wan_dns_primary' => $dns[0] ?? '', 'wan_dns_secondary' => $dns[1] ?? '',
        'lan_netmask' => $lan_mask, 'lan_network' => $lan_network,
        'system_stats' => [
            'cpu_load_1min' => (float)($load[0] ?? 0), 'cpu_load_5min' => (float)($load[1] ?? 0),
            'cpu_load_15min' => (float)($load[2] ?? 0),
            'memory_total_mb' => $total_mb, 'memory_used_mb' => $used_mb,
            'memory_percent' => $total_mb > 0 ? (int)($used_mb * 100 / $total_mb) : 0,
            'disk_total_gb' => (int)(((int)($df[1] ?? 0)) / 1048576),
            'disk_used_gb' => (int)(((int)($df[2] ?? 0)) / 1048576),
            'disk_percent' => (int)rtrim($df[4] ?? '0', '%'),
        ],
        'traffic_stats' => collect_traffic($wan_if),
    ];
}

function collect_traffic(string $if): array {
    if ($if === '') {
        return ['interface' => 'unknown', 'bytes_in' => 0, 'bytes_out' => 0, 'packets_in' => 0, 'packets_out' => 0];
    }
    $line = sh("netstat -ibn -I " . escapeshellarg($if) . " | awk '/<Link/ {print; exit}'");
    $f = preg_split('/\s+/', $line);
    return [
        'interface' => $if,
        'bytes_in' => (int)($f[7] ?? 0), 'bytes_out' => (int)($f[10] ?? 0),
        'packets_in' => (int)($f[4] ?? 0), 'packets_out' => (int)($f[8] ?? 0),
    ];
}

// --- health -----------------------------------------------------------------
function num($v) {
    return preg_match('/[-+]?\d*\.?\d+/', (string)$v, $m) ? (float)$m[0] : null;
}

function collect_gateways(): ?array {
    if (!function_exists('return_gateways_status')) {
        return null;
    }
    $status = return_gateways_status(true);
    $defs = function_exists('return_gateways_array') ? return_gateways_array() : [];
    if (!is_array($status)) {
        return null;
    }
    $rows = [];
    foreach ($status as $name => $gw) {
        $def = $defs[$name] ?? [];
        $s = strtolower((string)($gw['status'] ?? 'unknown'));
        $rows[] = [
            'name' => substr((string)$name, 0, 64),
            'interface' => substr((string)($def['interface'] ?? ''), 0, 64) ?: null,
            'address' => substr((string)($def['gateway'] ?? ''), 0, 45) ?: null,
            'monitor' => substr((string)($gw['monitorip'] ?? ''), 0, 45) ?: null,
            // pfSense: none = online, down, loss, delay, highloss, highdelay.
            'status' => $s === 'none' ? 'online' : $s,
            'latency_ms' => num($gw['delay'] ?? null),
            'stddev_ms' => num($gw['stddev'] ?? null),
            'loss_percent' => num($gw['loss'] ?? null),
            'is_default' => !empty($def['isdefaultgw']),
            'priority' => null,
        ];
    }
    return $rows;
}

function collect_services(): ?array {
    if (!function_exists('get_services') || !function_exists('get_service_status')) {
        return null;
    }
    $out = [];
    foreach (get_services() as $svc) {
        if (empty($svc['name'])) {
            continue;
        }
        $name = substr((string)$svc['name'], 0, 64);
        $running = (bool)get_service_status($svc);
        if (isset($out[$name])) {
            $out[$name]['running'] = $out[$name]['running'] && $running;
            continue;
        }
        $out[$name] = ['name' => $name, 'description' => substr((string)($svc['description'] ?? ''), 0, 128) ?: null,
                       'running' => $running, 'enabled' => true];
    }
    ksort($out);
    return array_values($out);
}

function collect_vpn(): array {
    $tunnels = [];
    // WireGuard: same dump format as on OPNsense.
    if (is_executable('/usr/local/bin/wg')) {
        foreach (explode("\n", sh('/usr/local/bin/wg show all dump')) as $line) {
            $p = explode("\t", $line);
            if (count($p) < 9) {
                continue;
            }
            [$iface, $pub, , $endpoint, $allowed, $hs, $rx, $tx] = $p;
            $hs = (int)$hs;
            $tunnels[] = [
                'type' => 'wireguard', 'name' => $iface . ':' . substr($pub, 0, 12),
                'peer' => substr($pub, 0, 44), 'endpoint' => $endpoint !== '(none)' ? $endpoint : null,
                'allowed_ips' => $allowed !== '(none)' ? $allowed : null,
                'status' => ($hs && time() - $hs < 180) ? 'up' : 'down',
                'latest_handshake' => $hs ?: null,
                'rx_bytes' => ctype_digit($rx) ? (int)$rx : null, 'tx_bytes' => ctype_digit($tx) ? (int)$tx : null,
            ];
        }
    }
    // IPsec through pfSense's own vici wrapper.
    if (@include_once('ipsec.inc')) {
        if (function_exists('ipsec_list_sa')) {
            foreach ((array)ipsec_list_sa() as $con => $sa) {
                $tunnels[] = [
                    'type' => 'ipsec', 'name' => substr((string)$con, 0, 128),
                    'status' => strtoupper((string)($sa['state'] ?? '')) === 'ESTABLISHED' ? 'up' : 'down',
                    'peer' => substr((string)($sa['remote-host'] ?? ''), 0, 255) ?: null,
                ];
            }
        }
    }
    // OpenVPN instances.
    if (@include_once('openvpn.inc')) {
        foreach (['openvpn_get_active_servers', 'openvpn_get_active_clients'] as $fn) {
            if (!function_exists($fn)) {
                continue;
            }
            foreach ((array)$fn() as $inst) {
                $tunnels[] = [
                    'type' => 'openvpn',
                    'name' => substr((string)($inst['name'] ?? ($inst['vpnid'] ?? 'openvpn')), 0, 128),
                    'status' => (isset($inst['status']) ? strtolower((string)$inst['status']) === 'up'
                                 : !empty($inst['conns'])) ? 'up' : 'down',
                    'peer' => substr((string)($inst['remote_host'] ?? ''), 0, 255) ?: null,
                ];
            }
        }
    }
    return $tunnels;
}

function collect_carp(): ?array {
    $out = sh('ifconfig');
    if ($out === '') {
        return null;
    }
    $vhids = [];
    $iface = null;
    foreach (explode("\n", $out) as $line) {
        if ($line !== '' && !ctype_space($line[0])) {
            $iface = strtok($line, ':');
            continue;
        }
        if (preg_match('/carp:\s+(\w+)\s+vhid\s+(\d+)(?:\s+advbase\s+(\d+))?(?:\s+advskew\s+(\d+))?/', $line, $m)) {
            $vhids[] = ['vhid' => $m[2], 'state' => strtoupper($m[1]), 'interface' => $iface,
                        'advbase' => isset($m[3]) ? (int)$m[3] : null, 'advskew' => isset($m[4]) ? (int)$m[4] : null];
        }
    }
    $carp = ['vhids' => $vhids];
    $peer = (string)cfg('hasync/synchronizetoip', '');
    if ($peer !== '') {
        $carp['peer_host'] = substr($peer, 0, 255);
    }
    return $carp;
}

function collect_certificates(): ?array {
    $certs = cfg('cert', null);
    $cas = cfg('ca', null);
    if ($certs === null && $cas === null) {
        return null;
    }
    $webref = (string)cfg('system/webgui/ssl-certref', '');
    $out = [];
    foreach ([['certificate', (array)$certs], ['ca', (array)$cas]] as [$type, $list]) {
        foreach ($list as $c) {
            if (!is_array($c) || empty($c['refid'])) {
                continue;
            }
            // PUBLIC certificate only. $c['prv'] is never read.
            $parsed = !empty($c['crt']) && function_exists('openssl_x509_parse')
                ? @openssl_x509_parse(base64_decode((string)$c['crt'])) : false;
            $row = ['refid' => substr($c['refid'], 0, 64), 'name' => substr((string)($c['descr'] ?? ''), 0, 255) ?: null,
                    'type' => $type, 'in_use' => $c['refid'] === $webref ? 'yes' : 'no'];
            if (is_array($parsed)) {
                $row['issuer'] = substr((string)($parsed['issuer']['CN'] ?? ''), 0, 255) ?: null;
                $row['subject'] = substr((string)($parsed['subject']['CN'] ?? ''), 0, 255) ?: null;
                $row['not_before'] = $parsed['validFrom_time_t'] ?? null;
                $row['not_after'] = $parsed['validTo_time_t'] ?? null;
            }
            $out[] = $row;
        }
    }
    return $out;
}

// --- main -------------------------------------------------------------------
$payload = ['platform' => collect_platform()];
if (!in_array('--platform-only', $argv ?? [], true)) {
    $payload = array_merge(collect_system(), $payload);
    $health = [];
    foreach (['gateways' => 'collect_gateways', 'vpn' => 'collect_vpn', 'carp' => 'collect_carp',
              'services' => 'collect_services', 'certificates' => 'collect_certificates'] as $k => $fn) {
        try {
            $v = $fn();
        } catch (Throwable $e) {
            $v = null;
        }
        if ($v !== null) {
            $health[$k] = $v;
        }
    }
    $payload['health'] = $health;
}
echo json_encode($payload, JSON_UNESCAPED_SLASHES), "\n";
