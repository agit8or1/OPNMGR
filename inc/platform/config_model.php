<?php
/**
 * OPNMGR normalized configuration model.
 *
 * Reads an OPNsense or pfSense config.xml into one internal representation for
 * templates, VPN topology and diagnostics. The raw XML is never modified here
 * and is always kept alongside this model: the model is for reading and
 * planning, the raw file is what a firewall actually runs.
 *
 * What is deliberately NOT flattened:
 *
 *   - Rule order. Every rule keeps its section and its position within it.
 *     OPNsense 26.x evaluates MVC rules (OPNsense/Firewall/Filter) and legacy
 *     <filter> rules as separate sections; pfSense has one <filter> list where
 *     floating rules are evaluated before interface rules. Both facts are kept.
 *   - NAT semantics. Port forwards, outbound NAT (with its mode) and 1:1 stay
 *     separate lists, each with its own fields.
 *   - Direction and quick. Floating rules carry them; interface rules are
 *     implicitly "in" + quick and are recorded that way rather than guessed.
 *   - VPN differences. WireGuard on OPNsense is MVC (servers/clients); on
 *     pfSense it is the WireGuard package (tunnels/peers). IPsec is legacy
 *     phase1/phase2 on pfSense and either legacy or Swanctl on OPNsense.
 *
 * Anything platform-specific that does not map is kept under `platform_fields`.
 *
 * SECRETS: only allow-listed fields are copied. Private keys, pre-shared keys,
 * passwords and TLS keys never enter the model; where their presence matters
 * (e.g. "this peer has a PSK") a boolean is recorded instead.
 *
 * @since 3.79.0
 */

if (!function_exists('platform_parse_xml')) {
    /**
     * Parse config XML safely: no network, no external entities.
     */
    function platform_parse_xml(string $xml): ?SimpleXMLElement {
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false) {
            // A firewall config never carries a DTD. Refusing one closes off
            // entity expansion tricks without depending on libxml defaults.
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        $node = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $node === false ? null : $node;
    }
}

if (!function_exists('platform_xml_text')) {
    function platform_xml_text($node, string $child, ?string $default = null): ?string {
        if ($node === null || !isset($node->{$child})) {
            return $default;
        }
        $v = trim((string)$node->{$child});
        return $v === '' ? $default : $v;
    }
}

if (!function_exists('platform_xml_flag')) {
    /** A pfSense/legacy style presence flag: <disabled/> or <disabled>1</disabled>. */
    function platform_xml_flag($node, string $child): bool {
        if ($node === null || !isset($node->{$child})) {
            return false;
        }
        $v = strtolower(trim((string)$node->{$child}));
        return $v === '' || $v === '1' || $v === 'yes' || $v === 'on' || $v === 'true';
    }
}

if (!function_exists('platform_xml_bool01')) {
    /** An MVC style boolean: <enabled>1</enabled>. Absent = $default. */
    function platform_xml_bool01($node, string $child, bool $default = false): bool {
        if ($node === null || !isset($node->{$child})) {
            return $default;
        }
        return trim((string)$node->{$child}) === '1';
    }
}

