<?php
/**
 * OPNMGR Global Fleet Search
 *
 * One search box that answers "where is this thing" across the managed fleet.
 *
 * Supported syntax
 * ----------------
 *   plain text            matches hostname, customer, site, IPs, version, tags
 *   customer:Acme         field-qualified; quote values with spaces
 *   site:"Jacksonville HQ"
 *   tag:critical
 *   version:26.1          OPNsense version prefix
 *   agent:1.5.6           agent version prefix
 *   ip:192.168.22.1       any address field
 *   interface:igc0        WAN interface / group
 *   vpn:branch-office     VPN or tunnel name where the agent has reported it
 *   status:online|offline
 *   192.168.22.0/24       bare CIDR: firewalls with an address inside it
 *
 * Terms combine with AND. Unqualified terms are OR'd across the searchable
 * columns, so "acme jacksonville" narrows rather than widens.
 *
 * Everything is a bound parameter; CIDR matching is done in SQL with INET_ATON
 * on an IPv4 range rather than by string prefix, so 192.168.2.0/24 does not
 * match 192.168.22.1.
 *
 * @since 3.12.0
 */

if (!defined('SEARCH_MAX_RESULTS')) {
    define('SEARCH_MAX_RESULTS', 200);
}

if (!function_exists('parse_search_query')) {
    /**
     * Split a raw query into field-qualified terms and free text.
     *
     * @return array{fields: array<int, array{field:string, value:string}>, text: string[], cidrs: string[]}
     */
    function parse_search_query(string $raw): array {
        $fields = [];
        $text   = [];
        $cidrs  = [];

        // field:value, field:"quoted value", "quoted text", bare word
        $pattern = '/(\w+):"([^"]*)"|(\w+):(\S+)|"([^"]*)"|(\S+)/';
        preg_match_all($pattern, trim($raw), $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            if (isset($m[2]) && $m[1] !== '') {
                $fields[] = ['field' => strtolower($m[1]), 'value' => $m[2]];
            } elseif (isset($m[4]) && ($m[3] ?? '') !== '') {
                $fields[] = ['field' => strtolower($m[3]), 'value' => $m[4]];
            } else {
                // ?? only falls through on null/unset, and preg_match_all gives
                // unmatched leading groups as '' rather than null - so
                // `$m[5] ?? $m[6]` returned the empty quoted-string group and
                // silently dropped every bare term, CIDRs included.
                $value = ($m[5] ?? '') !== '' ? $m[5] : ($m[6] ?? '');
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
                // A bare CIDR is a range query, not a text match.
                if (preg_match('#^\d{1,3}(\.\d{1,3}){3}/\d{1,2}$#', $value)) {
                    $cidrs[] = $value;
                } else {
                    $text[] = $value;
                }
            }
        }

        return ['fields' => $fields, 'text' => $text, 'cidrs' => $cidrs];
    }
}

