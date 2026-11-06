<?php
/**
 * OPNMGR policy templates: the template model.
 *
 *   policy_component_catalog()   the bounded set of typed components
 *   policy_component_compat()    per component, per platform: supported or why not
 *   policy_validate_content()    structural validation of a revision's content
 *   policy_template_* / policy_revision_*  lifecycle (draft -> published -> retired)
 *
 * Why the feature set is bounded: every component here has a hand-written,
 * structured renderer for each platform dialect (OPNsense MVC, OPNsense
 * legacy, pfSense). A free-form "set this XML path" component would make the
 * semantic preview, the idempotency guarantee and the secret redaction
 * unprovable, so it is deliberately not offered.
 *
 * Published revisions are immutable. The content hash is computed over the
 * canonical JSON at publish time and re-checked every time a revision is
 * planned, so a row edited behind the application's back is refused rather
 * than deployed.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/../audit.php';

if (!defined('POLICY_SCOPES')) {
    /** Precedence, least to most specific. A later scope overrides an earlier one. */
    define('POLICY_SCOPES', ['global', 'customer', 'site', 'firewall']);
}

if (!function_exists('policy_component_catalog')) {
    /**
     * The components a template may contain.
     *
     * fields: name => [type, required, default]
     *   type is one of the variable types (see policy_variable_types()), or
     *   'enum:a|b|c', 'bool', 'text', 'alias_name', 'endpoint', 'port_ref',
     *   'list:<type>', 'iface_list'
     * sync:   the HA sync item names (OPNsense syncitems / pfSense
     *         synchronizeX) that carry this component to an HA peer.
     */
    function policy_component_catalog(): array {
        return [
            'alias' => [
                'label'  => 'Alias (host / network / port)',
                'fields' => [
                    'name'        => ['alias_name', true, null],
                    'alias_type'  => ['enum:host|network|port', true, null],
                    'content'     => ['list:alias_entry', true, null],
                    'description' => ['text', false, ''],
                ],
                'sync' => ['aliases'],
            ],
            'filter_rule' => [
                'label'  => 'Filter rule',
                'fields' => [
                    'action'           => ['enum:pass|block|reject', true, null],
                    'interfaces'       => ['iface_list', true, null],
                    'direction'        => ['enum:in|out|any', false, 'in'],
                    'ipprotocol'       => ['enum:inet|inet6|inet46', false, 'inet'],
                    'protocol'         => ['protocol', false, 'any'],
                    'source'           => ['endpoint', false, 'any'],
                    'source_port'      => ['port_ref', false, ''],
                    'destination'      => ['endpoint', false, 'any'],
                    'destination_port' => ['port_ref', false, ''],
                    'log'              => ['bool', false, false],
                    'floating'         => ['bool', false, false],
                    'position'         => ['enum:top|bottom', false, 'top'],
                    'description'      => ['text', false, ''],
                ],
                'sync' => ['rules'],
            ],
            'dns_servers' => [
                'label'  => 'System DNS servers',
                'fields' => ['servers' => ['list:ip', true, null]],
                // pfSense "dns" sync is the DNS forwarder, not system DNS;
                // OPNsense does not sync system/dnsserver either.
                'sync' => [],
            ],
            'ntp_servers' => [
                'label'  => 'NTP servers',
                'fields' => ['servers' => ['list:host', true, null]],
                'sync' => ['ntpd'],
            ],
            'management_access' => [
                'label'  => 'Management access restriction (alias + rule)',
                'fields' => [
                    'interface'    => ['interface', true, null],
                    'sources'      => ['list:network', true, null],
                    'ports'        => ['list:port', true, null],
                    'block_others' => ['bool', false, false],
                    'alias_name'   => ['alias_name', false, ''],
                    'description'  => ['text', false, 'Management access'],
                ],
                'sync' => ['aliases', 'rules'],
            ],
            'remote_syslog' => [
                'label'  => 'Remote syslog destination',
                'fields' => [
                    'host'      => ['host', true, null],
                    'port'      => ['port', false, '514'],
                    'transport' => ['enum:udp4|tcp4', false, 'udp4'],
                ],
                'sync' => ['syslog'],
            ],
        ];
    }
}