if (!function_exists('platform_split_list')) {
    function platform_split_list(?string $v, string $pattern = '/[\s,]+/'): array {
        if ($v === null || trim($v) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', preg_split($pattern, $v)), fn($s) => $s !== ''));
    }
}

if (!function_exists('platform_legacy_endpoint')) {
    /**
     * Legacy <source>/<destination> block, shared by OPNsense legacy rules and
     * pfSense: one of <any/>, <network>lan</network> or <address>1.2.3.4</address>.
     */
    function platform_legacy_endpoint($node): array {
        if ($node === null) {
            return ['net' => 'any', 'kind' => 'any', 'port' => null, 'not' => false];
        }
        if (isset($node->network)) {
            $net = (string)$node->network;
            $kind = 'network';
        } elseif (isset($node->address)) {
            $net = (string)$node->address;
            $kind = 'address';
        } else {
            $net = 'any';
            $kind = 'any';
        }
        return [
            'net'  => $net,
            'kind' => $kind,
            'port' => platform_xml_text($node, 'port'),
            'not'  => isset($node->not),
        ];
    }
}

if (!function_exists('platform_normalize_config')) {
    /**
     * Normalize a config.xml.
     *
     * @param string      $xml
     * @param string|null $expectedFamily If given and the root disagrees, the
     *                                    model carries an error rather than a
     *                                    best guess.
     * @return array The model. `ok` false with `error` when unparseable.
     */
    function platform_normalize_config(string $xml, ?string $expectedFamily = null): array {
        $root = platform_parse_xml($xml);
        if ($root === null) {
            return ['ok' => false, 'error' => 'Configuration is not well-formed XML'];
        }

        $rootName = strtolower($root->getName());
        $family = match ($rootName) {
            'opnsense' => 'opnsense',
            'pfsense'  => 'pfsense',
            default    => null,
        };
        if ($family === null) {
            return ['ok' => false, 'error' => 'Unrecognised configuration root <' . substr($rootName, 0, 32) . '>'];
        }
        if ($expectedFamily !== null && $expectedFamily !== $family) {
            return ['ok' => false, 'error' => sprintf(
                'Configuration is %s but the firewall is recorded as %s', $family, $expectedFamily)];
        }

        $model = [
            'ok'             => true,
            'platform'       => $family,
            'root'           => $rootName,
            'config_version' => platform_xml_text($root, 'version'),
            'fingerprint'    => hash('sha256', $xml),
            'system'         => platform_norm_system($root, $family),
            'interfaces'     => platform_norm_interfaces($root),
            'aliases'        => platform_norm_aliases($root, $family),
            'filter_rules'   => platform_norm_filter($root, $family),
            'nat'            => platform_norm_nat($root, $family),
            'routes'         => platform_norm_routes($root),
            'gateways'       => platform_norm_gateways($root, $family),
            'vpn'            => [
                'wireguard' => platform_norm_wireguard($root, $family),
                'ipsec'     => platform_norm_ipsec($root, $family),
                'openvpn'   => platform_norm_openvpn($root, $family),
            ],
            'ha'             => platform_norm_ha($root, $family),
            'logging'        => platform_norm_logging($root, $family),
            'warnings'       => [],
        ];

        return $model;
    }
}

// --- system -----------------------------------------------------------------

if (!function_exists('platform_norm_system')) {
    function platform_norm_system(SimpleXMLElement $root, string $family): array {
        $sys = $root->system ?? null;
        $dns = [];
        if ($sys !== null) {
            foreach ($sys->dnsserver as $d) {
                $d = trim((string)$d);
                if ($d !== '') { $dns[] = $d; }
            }
        }
        $ntp = platform_split_list(platform_xml_text($sys, 'timeservers'), '/\s+/');

        $mgmt = [];
        if ($sys !== null && isset($sys->webgui)) {
            $mgmt = [
                'protocol' => platform_xml_text($sys->webgui, 'protocol'),
                'port'     => platform_xml_text($sys->webgui, 'port'),
                // OPNsense: comma separated interface list the GUI listens on.
                'interfaces' => platform_split_list(platform_xml_text($sys->webgui, 'interfaces')),
            ];
        }

        return [
            'hostname'    => platform_xml_text($sys, 'hostname'),
            'domain'      => platform_xml_text($sys, 'domain'),
            'timezone'    => platform_xml_text($sys, 'timezone'),
            'dns_servers' => $dns,
            'ntp_servers' => $ntp,
            'webgui'      => $mgmt,
            'ssh_enabled' => $family === 'opnsense'
                ? (isset($sys->ssh) && platform_xml_flag($sys->ssh, 'enabled'))
                : (isset($sys->ssh) && platform_xml_flag($sys->ssh, 'enable')),
        ];
    }
}

if (!function_exists('platform_norm_interfaces')) {
    function platform_norm_interfaces(SimpleXMLElement $root): array {
        $out = [];
        if (!isset($root->interfaces)) {
            return $out;
        }
        foreach ($root->interfaces->children() as $if) {
            $name = $if->getName();
            $ip   = platform_xml_text($if, 'ipaddr');
            $bits = platform_xml_text($if, 'subnet');
            $cidr = null;
            if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && ctype_digit((string)$bits)) {
                $cidr = platform_cidr_network($ip, (int)$bits);
            }
            $out[$name] = [
                'name'    => $name,
                'device'  => platform_xml_text($if, 'if'),
                'descr'   => platform_xml_text($if, 'descr') ?? strtoupper($name),
                'enabled' => isset($if->enable) && trim((string)$if->enable) !== '0',
                'ipv4'    => $ip,       // may be 'dhcp', 'pppoe', ...
                'subnet'  => $bits,
                'network' => $cidr,
                'ipv6'    => platform_xml_text($if, 'ipaddrv6'),
            ];
        }
        return $out;
    }
}

if (!function_exists('platform_cidr_network')) {
    /** "192.168.1.1", 24 -> "192.168.1.0/24" */
    function platform_cidr_network(string $ip, int $bits): ?string {
        $long = ip2long($ip);
        if ($long === false || $bits < 0 || $bits > 32) {
            return null;
        }
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
        return long2ip($long & $mask) . '/' . $bits;
    }
}