if (!function_exists('cidr_to_range')) {
    /**
     * IPv4 CIDR to inclusive integer bounds.
     *
     * @return array{0:int,1:int}|null Null when the CIDR is malformed
     */
    function cidr_to_range(string $cidr): ?array {
        [$base, $bits] = array_pad(explode('/', $cidr, 2), 2, null);

        if (filter_var($base, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        $bits = (int)$bits;
        if ($bits < 0 || $bits > 32) {
            return null;
        }

        $baseLong = ip2long($base);
        if ($baseLong === false) {
            return null;
        }

        $mask  = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;
        $start = $baseLong & $mask;
        $end   = $start | (~$mask & 0xFFFFFFFF);

        return [$start, $end];
    }
}

if (!function_exists('search_fleet')) {
    /**
     * Run a fleet search.
     *
     * @param string $raw   Raw query string
     * @param int    $limit Maximum rows
     * @return array{results: array, total: int, parsed: array, error: string}
     */
    function search_fleet(string $raw, int $limit = SEARCH_MAX_RESULTS): array {
        $parsed = parse_search_query($raw);
        $limit  = max(1, min(SEARCH_MAX_RESULTS, $limit));

        if (!$parsed['fields'] && !$parsed['text'] && !$parsed['cidrs']) {
            return ['results' => [], 'total' => 0, 'parsed' => $parsed, 'error' => ''];
        }

        $where  = [];
        $params = [];

        // --- field-qualified terms -----------------------------------------
        foreach ($parsed['fields'] as $term) {
            $value = $term['value'];
            if ($value === '') {
                continue;
            }
            $like = '%' . $value . '%';

            switch ($term['field']) {
                case 'customer':
                    $where[]  = '(c.name LIKE ? OR c.code LIKE ?)';
                    array_push($params, $like, $like);
                    break;

                case 'site':
                    $where[]  = '(s.name LIKE ? OR s.code LIKE ?)';
                    array_push($params, $like, $like);
                    break;

                case 'tag':
                    // Firewall tags live in a join table; customer tags are a
                    // comma-separated column.
                    $where[]  = '(EXISTS (SELECT 1 FROM firewall_tags ft
                                            JOIN tags t ON t.id = ft.tag_id
                                           WHERE ft.firewall_id = f.id AND t.name LIKE ?)
                                  OR c.tags LIKE ?)';
                    array_push($params, $like, $like);
                    break;

                case 'version':
                case 'opnsense':
                    $where[]  = '(f.version LIKE ? OR f.current_version LIKE ?)';
                    array_push($params, $value . '%', $value . '%');
                    break;

                case 'agent':
                    $where[]  = 'f.agent_version LIKE ?';
                    $params[] = $value . '%';
                    break;

                case 'ip':
                case 'address':
                    $where[]  = '(f.wan_ip LIKE ? OR f.lan_ip LIKE ? OR f.ip_address LIKE ?
                                  OR f.ipv6_address LIKE ? OR f.lan_network LIKE ?)';
                    array_push($params, $like, $like, $like, $like, $like);
                    break;

                case 'interface':
                    $where[]  = '(f.wan_interfaces LIKE ? OR f.wan_groups LIKE ?)';
                    array_push($params, $like, $like);
                    break;

                case 'vpn':
                case 'tunnel':
                    // Reported interface stats carry VPN interface names; the
                    // tunnel tables carry named sessions.
                    $where[]  = '(f.wan_interface_stats LIKE ?
                                  OR EXISTS (SELECT 1 FROM ssh_access_sessions sa
                                              WHERE sa.firewall_id = f.id AND sa.rule_label LIKE ?))';
                    array_push($params, $like, $like);
                    break;

                case 'status':
                    $where[]  = 'f.status = ?';
                    $params[] = strtolower($value);
                    break;

                case 'host':
                case 'hostname':
                case 'name':
                    $where[]  = 'f.hostname LIKE ?';
                    $params[] = $like;
                    break;

                default:
                    // Unknown qualifier: treat it as free text rather than
                    // silently dropping the term and returning too much.
                    $parsed['text'][] = $term['field'] . ':' . $value;
                    break;
            }
        }

        // --- free text ------------------------------------------------------
        foreach ($parsed['text'] as $word) {
            $like = '%' . $word . '%';
            $where[] = '(f.hostname LIKE ? OR f.wan_ip LIKE ? OR f.lan_ip LIKE ?
                         OR f.ip_address LIKE ? OR f.ipv6_address LIKE ?
                         OR f.version LIKE ? OR f.agent_version LIKE ?
                         OR f.notes LIKE ? OR f.wan_interfaces LIKE ?
                         OR c.name LIKE ? OR c.code LIKE ? OR s.name LIKE ?)';
            for ($i = 0; $i < 12; $i++) {
                $params[] = $like;
            }
        }

        // --- CIDR -----------------------------------------------------------
        foreach ($parsed['cidrs'] as $cidr) {
            $range = cidr_to_range($cidr);
            if ($range === null) {
                continue;
            }
            // INET_ATON returns NULL for anything that is not dotted-quad, so
            // IPv6 and empty columns simply do not match.
            $where[] = '(
                (INET_ATON(f.wan_ip)     BETWEEN ? AND ?) OR
                (INET_ATON(f.lan_ip)     BETWEEN ? AND ?) OR
                (INET_ATON(f.ip_address) BETWEEN ? AND ?)
            )';
            array_push($params,
                $range[0], $range[1],
                $range[0], $range[1],
                $range[0], $range[1]
            );
        }

        if (!$where) {
            return ['results' => [], 'total' => 0, 'parsed' => $parsed, 'error' => ''];
        }

        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT f.id, f.hostname, f.status, f.wan_ip, f.lan_ip, f.ipv6_address,
                   f.version, f.agent_version, f.last_checkin, f.wan_interfaces,
                   f.customer_id, f.site_id,
                   c.name AS customer_name, c.code AS customer_code,
                   s.name AS site_name,
                   (SELECT GROUP_CONCAT(t.name SEPARATOR ',')
                      FROM firewall_tags ft JOIN tags t ON t.id = ft.tag_id
                     WHERE ft.firewall_id = f.id) AS tag_names
              FROM firewalls f
              LEFT JOIN customers c ON c.id = f.customer_id
              LEFT JOIN sites     s ON s.id = f.site_id
             WHERE {$whereSql}
             ORDER BY f.hostname
             LIMIT {$limit}
        ";

        try {
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $countStmt = db()->prepare("
                SELECT COUNT(*)
                  FROM firewalls f
                  LEFT JOIN customers c ON c.id = f.customer_id
                  LEFT JOIN sites     s ON s.id = f.site_id
                 WHERE {$whereSql}
            ");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('OPNMGR: search_fleet failed: ' . $e->getMessage());
            return ['results' => [], 'total' => 0, 'parsed' => $parsed,
                    'error' => 'The search could not be completed.'];
        }

        return ['results' => $results, 'total' => $total, 'parsed' => $parsed, 'error' => ''];
    }
}