if (!function_exists('policy_variable_types')) {
    /** Typed variables. Each must validate before a plan can be queued. */
    function policy_variable_types(): array {
        return [
            'ip'           => 'IP address',
            'cidr'         => 'Network (CIDR, network-aligned)',
            'network'      => 'IP address or CIDR network',
            'host'         => 'IP address or hostname',
            'port'         => 'Port or port range (1-65535, a-b)',
            'protocol'     => 'Protocol (any, tcp, udp, tcp/udp, icmp)',
            'interface'    => 'Logical interface mapping (config interface key, e.g. lan, opt1)',
            'string'       => 'Short text',
            'list:ip'      => 'List of IP addresses',
            'list:cidr'    => 'List of CIDR networks',
            'list:network' => 'List of IP addresses or networks',
            'list:host'    => 'List of hosts',
            'list:port'    => 'List of ports',
        ];
    }
}

if (!function_exists('policy_well_known_variables')) {
    /** The variables the product owner named, offered as a starting point in the editor. */
    function policy_well_known_variables(): array {
        return [
            ['name' => 'lan_subnet',        'type' => 'cidr',         'label' => 'LAN subnet'],
            ['name' => 'guest_subnet',      'type' => 'cidr',         'label' => 'Guest subnet'],
            ['name' => 'management_subnet', 'type' => 'cidr',         'label' => 'Management subnet'],
            ['name' => 'dns_servers',       'type' => 'list:ip',      'label' => 'DNS servers'],
            ['name' => 'ntp_servers',       'type' => 'list:host',    'label' => 'NTP servers'],
            ['name' => 'vpn_networks',      'type' => 'list:cidr',    'label' => 'VPN networks'],
            ['name' => 'wan_interface',     'type' => 'interface',    'label' => 'WAN interface'],
            ['name' => 'lan_interface',     'type' => 'interface',    'label' => 'LAN interface'],
            ['name' => 'guest_interface',   'type' => 'interface',    'label' => 'Guest interface'],
        ];
    }
}

// ---------------------------------------------------------------------------
// Value validation
// ---------------------------------------------------------------------------

if (!function_exists('policy_valid_ip')) {
    function policy_valid_ip(string $v): bool {
        return filter_var($v, FILTER_VALIDATE_IP) !== false;
    }
}