// --- aliases ----------------------------------------------------------------

if (!function_exists('platform_norm_aliases')) {
    function platform_norm_aliases(SimpleXMLElement $root, string $family): array {
        $out = [];

        // OPNsense MVC aliases (OPNsense/Firewall/Alias/aliases/alias[@uuid]).
        $mvc = $root->xpath('/opnsense/OPNsense/Firewall/Alias/aliases/alias') ?: [];
        foreach ($mvc as $a) {
            $out[] = [
                'id'          => (string)($a['uuid'] ?? ''),
                'name'        => platform_xml_text($a, 'name'),
                'type'        => platform_xml_text($a, 'type'),
                'content'     => platform_split_list(platform_xml_text($a, 'content'), '/\n+/'),
                'description' => platform_xml_text($a, 'description'),
                'enabled'     => platform_xml_bool01($a, 'enabled', true),
                'source'      => 'mvc',
                'raw_path'    => 'OPNsense/Firewall/Alias/aliases/alias[@uuid="' . (string)($a['uuid'] ?? '') . '"]',
                'platform_fields' => array_filter([
                    'proto'      => platform_xml_text($a, 'proto'),
                    'updatefreq' => platform_xml_text($a, 'updatefreq'),
                    'interface'  => platform_xml_text($a, 'interface'),
                ], fn($v) => $v !== null),
            ];
        }

        // Legacy <aliases><alias> (pfSense always; OPNsense pre-MVC).
        if (isset($root->aliases)) {
            $i = 0;
            foreach ($root->aliases->alias as $a) {
                $name = platform_xml_text($a, 'name');
                $out[] = [
                    'id'          => null,
                    'name'        => $name,
                    'type'        => platform_xml_text($a, 'type'),
                    // pfSense separates entries with spaces, details with "||".
                    'content'     => platform_split_list(platform_xml_text($a, 'address'), '/\s+/'),
                    'description' => platform_xml_text($a, 'descr'),
                    'enabled'     => true,
                    'source'      => 'legacy',
                    'raw_path'    => 'aliases/alias[' . (++$i) . ']',
                    'platform_fields' => array_filter([
                        'detail' => platform_xml_text($a, 'detail'),
                        'url'    => platform_xml_text($a, 'url'),
                    ], fn($v) => $v !== null),
                ];
            }
        }
        return $out;
    }
}

// --- filter rules -----------------------------------------------------------

