<?php
/**
 * OPNMGR Fleet Configuration Search
 *
 * Answers operational questions across every stored configuration backup:
 *
 *   "Which firewalls have SSH enabled on WAN?"
 *   "Which firewalls contain 8.8.8.8?"
 *   "Find every reference to 192.168.50.0/24"
 *   "Which firewalls have UPnP enabled?"
 *
 * Deliberately deterministic. AI may later explain a result set, but it is
 * never required to produce one: the answer to "which firewalls expose port
 * 443 from any source" must be reproducible and auditable, and an operator has
 * to be able to trust it without a model in the path.
 *
 * Searching is done over the canonical parse from inc/config_drift.php, so a
 * query matches configuration meaning rather than XML formatting.
 *
 * @since 3.16.0
 */

require_once __DIR__ . '/config_drift.php';
require_once __DIR__ . '/backup_storage.php';
require_once __DIR__ . '/search.php';

if (!function_exists('config_search_checks')) {
    /**
     * Named checks for common questions, so the answer does not depend on
     * somebody remembering the right XPath.
     *
     * Each check receives the canonical config tree and returns a list of
     * human-readable findings (empty means "does not apply").
     *
     * @return array<string, array{label:string, describe:string, run:callable}>
     */
    function config_search_checks(): array {
        return [
            'ssh_on_wan' => [
                'label' => 'SSH reachable from WAN',
                'describe' => 'A pass rule on a WAN interface targeting port 22.',
                'run' => function (array $cfg): array {
                    $found = [];
                    foreach (config_search_rules($cfg) as $rule) {
                        $iface = strtolower((string)($rule['interface'] ?? ''));
                        $port  = (string)($rule['destination']['port'] ?? '');
                        $type  = strtolower((string)($rule['type'] ?? ''));

                        if ($type === 'pass' && str_contains($iface, 'wan')
                            && ($port === '22' || $port === 'ssh')) {
                            $found[] = sprintf('%s rule on %s port %s: %s',
                                $type, $iface, $port, $rule['descr'] ?? '(no description)');
                        }
                    }
                    return $found;
                },
            ],
            'webgui_on_wan' => [
                'label' => 'Web GUI reachable from WAN',
                'describe' => 'A pass rule on a WAN interface targeting the GUI port.',
                'run' => function (array $cfg): array {
                    $guiPort = (string)($cfg['system']['webgui']['port'] ?? '443');
                    $found = [];
                    foreach (config_search_rules($cfg) as $rule) {
                        $iface = strtolower((string)($rule['interface'] ?? ''));
                        $port  = (string)($rule['destination']['port'] ?? '');
                        if (strtolower((string)($rule['type'] ?? '')) === 'pass'
                            && str_contains($iface, 'wan')
                            && ($port === $guiPort || $port === 'https' || $port === '443')) {
                            $found[] = sprintf('pass rule on %s port %s: %s',
                                $iface, $port, $rule['descr'] ?? '(no description)');
                        }
                    }
                    return $found;
                },
            ],
            'any_any_pass' => [
                'label' => 'Permissive any-to-any pass rule',
                'describe' => 'A pass rule with both source and destination set to any.',
                'run' => function (array $cfg): array {
                    $found = [];
                    foreach (config_search_rules($cfg) as $rule) {
                        $srcAny = array_key_exists('any', (array)($rule['source'] ?? []));
                        $dstAny = array_key_exists('any', (array)($rule['destination'] ?? []));
                        if (strtolower((string)($rule['type'] ?? '')) === 'pass' && $srcAny && $dstAny) {
                            $found[] = sprintf('%s: %s',
                                $rule['interface'] ?? '?', $rule['descr'] ?? '(no description)');
                        }
                    }
                    return $found;
                },
            ],
            'upnp_enabled' => [
                'label' => 'UPnP enabled',
                'describe' => 'miniupnpd is present and enabled.',
                'run' => function (array $cfg): array {
                    $upnp = $cfg['miniupnpd'] ?? null;
                    if (!is_array($upnp)) {
                        return [];
                    }
                    $enabled = (string)($upnp['enable'] ?? ($upnp['iface_array'] ?? ''));
                    return ($enabled === '1' || $enabled === 'yes') ? ['miniupnpd enabled'] : [];
                },
            ],
            'password_auth_ssh' => [
                'label' => 'SSH password authentication permitted',
                'describe' => 'The SSH service allows password logins.',
                'run' => function (array $cfg): array {
                    $ssh = $cfg['system']['ssh'] ?? null;
                    if (!is_array($ssh)) {
                        return [];
                    }
                    $noPassword = (string)($ssh['passwordauth'] ?? '');
                    // OPNsense stores 'passwordauth' as present/1 when allowed.
                    return ($noPassword === '1' || $noPassword === 'yes')
                        ? ['password authentication enabled'] : [];
                },
            ],
            'no_dns_servers' => [
                'label' => 'No DNS servers configured',
                'describe' => 'The system has no explicit DNS server entries.',
                'run' => function (array $cfg): array {
                    $dns = $cfg['system']['dnsserver'] ?? null;
                    if ($dns === null || $dns === '' || $dns === []) {
                        return ['no dnsserver entries'];
                    }
                    return [];
                },
            ],
        ];
    }
}

if (!function_exists('config_search_rules')) {
    /**
     * Normalise filter rules into a list, whatever their canonical shape.
     *
     * A single rule canonicalises to a map and several to a list, so callers
     * that iterate need this rather than special-casing each time.
     *
     * @return array<int, array>
     */
    function config_search_rules(array $cfg): array {
        $rules = $cfg['filter']['rule'] ?? null;
        if ($rules === null) {
            return [];
        }
        if (drift_is_list($rules)) {
            return array_values(array_filter($rules, 'is_array'));
        }
        return is_array($rules) ? [$rules] : [];
    }
}