if (!function_exists('policy_valid_cidr')) {
    /**
     * a.b.c.d/n or v6/n, and network-aligned: "10.0.0.1/24" is refused because
     * it is almost always a typo for the gateway address, and a rule built
     * from it would not mean what the operator thinks.
     */
    function policy_valid_cidr(string $v): bool {
        if (!preg_match('#^([0-9a-fA-F:.]+)/(\d{1,3})$#', $v, $m) || !policy_valid_ip($m[1])) {
            return false;
        }
        $bits = (int)$m[2];
        $bin = inet_pton($m[1]);
        $max = strlen($bin) * 8;
        if ($bits > $max) {
            return false;
        }
        for ($i = $bits; $i < $max; $i++) {
            if ((ord($bin[intdiv($i, 8)]) >> (7 - $i % 8)) & 1) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('policy_valid_hostname')) {
    function policy_valid_hostname(string $v): bool {
        return strlen($v) <= 253
            && preg_match('/^(?=.*[A-Za-z])([A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $v) === 1;
    }
}

if (!function_exists('policy_valid_port')) {
    /** 1-65535 or an ascending range a-b. */
    function policy_valid_port(string $v): bool {
        if (preg_match('/^\d{1,5}$/', $v)) {
            return (int)$v >= 1 && (int)$v <= 65535;
        }
        if (preg_match('/^(\d{1,5})-(\d{1,5})$/', $v, $m)) {
            return (int)$m[1] >= 1 && (int)$m[2] <= 65535 && (int)$m[1] < (int)$m[2];
        }
        return false;
    }
}

if (!function_exists('policy_valid_alias_name')) {
    /** Both products: letters, digits, underscore; starts with a letter; at most 31. */
    function policy_valid_alias_name(string $v): bool {
        return preg_match('/^[A-Za-z][A-Za-z0-9_]{0,30}$/', $v) === 1;
    }
}

if (!function_exists('policy_valid_iface_key')) {
    /** A config.xml interface key: wan, lan, optN, or a group/vpn name. */
    function policy_valid_iface_key(string $v): bool {
        return preg_match('/^[a-z][a-z0-9_]{0,31}$/', $v) === 1;
    }
}

if (!function_exists('policy_validate_value')) {
    /**
     * Validate one variable value against its type.
     *
     * @param mixed $value
     * @return array{ok:bool, error:string, value:mixed} value is normalized
     */
    function policy_validate_value(string $type, $value): array {
        $bad = fn(string $e) => ['ok' => false, 'error' => $e, 'value' => null];

        if (str_starts_with($type, 'list:')) {
            if (is_string($value)) {
                $value = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
            }
            if (!is_array($value) || !$value) {
                return $bad('must be a non-empty list');
            }
            if (count($value) > 64) {
                return $bad('at most 64 entries');
            }
            $out = [];
            foreach (array_values($value) as $i => $item) {
                $r = policy_validate_value(substr($type, 5), $item);
                if (!$r['ok']) {
                    return $bad('entry ' . ($i + 1) . ': ' . $r['error']);
                }
                $out[] = $r['value'];
            }
            return ['ok' => true, 'error' => '', 'value' => array_values(array_unique($out))];
        }

        if (!is_scalar($value)) {
            return $bad('must be a single value');
        }
        $v = trim((string)$value);
        if ($v === '') {
            return $bad('is empty');
        }

        return match ($type) {
            'ip'        => policy_valid_ip($v) ? ['ok' => true, 'error' => '', 'value' => $v] : $bad("'{$v}' is not an IP address"),
            'cidr'      => policy_valid_cidr($v) ? ['ok' => true, 'error' => '', 'value' => strtolower($v)]
                           : $bad("'{$v}' is not a network-aligned CIDR"),
            'network'   => (policy_valid_ip($v) || policy_valid_cidr($v)) ? ['ok' => true, 'error' => '', 'value' => strtolower($v)]
                           : $bad("'{$v}' is not an IP address or network-aligned CIDR"),
            'host'      => (policy_valid_ip($v) || policy_valid_hostname($v)) ? ['ok' => true, 'error' => '', 'value' => strtolower($v)]
                           : $bad("'{$v}' is not an IP address or hostname"),
            'port'      => policy_valid_port($v) ? ['ok' => true, 'error' => '', 'value' => $v] : $bad("'{$v}' is not a port or port range"),
            'protocol'  => in_array(strtolower($v), ['any', 'tcp', 'udp', 'tcp/udp', 'icmp'], true)
                           ? ['ok' => true, 'error' => '', 'value' => strtolower($v)] : $bad("'{$v}' is not a supported protocol"),
            'interface' => policy_valid_iface_key($v) ? ['ok' => true, 'error' => '', 'value' => $v]
                           : $bad("'{$v}' is not an interface key"),
            'string'    => (strlen($v) <= 255 && !preg_match('/[\x00-\x1f<>]/', $v))
                           ? ['ok' => true, 'error' => '', 'value' => $v] : $bad('is not acceptable text'),
            default     => $bad("unknown type {$type}"),
        };
    }
}

// ---------------------------------------------------------------------------
// Content validation (structure only; values are checked after substitution)
// ---------------------------------------------------------------------------

if (!function_exists('policy_canonical_json')) {
    /** Key-sorted JSON so the same content always hashes the same. */
    function policy_canonical_json($data): string {
        $sort = function ($v) use (&$sort) {
            if (is_array($v)) {
                if (array_keys($v) !== range(0, count($v) - 1)) {
                    ksort($v);
                }
                return array_map($sort, $v);
            }
            return $v;
        };
        return json_encode($sort($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('policy_validate_content')) {
    /**
     * Validate a revision's content: declared variables and components.
     *
     * @param mixed $content decoded JSON
     * @return array{ok:bool, errors:string[], content:array}
     */
    function policy_validate_content($content): array {
        $errors = [];
        if (!is_array($content)) {
            return ['ok' => false, 'errors' => ['Content must be a JSON object'], 'content' => []];
        }
        $vars = $content['variables'] ?? [];
        $comps = $content['components'] ?? [];
        if (!is_array($vars) || !is_array($comps)) {
            return ['ok' => false, 'errors' => ['variables and components must be lists'], 'content' => []];
        }

        $types = policy_variable_types();
        $declared = [];
        $cleanVars = [];
        foreach ($vars as $i => $v) {
            $name = (string)($v['name'] ?? '');
            $type = (string)($v['type'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
                $errors[] = "Variable #" . ($i + 1) . ": invalid name";
                continue;
            }
            if (!isset($types[$type])) {
                $errors[] = "Variable {$name}: unknown type '{$type}'";
                continue;
            }
            if (isset($declared[$name])) {
                $errors[] = "Variable {$name} is declared twice";
                continue;
            }
            $row = ['name' => $name, 'type' => $type, 'required' => (bool)($v['required'] ?? true),
                    'label' => substr((string)($v['label'] ?? $name), 0, 80)];
            if (array_key_exists('default', $v) && $v['default'] !== null && $v['default'] !== '') {
                $d = policy_validate_value($type, $v['default']);
                if (!$d['ok']) {
                    $errors[] = "Variable {$name}: default {$d['error']}";
                } else {
                    $row['default'] = $d['value'];
                }
            }
            $declared[$name] = $type;
            $cleanVars[] = $row;
        }

        $catalog = policy_component_catalog();
        $keys = [];
        $cleanComps = [];
        foreach ($comps as $i => $c) {
            $label = 'Component #' . ($i + 1);
            $key = (string)($c['key'] ?? '');
            $type = (string)($c['type'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9_]{0,47}$/', $key)) {
                $errors[] = "{$label}: key must be lowercase letters, digits, underscore";
                continue;
            }
            $label = "Component {$key}";
            if (isset($keys[$key])) {
                $errors[] = "{$label}: duplicate key";
                continue;
            }
            $keys[$key] = true;
            if (!isset($catalog[$type])) {
                $errors[] = "{$label}: unknown type '{$type}'";
                continue;
            }
            $row = ['key' => $key, 'type' => $type];
            foreach ($catalog[$type]['fields'] as $f => [$ftype, $req, $default]) {
                $has = array_key_exists($f, $c) && $c[$f] !== null && $c[$f] !== '' && $c[$f] !== [];
                if (!$has) {
                    if ($req) {
                        $errors[] = "{$label}: '{$f}' is required";
                    }
                    $row[$f] = $default;
                    continue;
                }
                $row[$f] = $c[$f];
                // References must name declared variables of a compatible type.
                foreach (policy_variable_refs($c[$f]) as $ref) {
                    if (!isset($declared[$ref])) {
                        $errors[] = "{$label}: '{$f}' references undeclared variable {$ref}";
                    }
                }
            }
            foreach (array_keys($c) as $f) {
                if (!in_array($f, ['key', 'type'], true) && !isset($catalog[$type]['fields'][$f])) {
                    $errors[] = "{$label}: unknown field '{$f}'";
                }
            }
            $cleanComps[] = $row;
        }
        if (count($cleanComps) > 200) {
            $errors[] = 'At most 200 components per template';
        }
        $syslog = array_filter($cleanComps, fn($c) => $c['type'] === 'remote_syslog');
        foreach (['dns_servers', 'ntp_servers'] as $single) {
            if (count(array_filter($cleanComps, fn($c) => $c['type'] === $single)) > 1) {
                $errors[] = "Only one {$single} component per template";
            }
        }
        if (count($syslog) > 3) {
            $errors[] = 'At most three remote syslog destinations (pfSense has three slots)';
        }

        return ['ok' => !$errors, 'errors' => $errors,
                'content' => ['variables' => $cleanVars, 'components' => $cleanComps]];
    }
}

if (!function_exists('policy_variable_refs')) {
    /** Variable names referenced as {{name}} anywhere in a value. */
    function policy_variable_refs($value): array {
        $out = [];
        $walk = function ($v) use (&$walk, &$out) {
            if (is_array($v)) {
                foreach ($v as $x) { $walk($x); }
            } elseif (is_string($v) && preg_match_all('/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/', $v, $m)) {
                foreach ($m[1] as $n) { $out[$n] = true; }
            }
        };
        $walk($value);
        return array_keys($out);
    }
}

if (!function_exists('policy_component_compat')) {
    /**
     * Whether a component can be rendered for a platform family and the
     * dialect its configuration uses.
     *
     * @param array $dialect from policy_config_dialect(): aliases, rules, syslog
     * @return array{supported:bool, reason:string, target:string}
     */
    function policy_component_compat(string $type, string $family, array $dialect = []): array {
        $no = fn(string $r) => ['supported' => false, 'reason' => $r, 'target' => ''];
        if (!in_array($family, ['opnsense', 'pfsense'], true)) {
            return $no('Platform not verified');
        }
        $aliases = $dialect['aliases'] ?? ($family === 'opnsense' ? 'mvc' : 'legacy');
        $rules   = $dialect['rules']   ?? ($family === 'opnsense' ? 'mvc' : 'legacy');
        $syslog  = $dialect['syslog']  ?? ($family === 'opnsense' ? 'mvc' : 'legacy');
        return match ($type) {
            'alias' => ['supported' => true, 'reason' => '',
                        'target' => $aliases === 'mvc' ? 'OPNsense/Firewall/Alias/aliases/alias' : 'aliases/alias'],
            'filter_rule' => ['supported' => true, 'reason' => '',
                        'target' => $rules === 'mvc' ? 'OPNsense/Firewall/Filter/rules/rule' : 'filter/rule'],
            'management_access' => ['supported' => true, 'reason' => '',
                        'target' => 'alias + ' . ($rules === 'mvc' ? 'MVC rule' : 'filter/rule')],
            'dns_servers' => ['supported' => true, 'reason' => '', 'target' => 'system/dnsserver'],
            'ntp_servers' => ['supported' => true, 'reason' => '', 'target' => 'system/timeservers'],
            'remote_syslog' => $family === 'pfsense'
                ? ['supported' => true, 'reason' => '', 'target' => 'syslog/remoteserver[1-3]']
                : ($syslog === 'mvc'
                    ? ['supported' => true, 'reason' => '', 'target' => 'OPNsense/Syslog/destinations/destination']
                    : $no('This OPNsense configuration has no MVC Syslog section (pre-19.7 layout); not implemented')),
            default => $no("Unknown component {$type}"),
        };
    }
}

// ---------------------------------------------------------------------------
// Templates and revisions
// ---------------------------------------------------------------------------

if (!function_exists('policy_actor')) {
    function policy_actor(): string {
        return (string)($_SESSION['username'] ?? (function_exists('audit_cli_username') ? (audit_cli_username() ?? 'system') : 'system'));
    }
}

if (!function_exists('policy_template_create')) {
    /** @return array{ok:bool, error:string, id:int, revision_id:int} */
    function policy_template_create(string $name, string $description = '', ?array $content = null): array {
        $name = trim($name);
        if ($name === '' || strlen($name) > 128) {
            return ['ok' => false, 'error' => 'Give the template a name (at most 128 characters)', 'id' => 0, 'revision_id' => 0];
        }
        try {
            db()->prepare('INSERT INTO policy_templates (name, description, created_by) VALUES (?,?,?)')
                ->execute([$name, substr($description, 0, 2000) ?: null, policy_actor()]);
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'A template with that name already exists', 'id' => 0, 'revision_id' => 0];
        }
        $id = (int)db()->lastInsertId();
        $rev = policy_revision_create_draft($id, $content ?? ['variables' => [], 'components' => []]);
        audit_log('policy.template.create', ['object_type' => 'policy_template', 'object_id' => (string)$id,
            'message' => 'Template created: ' . $name]);
        return ['ok' => true, 'error' => '', 'id' => $id, 'revision_id' => $rev['id']];
    }
}

if (!function_exists('policy_template_get')) {
    function policy_template_get(int $id): ?array {
        $s = db()->prepare('SELECT * FROM policy_templates WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('policy_template_list')) {
    function policy_template_list(): array {
        return db()->query(
            'SELECT t.*,
                    (SELECT MAX(revision) FROM policy_template_revisions r WHERE r.template_id = t.id) AS latest_revision,
                    (SELECT MAX(revision) FROM policy_template_revisions r WHERE r.template_id = t.id AND r.status = "published") AS published_revision,
                    (SELECT COUNT(*) FROM policy_assignments a WHERE a.template_id = t.id AND a.is_active = 1) AS assignment_count
               FROM policy_templates t ORDER BY t.is_retired, t.name'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('policy_revision_get')) {
    /** A revision with decoded content and an integrity verdict. */
    function policy_revision_get(int $revisionId): ?array {
        $s = db()->prepare('SELECT * FROM policy_template_revisions WHERE id = ?');
        $s->execute([$revisionId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        $r['content_decoded'] = json_decode((string)$r['content'], true) ?: ['variables' => [], 'components' => []];
        $r['integrity_ok'] = hash_equals((string)$r['content_hash'], hash('sha256', policy_canonical_json($r['content_decoded'])));
        return $r;
    }
}

if (!function_exists('policy_revisions_for')) {
    function policy_revisions_for(int $templateId): array {
        $s = db()->prepare('SELECT id, template_id, revision, status, content_hash, notes, created_by, created_at,
                                   published_by, published_at, retired_at
                              FROM policy_template_revisions WHERE template_id = ? ORDER BY revision DESC');
        $s->execute([$templateId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('policy_revision_create_draft')) {
    /**
     * New draft revision, optionally copied from an existing one. Only one
     * draft per template at a time, so "the draft" is unambiguous.
     *
     * @return array{ok:bool, error:string, id:int}
     */
    function policy_revision_create_draft(int $templateId, ?array $content = null, ?int $fromRevisionId = null): array {
        $s = db()->prepare('SELECT id FROM policy_template_revisions WHERE template_id = ? AND status = "draft"');
        $s->execute([$templateId]);
        if ($existing = $s->fetchColumn()) {
            return ['ok' => false, 'error' => 'This template already has a draft', 'id' => (int)$existing];
        }
        if ($content === null && $fromRevisionId !== null) {
            $from = policy_revision_get($fromRevisionId);
            $content = $from && (int)$from['template_id'] === $templateId ? $from['content_decoded'] : null;
        }
        $content = $content ?? ['variables' => [], 'components' => []];
        $next = db()->prepare('SELECT COALESCE(MAX(revision), 0) + 1 FROM policy_template_revisions WHERE template_id = ?');
        $next->execute([$templateId]);
        $rev = (int)$next->fetchColumn();
        db()->prepare('INSERT INTO policy_template_revisions (template_id, revision, status, content, content_hash, created_by)
                       VALUES (?,?,"draft",?,?,?)')
            ->execute([$templateId, $rev, json_encode($content, JSON_UNESCAPED_SLASHES),
                       hash('sha256', policy_canonical_json($content)), policy_actor()]);
        return ['ok' => true, 'error' => '', 'id' => (int)db()->lastInsertId()];
    }
}

if (!function_exists('policy_revision_update_draft')) {
    /**
     * Replace a draft's content. Published and retired revisions are refused
     * here and nowhere else writes `content`, which is what makes them immutable.
     *
     * @return array{ok:bool, errors:string[]}
     */
    function policy_revision_update_draft(int $revisionId, $content, string $notes = ''): array {
        $rev = policy_revision_get($revisionId);
        if (!$rev) {
            return ['ok' => false, 'errors' => ['Revision not found']];
        }
        if ($rev['status'] !== 'draft') {
            return ['ok' => false, 'errors' => ['Published and retired revisions are immutable; create a new draft']];
        }
        $v = policy_validate_content($content);
        if (!$v['ok']) {
            return ['ok' => false, 'errors' => $v['errors']];
        }
        $stmt = db()->prepare('UPDATE policy_template_revisions SET content = ?, content_hash = ?, notes = ?
                                WHERE id = ? AND status = "draft"');
        $stmt->execute([json_encode($v['content'], JSON_UNESCAPED_SLASHES),
                        hash('sha256', policy_canonical_json($v['content'])),
                        substr($notes, 0, 255) ?: null, $revisionId]);
        audit_log('policy.revision.update', ['object_type' => 'policy_revision', 'object_id' => (string)$revisionId,
            'message' => sprintf('Draft revision %d of template #%d updated', $rev['revision'], $rev['template_id']),
            'metadata' => ['components' => count($v['content']['components']), 'variables' => count($v['content']['variables'])]]);
        return ['ok' => true, 'errors' => []];
    }
}

if (!function_exists('policy_revision_publish')) {
    /** @return array{ok:bool, errors:string[]} */
    function policy_revision_publish(int $revisionId): array {
        $rev = policy_revision_get($revisionId);
        if (!$rev || $rev['status'] !== 'draft') {
            return ['ok' => false, 'errors' => ['Only a draft can be published']];
        }
        $v = policy_validate_content($rev['content_decoded']);
        if (!$v['ok']) {
            return ['ok' => false, 'errors' => $v['errors']];
        }
        if (!$v['content']['components']) {
            return ['ok' => false, 'errors' => ['A template with no components cannot be published']];
        }
        $hash = hash('sha256', policy_canonical_json($v['content']));
        db()->prepare('UPDATE policy_template_revisions
                          SET status = "published", content = ?, content_hash = ?, published_by = ?, published_at = NOW()
                        WHERE id = ? AND status = "draft"')
            ->execute([json_encode($v['content'], JSON_UNESCAPED_SLASHES), $hash, policy_actor(), $revisionId]);
        audit_log('policy.revision.publish', ['object_type' => 'policy_revision', 'object_id' => (string)$revisionId,
            'message' => sprintf('Template #%d revision %d published', $rev['template_id'], $rev['revision']),
            'metadata' => ['content_hash' => $hash]]);
        return ['ok' => true, 'errors' => []];
    }
}

if (!function_exists('policy_revision_retire')) {
    /** Retired revisions can no longer be assigned or deployed; existing assignments are reported as invalid. */
    function policy_revision_retire(int $revisionId): array {
        $stmt = db()->prepare('UPDATE policy_template_revisions SET status = "retired", retired_at = NOW()
                                WHERE id = ? AND status = "published"');
        $stmt->execute([$revisionId]);
        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'errors' => ['Only a published revision can be retired']];
        }
        audit_log('policy.revision.retire', ['object_type' => 'policy_revision', 'object_id' => (string)$revisionId,
            'message' => 'Template revision retired']);
        return ['ok' => true, 'errors' => []];
    }
}