if (!function_exists('platform_norm_filter')) {
    function platform_norm_filter(SimpleXMLElement $root, string $family): array {
        $out = [];

        // OPNsense MVC rules, ordered by <sequence> within their own section.
        $mvc = $root->xpath('/opnsense/OPNsense/Firewall/Filter/rules/rule') ?: [];
        $mvcRows = [];
        foreach ($mvc as $docIndex => $r) {
            $mvcRows[] = [
                'id'          => (string)($r['uuid'] ?? ''),
                'section'     => 'mvc',
                'sequence'    => (int)platform_xml_text($r, 'sequence', '0'),
                'doc_index'   => $docIndex,
                'action'      => platform_xml_text($r, 'action', 'pass'),
                'enabled'     => platform_xml_bool01($r, 'enabled', true),
                'quick'       => platform_xml_bool01($r, 'quick', true),
                'interface'   => platform_split_list(platform_xml_text($r, 'interface')),
                'interface_not' => platform_xml_bool01($r, 'interfacenot'),
                'direction'   => platform_xml_text($r, 'direction', 'in'),
                'ipprotocol'  => platform_xml_text($r, 'ipprotocol', 'inet'),
                'protocol'    => platform_xml_text($r, 'protocol', 'any'),
                'source'      => [
                    'net'  => platform_xml_text($r, 'source_net', 'any'),
                    'kind' => 'mvc',
                    'port' => platform_xml_text($r, 'source_port'),
                    'not'  => platform_xml_bool01($r, 'source_not'),
                ],
                'destination' => [
                    'net'  => platform_xml_text($r, 'destination_net', 'any'),
                    'kind' => 'mvc',
                    'port' => platform_xml_text($r, 'destination_port'),
                    'not'  => platform_xml_bool01($r, 'destination_not'),
                ],
                'log'         => platform_xml_bool01($r, 'log'),
                'floating'    => count(platform_split_list(platform_xml_text($r, 'interface'))) !== 1,
                'description' => platform_xml_text($r, 'description'),
                'gateway'     => platform_xml_text($r, 'gateway'),
                'platform_fields' => array_filter([
                    'statetype'    => platform_xml_text($r, 'statetype'),
                    'state-policy' => platform_xml_text($r, 'state-policy'),
                    'tag'          => platform_xml_text($r, 'tag'),
                    'tagged'       => platform_xml_text($r, 'tagged'),
                    'categories'   => platform_xml_text($r, 'categories'),
                    'nosync'       => platform_xml_text($r, 'nosync'),
                ], fn($v) => $v !== null),
            ];
        }
        usort($mvcRows, fn($a, $b) => [$a['sequence'], $a['doc_index']] <=> [$b['sequence'], $b['doc_index']]);
        foreach ($mvcRows as $i => $row) {
            $row['order'] = $i;
            unset($row['doc_index']);
            $out[] = $row;
        }

        // Legacy <filter><rule> - document order IS evaluation order.
        if (isset($root->filter)) {
            $i = 0;
            foreach ($root->filter->rule as $r) {
                $floating = platform_xml_flag($r, 'floating');
                $out[] = [
                    // pfSense: <tracker> is stable across edits; OPNsense legacy
                    // rules carry a uuid attribute.
                    'id'          => (string)($r['uuid'] ?? '') ?: (platform_xml_text($r, 'tracker') ?? ''),
                    'section'     => 'legacy',
                    'order'       => $i++,
                    'action'      => platform_xml_text($r, 'type', 'pass'),
                    'enabled'     => !platform_xml_flag($r, 'disabled')
                                     && platform_xml_text($r, 'enabled', '1') !== '0',
                    // Interface rules are always quick; floating rules default
                    // to non-quick unless <quick> is set.
                    'quick'       => $floating ? platform_xml_flag($r, 'quick') : true,
                    'interface'   => platform_split_list(platform_xml_text($r, 'interface')),
                    'interface_not' => false,
                    'direction'   => platform_xml_text($r, 'direction', $floating ? 'any' : 'in'),
                    'ipprotocol'  => platform_xml_text($r, 'ipprotocol', 'inet'),
                    'protocol'    => platform_xml_text($r, 'protocol', 'any'),
                    'source'      => platform_legacy_endpoint($r->source ?? null),
                    'destination' => platform_legacy_endpoint($r->destination ?? null),
                    'log'         => platform_xml_flag($r, 'log'),
                    'floating'    => $floating,
                    'description' => platform_xml_text($r, 'descr'),
                    'gateway'     => platform_xml_text($r, 'gateway'),
                    'platform_fields' => array_filter([
                        'tracker'   => platform_xml_text($r, 'tracker'),
                        'statetype' => platform_xml_text($r, 'statetype'),
                        'tag'       => platform_xml_text($r, 'tag'),
                        'tagged'    => platform_xml_text($r, 'tagged'),
                        'associated-rule-id' => platform_xml_text($r, 'associated-rule-id'),
                        'sched'     => platform_xml_text($r, 'sched'),
                    ], fn($v) => $v !== null),
                ];
            }
        }
        return $out;
    }
}

// --- NAT --------------------------------------------------------------------