if (!function_exists('search_customers_and_sites')) {
    /**
     * Matching customers and sites, so a search for an organisation lands on it
     * directly rather than only on its firewalls.
     *
     * @return array{customers: array, sites: array}
     */
    function search_customers_and_sites(string $raw, int $limit = 10): array {
        $parsed = parse_search_query($raw);
        $words  = $parsed['text'];

        foreach ($parsed['fields'] as $term) {
            if (in_array($term['field'], ['customer', 'site'], true)) {
                $words[] = $term['value'];
            }
        }

        if (!$words) {
            return ['customers' => [], 'sites' => []];
        }

        $limit = max(1, min(50, $limit));
        // Build the two condition lists separately. Deriving the site clause by
        // string-replacing column names in the customer clause would break the
        // moment either query grew a column whose name contained the other.
        $customerConds = [];
        $siteConds     = [];
        $params        = [];
        foreach ($words as $w) {
            $customerConds[] = '(name LIKE ? OR code LIKE ?)';
            $siteConds[]     = '(s.name LIKE ? OR s.code LIKE ?)';
            array_push($params, '%' . $w . '%', '%' . $w . '%');
        }
        $customerSql = implode(' AND ', $customerConds);
        $siteSql     = implode(' AND ', $siteConds);

        try {
            $cs = db()->prepare("
                SELECT id, name, code, is_active,
                       (SELECT COUNT(*) FROM firewalls f WHERE f.customer_id = customers.id) AS firewall_count
                  FROM customers WHERE {$customerSql} ORDER BY name LIMIT {$limit}");
            $cs->execute($params);
            $customers = $cs->fetchAll(PDO::FETCH_ASSOC);

            $ss = db()->prepare("
                SELECT s.id, s.name, s.code, s.customer_id, c.name AS customer_name,
                       (SELECT COUNT(*) FROM firewalls f WHERE f.site_id = s.id) AS firewall_count
                  FROM sites s JOIN customers c ON c.id = s.customer_id
                 WHERE {$siteSql}
                 ORDER BY s.name LIMIT {$limit}");
            $ss->execute($params);
            $sites = $ss->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('OPNMGR: search_customers_and_sites failed: ' . $e->getMessage());
            return ['customers' => [], 'sites' => []];
        }

        return ['customers' => $customers, 'sites' => $sites];
    }
}