if (!function_exists('config_search_fleet')) {
    /**
     * Run a search across the newest usable configuration of every firewall.
     *
     * Supported query forms:
     *   check:ssh_on_wan     a named check from config_search_checks()
     *   192.168.50.0/24      any address inside that range, anywhere in the config
     *   8.8.8.8              a literal string, matched against config values
     *   "some text"          a literal string
     *
     * @param string $query
     * @param int    $maxFirewalls Safety bound
     * @return array{results:array, scanned:int, skipped:int, error:string, kind:string}
     */
    function config_search_fleet(string $query, int $maxFirewalls = 500): array {
        $query = trim($query);
        if ($query === '') {
            return ['results' => [], 'scanned' => 0, 'skipped' => 0, 'error' => '', 'kind' => ''];
        }

        // Decide what kind of search this is.
        $check = null;
        $cidr  = null;
        $literal = null;

        if (preg_match('/^check:([a-z0-9_]+)$/i', $query, $m)) {
            $checks = config_search_checks();
            $name = strtolower($m[1]);
            if (!isset($checks[$name])) {
                return ['results' => [], 'scanned' => 0, 'skipped' => 0,
                        'error' => 'Unknown check: ' . $name, 'kind' => 'check'];
            }
            $check = ['name' => $name] + $checks[$name];
        } elseif (preg_match('#^\d{1,3}(\.\d{1,3}){3}/\d{1,2}$#', $query)) {
            $cidr = cidr_to_range($query);
            if ($cidr === null) {
                return ['results' => [], 'scanned' => 0, 'skipped' => 0,
                        'error' => 'Malformed CIDR', 'kind' => 'cidr'];
            }
        } else {
            $literal = trim($query, '"\'');
        }

        $firewalls = db()->query(
            'SELECT f.id, f.hostname, c.name AS customer_name
               FROM firewalls f LEFT JOIN customers c ON c.id = f.customer_id
              ORDER BY f.hostname'
        )->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        $scanned = 0;
        $skipped = 0;

        foreach (array_slice($firewalls, 0, $maxFirewalls) as $fw) {
            $backup = drift_latest_backup((int)$fw['id']);
            if ($backup === null) {
                $skipped++;
                continue;
            }

            $loaded = drift_load_backup_xml((int)$backup['id']);
            if (!$loaded['ok']) {
                $skipped++;
                continue;
            }

            $canonical = drift_xml_to_canonical($loaded['xml']);
            if (!$canonical['ok']) {
                $skipped++;
                continue;
            }

            $scanned++;
            $root = $canonical['tree'][array_key_first($canonical['tree'])] ?? [];
            if (!is_array($root)) {
                continue;
            }

            $matches = [];

            if ($check !== null) {
                $matches = ($check['run'])($root);
            } elseif ($cidr !== null) {
                $matches = config_search_walk($root, '', function ($path, $value) use ($cidr) {
                    if (!is_string($value)) {
                        return null;
                    }
                    // Match any dotted-quad inside the value against the range.
                    if (!preg_match_all('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', $value, $m)) {
                        return null;
                    }
                    foreach ($m[0] as $ip) {
                        $long = ip2long($ip);
                        if ($long !== false && $long >= $cidr[0] && $long <= $cidr[1]) {
                            return sprintf('%s = %s', $path, drift_truncate($value, 80));
                        }
                    }
                    return null;
                });
            } else {
                $needle = strtolower($literal);
                $matches = config_search_walk($root, '', function ($path, $value) use ($needle) {
                    if (!is_string($value) || $value === '') {
                        return null;
                    }
                    return str_contains(strtolower($value), $needle)
                        ? sprintf('%s = %s', $path, drift_truncate($value, 80))
                        : null;
                });
            }

            if ($matches) {
                $results[] = [
                    'firewall_id'   => (int)$fw['id'],
                    'hostname'      => $fw['hostname'],
                    'customer_name' => $fw['customer_name'],
                    'backup_id'     => (int)$backup['id'],
                    'backup_at'     => $backup['uploaded_at'] ?: $backup['created_at'],
                    'match_count'   => count($matches),
                    'matches'       => array_slice($matches, 0, 25),
                ];
            }
        }

        return [
            'results' => $results,
            'scanned' => $scanned,
            'skipped' => $skipped,
            'error'   => '',
            'kind'    => $check ? 'check' : ($cidr ? 'cidr' : 'literal'),
        ];
    }
}

if (!function_exists('config_search_walk')) {
    /**
     * Walk a canonical config tree, collecting whatever the matcher returns.
     *
     * @param mixed    $node
     * @param string   $path
     * @param callable $matcher fn(string $path, mixed $value): ?string
     * @param int      $depth
     * @param int      $limit
     * @return string[]
     */
    function config_search_walk($node, string $path, callable $matcher, int $depth = 0, int $limit = 200): array {
        if ($depth > 30) {
            return [];
        }

        $found = [];

        if (!is_array($node)) {
            $hit = $matcher($path, $node);
            return $hit !== null ? [$hit] : [];
        }

        foreach ($node as $key => $child) {
            if (count($found) >= $limit) {
                break;
            }
            $childPath = $path === '' ? (string)$key : $path . '/' . $key;
            foreach (config_search_walk($child, $childPath, $matcher, $depth + 1, $limit) as $hit) {
                $found[] = $hit;
                if (count($found) >= $limit) {
                    break;
                }
            }
        }

        return $found;
    }
}