if (!function_exists('platform_norm_nat')) {
    function platform_norm_nat(SimpleXMLElement $root, string $family): array {
        $nat = ['port_forwards' => [], 'outbound' => ['mode' => null, 'rules' => []], 'one_to_one' => []];
        if (isset($root->nat)) {
            $i = 0;
            foreach ($root->nat->rule as $r) {
                $nat['port_forwards'][] = [
                    'id'          => (string)($r['uuid'] ?? '') ?: null,
                    'order'       => $i++,
                    'enabled'     => !platform_xml_flag($r, 'disabled'),
                    'interface'   => platform_split_list(platform_xml_text($r, 'interface')),
                    'protocol'    => platform_xml_text($r, 'protocol', 'tcp'),
                    'source'      => platform_legacy_endpoint($r->source ?? null),
                    'destination' => platform_legacy_endpoint($r->destination ?? null),
                    'target'      => platform_xml_text($r, 'target'),
                    'local_port'  => platform_xml_text($r, 'local-port'),
                    'reflection'  => platform_xml_text($r, 'natreflection'),
                    'associated_rule' => platform_xml_text($r, 'associated-rule-id'),
                    'description' => platform_xml_text($r, 'descr'),
                ];
            }
            if (isset($root->nat->outbound)) {
                $nat['outbound']['mode'] = platform_xml_text($root->nat->outbound, 'mode', 'automatic');
                $i = 0;
                foreach ($root->nat->outbound->rule as $r) {
                    $nat['outbound']['rules'][] = [
                        'order'       => $i++,
                        'enabled'     => !platform_xml_flag($r, 'disabled'),
                        'interface'   => platform_xml_text($r, 'interface'),
                        'source'      => platform_legacy_endpoint($r->source ?? null),
                        'destination' => platform_legacy_endpoint($r->destination ?? null),
                        'target'      => platform_xml_text($r, 'target'),
                        'nonat'       => platform_xml_flag($r, 'nonat'),
                        'description' => platform_xml_text($r, 'descr'),
                    ];
                }
            }
            foreach ($root->nat->onetoone as $r) {
                $nat['one_to_one'][] = [
                    'interface' => platform_xml_text($r, 'interface'),
                    'external'  => platform_xml_text($r, 'external'),
                    'internal'  => platform_legacy_endpoint($r->source ?? null)['net'],
                    'description' => platform_xml_text($r, 'descr'),
                    'enabled'   => !platform_xml_flag($r, 'disabled'),
                ];
            }
        }
        // OPNsense MVC 1:1 NAT.
        foreach ($root->xpath('/opnsense/OPNsense/Firewall/Filter/onetoone/rule') ?: [] as $r) {
            $nat['one_to_one'][] = [
                'interface' => platform_xml_text($r, 'interface'),
                'external'  => platform_xml_text($r, 'external'),
                'internal'  => platform_xml_text($r, 'source_net'),
                'description' => platform_xml_text($r, 'description'),
                'enabled'   => platform_xml_bool01($r, 'enabled', true),
            ];
        }
        return $nat;
    }
}

// --- routing ----------------------------------------------------------------

if (!function_exists('platform_norm_routes')) {
    function platform_norm_routes(SimpleXMLElement $root): array {
        $out = [];
        if (isset($root->staticroutes)) {
            foreach ($root->staticroutes->route as $r) {
                $out[] = [
                    'network' => platform_xml_text($r, 'network'),
                    'gateway' => platform_xml_text($r, 'gateway'),
                    'enabled' => !platform_xml_flag($r, 'disabled'),
                    'description' => platform_xml_text($r, 'descr'),
                ];
            }
        }
        return $out;
    }
}

if (!function_exists('platform_norm_gateways')) {
    function platform_norm_gateways(SimpleXMLElement $root, string $family): array {
        $out = [];
        $items = [];
        if (isset($root->gateways)) {
            foreach ($root->gateways->gateway_item as $g) { $items[] = $g; }
        }
        foreach ($root->xpath('/opnsense/OPNsense/Gateways/gateway_item') ?: [] as $g) { $items[] = $g; }
        foreach ($items as $g) {
            $out[] = [
                'name'      => platform_xml_text($g, 'name'),
                'interface' => platform_xml_text($g, 'interface'),
                'gateway'   => platform_xml_text($g, 'gateway'),
                'monitor'   => platform_xml_text($g, 'monitor'),
                'ipprotocol'=> platform_xml_text($g, 'ipprotocol'),
                'default'   => platform_xml_flag($g, 'defaultgw'),
            ];
        }
        return $out;
    }
}

// --- WireGuard --------------------------------------------------------------

if (!function_exists('platform_norm_wireguard')) {
    /**
     * WireGuard instances and peers. Public keys are kept (they identify
     * peers); private keys and PSKs are never copied, only "psk: true".
     */
    function platform_norm_wireguard(SimpleXMLElement $root, string $family): array {
        $instances = [];
        $peers = [];

        if ($family === 'opnsense') {
            $byUuid = [];
            foreach ($root->xpath('/opnsense/OPNsense/wireguard/client/clients/client') ?: [] as $c) {
                $uuid = (string)($c['uuid'] ?? '');
                $byUuid[$uuid] = [
                    'id'          => $uuid,
                    'name'        => platform_xml_text($c, 'name'),
                    'enabled'     => platform_xml_bool01($c, 'enabled', true),
                    'public_key'  => platform_xml_text($c, 'pubkey'),
                    'has_psk'     => platform_xml_text($c, 'psk') !== null,
                    // OPNsense "tunneladdress" on a peer is its AllowedIPs.
                    'allowed_ips' => platform_split_list(platform_xml_text($c, 'tunneladdress')),
                    'endpoint'    => platform_xml_text($c, 'serveraddress'),
                    'endpoint_port' => platform_xml_text($c, 'serverport'),
                    'keepalive'   => platform_xml_text($c, 'keepalive'),
                    'instance'    => null,
                ];
            }
            foreach ($root->xpath('/opnsense/OPNsense/wireguard/server/servers/server') ?: [] as $s) {
                $uuid = (string)($s['uuid'] ?? '');
                $instanceNo = platform_xml_text($s, 'instance');
                $peerIds = platform_split_list(platform_xml_text($s, 'peers'));
                $instances[] = [
                    'id'          => $uuid,
                    'name'        => platform_xml_text($s, 'name'),
                    'interface'   => $instanceNo !== null ? 'wg' . $instanceNo : null,
                    'enabled'     => platform_xml_bool01($s, 'enabled', true),
                    'public_key'  => platform_xml_text($s, 'pubkey'),
                    'listen_port' => platform_xml_text($s, 'port'),
                    'addresses'   => platform_split_list(platform_xml_text($s, 'tunneladdress')),
                    'mtu'         => platform_xml_text($s, 'mtu'),
                    'disable_routes' => platform_xml_bool01($s, 'disableroutes'),
                    'peer_ids'    => $peerIds,
                ];
                foreach ($peerIds as $pid) {
                    if (isset($byUuid[$pid])) {
                        $byUuid[$pid]['instance'] = $instanceNo !== null ? 'wg' . $instanceNo : $uuid;
                    }
                }
            }
            $peers = array_values($byUuid);
        } else {
            // pfSense WireGuard package: installedpackages/wireguard/{tunnels,peers}/item
            $wg = $root->installedpackages->wireguard ?? null;
            if ($wg !== null) {
                foreach ($wg->tunnels->item ?? [] as $t) {
                    $addrs = [];
                    foreach ($t->addresses->row ?? [] as $row) {
                        $a = platform_xml_text($row, 'address');
                        $m = platform_xml_text($row, 'mask');
                        if ($a !== null) { $addrs[] = $m !== null ? "$a/$m" : $a; }
                    }
                    $instances[] = [
                        'id'          => platform_xml_text($t, 'name'),
                        'name'        => platform_xml_text($t, 'descr') ?? platform_xml_text($t, 'name'),
                        'interface'   => platform_xml_text($t, 'name'),
                        'enabled'     => platform_xml_text($t, 'enabled', 'yes') === 'yes',
                        'public_key'  => platform_xml_text($t, 'publickey'),
                        'listen_port' => platform_xml_text($t, 'listenport'),
                        'addresses'   => $addrs,
                        'mtu'         => platform_xml_text($t, 'mtu'),
                        'disable_routes' => false,
                        'peer_ids'    => [],
                    ];
                }
                $i = 0;
                foreach ($wg->peers->item ?? [] as $p) {
                    $allowed = [];
                    foreach ($p->allowedips->row ?? [] as $row) {
                        $a = platform_xml_text($row, 'address');
                        $m = platform_xml_text($row, 'mask');
                        if ($a !== null) { $allowed[] = $m !== null ? "$a/$m" : $a; }
                    }
                    $peers[] = [
                        'id'          => 'peer' . ($i++),
                        'name'        => platform_xml_text($p, 'descr'),
                        'enabled'     => platform_xml_text($p, 'enabled', 'yes') === 'yes',
                        'public_key'  => platform_xml_text($p, 'publickey'),
                        'has_psk'     => platform_xml_text($p, 'presharedkey') !== null,
                        'allowed_ips' => $allowed,
                        'endpoint'    => platform_xml_text($p, 'endpoint'),
                        'endpoint_port' => platform_xml_text($p, 'port'),
                        'keepalive'   => platform_xml_text($p, 'persistentkeepalive'),
                        'instance'    => platform_xml_text($p, 'tun'),
                    ];
                }
            }
        }
        return ['instances' => $instances, 'peers' => $peers];
    }
}

// --- IPsec ------------------------------------------------------------------

if (!function_exists('platform_norm_ipsec')) {
    function platform_norm_ipsec(SimpleXMLElement $root, string $family): array {
        $phase1 = [];
        $phase2 = [];
        if (isset($root->ipsec)) {
            foreach ($root->ipsec->phase1 as $p) {
                $phase1[] = [
                    'ikeid'       => platform_xml_text($p, 'ikeid'),
                    'enabled'     => !platform_xml_flag($p, 'disabled'),
                    'iketype'     => platform_xml_text($p, 'iketype'),
                    'interface'   => platform_xml_text($p, 'interface'),
                    'remote_gateway' => platform_xml_text($p, 'remote-gateway'),
                    'auth_method' => platform_xml_text($p, 'authentication_method'),
                    'my_id'       => trim((platform_xml_text($p, 'myid_type') ?? '') . ':' . (platform_xml_text($p, 'myid_data') ?? ''), ':'),
                    'peer_id'     => trim((platform_xml_text($p, 'peerid_type') ?? '') . ':' . (platform_xml_text($p, 'peerid_data') ?? ''), ':'),
                    'mobile'      => platform_xml_flag($p, 'mobile'),
                    'has_psk'     => platform_xml_text($p, 'pre-shared-key') !== null,
                    'description' => platform_xml_text($p, 'descr'),
                ];
            }
            foreach ($root->ipsec->phase2 as $p) {
                $phase2[] = [
                    'ikeid'       => platform_xml_text($p, 'ikeid'),
                    'uniqid'      => platform_xml_text($p, 'uniqid'),
                    'reqid'       => platform_xml_text($p, 'reqid'),
                    'enabled'     => !platform_xml_flag($p, 'disabled'),
                    'mode'        => platform_xml_text($p, 'mode'),
                    'local'       => platform_ipsec_id($p->localid ?? null),
                    'remote'      => platform_ipsec_id($p->remoteid ?? null),
                    'nat_local'   => isset($p->natlocalid) ? platform_ipsec_id($p->natlocalid) : null,
                    'description' => platform_xml_text($p, 'descr'),
                ];
            }
        }

        // OPNsense Swanctl (connections + children).
        $connections = [];
        $locals = [];
        foreach ($root->xpath('/opnsense/OPNsense/Swanctl/locals/local') ?: [] as $l) {
            $locals[(string)$l->connection][] = platform_xml_text($l, 'id');
        }
        $children = [];
        foreach ($root->xpath('/opnsense/OPNsense/Swanctl/children/child') ?: [] as $c) {
            $children[(string)$c->connection][] = [
                'id'          => (string)($c['uuid'] ?? ''),
                'enabled'     => platform_xml_bool01($c, 'enabled', true),
                'mode'        => platform_xml_text($c, 'mode'),
                'local_ts'    => platform_split_list(platform_xml_text($c, 'local_ts')),
                'remote_ts'   => platform_split_list(platform_xml_text($c, 'remote_ts')),
                'reqid'       => platform_xml_text($c, 'reqid'),
                'description' => platform_xml_text($c, 'description'),
            ];
        }
        foreach ($root->xpath('/opnsense/OPNsense/Swanctl/Connections/Connection') ?: [] as $c) {
            $uuid = (string)($c['uuid'] ?? '');
            $connections[] = [
                'id'          => $uuid,
                'enabled'     => platform_xml_bool01($c, 'enabled', true),
                'version'     => platform_xml_text($c, 'version'),
                'local_addrs' => platform_split_list(platform_xml_text($c, 'local_addrs')),
                'remote_addrs'=> platform_split_list(platform_xml_text($c, 'remote_addrs')),
                'description' => platform_xml_text($c, 'description'),
                'local_ids'   => $locals[$uuid] ?? [],
                'children'    => $children[$uuid] ?? [],
            ];
        }

        return ['phase1' => $phase1, 'phase2' => $phase2, 'connections' => $connections];
    }
}

if (!function_exists('platform_ipsec_id')) {
    function platform_ipsec_id($n): ?array {
        if ($n === null) {
            return null;
        }
        $type = platform_xml_text($n, 'type');
        $addr = platform_xml_text($n, 'address');
        $bits = platform_xml_text($n, 'netbits');
        $cidr = null;
        if ($type === 'network' && $addr !== null && $bits !== null) {
            $cidr = "$addr/$bits";
        } elseif ($type === 'address' && $addr !== null) {
            $cidr = str_contains($addr, ':') ? "$addr/128" : "$addr/32";
        }
        return ['type' => $type, 'address' => $addr, 'netbits' => $bits, 'cidr' => $cidr];
    }
}

// --- OpenVPN ----------------------------------------------------------------

if (!function_exists('platform_norm_openvpn')) {
    function platform_norm_openvpn(SimpleXMLElement $root, string $family): array {
        $servers = [];
        $clients = [];
        if (isset($root->openvpn)) {
            foreach ($root->openvpn->{'openvpn-server'} as $s) {
                $servers[] = platform_openvpn_row($s, 'server');
            }
            foreach ($root->openvpn->{'openvpn-client'} as $c) {
                $clients[] = platform_openvpn_row($c, 'client');
            }
        }
        // OPNsense MVC instances.
        foreach ($root->xpath('/opnsense/OPNsense/OpenVPN/Instances/Instance') ?: [] as $i) {
            $role = platform_xml_text($i, 'role', 'server');
            $row = [
                'id'          => (string)($i['uuid'] ?? ''),
                'vpnid'       => platform_xml_text($i, 'vpnid'),
                'role'        => $role,
                'enabled'     => platform_xml_bool01($i, 'enabled', true),
                'mode'        => platform_xml_text($i, 'dev_type'),
                'protocol'    => platform_xml_text($i, 'proto'),
                'port'        => platform_xml_text($i, 'port'),
                'remote'      => platform_split_list(platform_xml_text($i, 'remote')),
                'tunnel_network' => platform_xml_text($i, 'server'),
                'local_networks' => platform_split_list(platform_xml_text($i, 'push_route')),
                'remote_networks'=> platform_split_list(platform_xml_text($i, 'route')),
                'description' => platform_xml_text($i, 'description'),
                'source'      => 'mvc',
            ];
            if ($role === 'client') { $clients[] = $row; } else { $servers[] = $row; }
        }
        return ['servers' => $servers, 'clients' => $clients];
    }
}

if (!function_exists('platform_openvpn_row')) {
    function platform_openvpn_row(SimpleXMLElement $n, string $role): array {
        return [
            'id'          => platform_xml_text($n, 'vpnid'),
            'vpnid'       => platform_xml_text($n, 'vpnid'),
            'role'        => $role,
            'enabled'     => !platform_xml_flag($n, 'disable'),
            'mode'        => platform_xml_text($n, 'mode'),
            'protocol'    => platform_xml_text($n, 'protocol'),
            'port'        => platform_xml_text($n, 'local_port') ?? platform_xml_text($n, 'server_port'),
            'remote'      => array_filter([platform_xml_text($n, 'server_addr')]),
            'interface'   => platform_xml_text($n, 'interface'),
            'tunnel_network' => platform_xml_text($n, 'tunnel_network'),
            'local_networks' => platform_split_list(platform_xml_text($n, 'local_network')),
            'remote_networks'=> platform_split_list(platform_xml_text($n, 'remote_network')),
            'description' => platform_xml_text($n, 'description'),
            'source'      => 'legacy',
        ];
    }
}

// --- HA / logging -----------------------------------------------------------

if (!function_exists('platform_norm_ha')) {
    function platform_norm_ha(SimpleXMLElement $root, string $family): array {
        $h = $root->hasync ?? null;
        $items = [];
        if ($h !== null) {
            if ($family === 'opnsense') {
                $items = platform_split_list(platform_xml_text($h, 'syncitems'));
            } else {
                // pfSense: one <synchronizeX>on</synchronizeX> element per item.
                foreach ($h->children() as $c) {
                    $n = $c->getName();
                    if (str_starts_with($n, 'synchronize') && $n !== 'synchronizetoip'
                        && strtolower(trim((string)$c)) === 'on') {
                        $items[] = substr($n, strlen('synchronize'));
                    }
                }
            }
        }
        $vips = [];
        if (isset($root->virtualip)) {
            foreach ($root->virtualip->vip as $v) {
                if (platform_xml_text($v, 'mode') === 'carp') {
                    $vips[] = [
                        'interface' => platform_xml_text($v, 'interface'),
                        'vhid'      => platform_xml_text($v, 'vhid'),
                        'subnet'    => platform_xml_text($v, 'subnet'),
                    ];
                }
            }
        }
        $peer = platform_xml_text($h, 'synchronizetoip');
        return [
            'sync_peer'        => $peer,
            'pfsync_interface' => platform_xml_text($h, 'pfsyncinterface'),
            'pfsync_peer'      => platform_xml_text($h, 'pfsyncpeerip'),
            // The things the platform copies to the peer on its own. Template
            // planning uses this to avoid writing both HA members.
            'sync_items'       => $items,
            'carp_vips'        => $vips,
            'configured'       => $peer !== null,
        ];
    }
}

if (!function_exists('platform_norm_logging')) {
    function platform_norm_logging(SimpleXMLElement $root, string $family): array {
        $remote = [];
        if ($family === 'pfsense' && isset($root->syslog)) {
            foreach (['remoteserver', 'remoteserver2', 'remoteserver3'] as $k) {
                $v = platform_xml_text($root->syslog, $k);
                if ($v !== null) { $remote[] = $v; }
            }
        }
        foreach ($root->xpath('/opnsense/OPNsense/Syslog/destinations/destination') ?: [] as $d) {
            $remote[] = trim((platform_xml_text($d, 'hostname') ?? '') . ':' . (platform_xml_text($d, 'port') ?? ''), ':');
        }
        return ['remote_servers' => $remote];
    }
}
