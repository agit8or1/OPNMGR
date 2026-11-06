<?php
/**
 * OPNMGR policy templates: planning and rendering.
 *
 *   policy_plan($baseXml, $ctx)  -> the semantic plan for one firewall and the
 *                                   full target configuration (artifact)
 *
 * The artifact is built by structured DOM edits of the base configuration:
 * nodes are found by identity, fields are set by element name, nodes are
 * inserted, moved or removed. No regular expression or text substitution ever
 * touches the XML. Everything outside the managed nodes is left as the DOM
 * parsed it, which tests prove section by section with canonical XML.
 *
 * Managed-object identity
 * -----------------------
 * Every object a template creates carries a marker at the end of its
 * description:
 *
 *     Allow DNS [opnmgr:tpl=12:obj=allow_dns]
 *
 * tpl is the template id (stable across revisions), obj the component key;
 * rules of a template's bottom block carry a trailing `:bottom` so other
 * templates can place their own blocks around them.
 * Objects with a uuid (OPNsense MVC aliases, rules, syslog destinations) also
 * get a deterministic uuid derived from tpl+key, and pfSense rules a
 * deterministic <tracker>, so re-rendering the same plan produces the same
 * bytes - which is what lets the artifact be re-rendered at fetch time and
 * checked against the approved sha256 instead of being stored.
 *
 * An object without the marker is unmanaged and is never modified, moved or
 * removed. If a template object's name collides with an unmanaged object, the
 * plan is blocked until an operator maps it explicitly
 * (policy_mapping_set); the mapped object then appears as "adopt" with the
 * exact fields that will change.
 *
 * Settings without an object identity (system DNS list, NTP list, pfSense
 * remote syslog slots) are owned by the component as a whole; the plan shows
 * the before and after values.
 *
 * Ordering
 * --------
 * Within a rule section a template's rules form one contiguous block: `top`
 * rules go before every unmanaged rule, `bottom` rules after them. Blocks of
 * several templates are ordered by template id (lower id nearer the edge of
 * the section), so planning one template never reorders another and the
 * placement is a pure function of the configuration - re-planning a result
 * yields no moves. In OPNsense MVC the evaluation order is <sequence>; top
 * rules get 1..n, bottom rules 99000+i, and are also placed first/last in the
 * document so equal sequence numbers resolve in the managed block's favour
 * (tie-breaking inside OPNsense is NOT verified on a live device). See
 * docs/POLICY_TEMPLATES.md for floating/interface rules on pfSense.
 *
 * Secrets: the plan only ever contains fields of managed objects, which are
 * template values. It never contains anything read from unrelated parts of the
 * configuration, and policy_redact() is applied to everything persisted.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/model.php';
require_once __DIR__ . '/assignment.php';

const POLICY_MARKER_RE = '/\s*\[opnmgr:tpl=(\d+):obj=([a-z0-9_]+)(:bottom)?\]\s*$/';

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

if (!function_exists('policy_marker')) {
    /** $bottom marks a rule of the bottom block, so other templates can place theirs around it. */
    function policy_marker(int $templateId, string $key, bool $bottom = false): string {
        return "[opnmgr:tpl={$templateId}:obj={$key}" . ($bottom ? ':bottom' : '') . ']';
    }
}

if (!function_exists('policy_parse_marker')) {
    /** @return array{0:int,1:string,2:bool}|null  [template id, object key, bottom block] */
    function policy_parse_marker(?string $descr): ?array {
        if ($descr !== null && preg_match(POLICY_MARKER_RE, $descr, $m)) {
            return [(int)$m[1], $m[2], ($m[3] ?? '') !== ''];
        }
        return null;
    }
}

if (!function_exists('policy_uuid')) {
    /** Deterministic RFC 4122-shaped uuid for a managed object. */
    function policy_uuid(int $templateId, string $key): string {
        $h = hash('sha256', "opnmgr-policy:{$templateId}:{$key}");
        return sprintf('%s-%s-5%s-%s%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3),
            dechex(8 | (hexdec($h[16]) & 3)), substr($h, 17, 3), substr($h, 20, 12));
    }
}

if (!function_exists('policy_tracker')) {
    /** Deterministic pfSense rule tracker in a band pfSense's time()-based trackers never reach. */
    function policy_tracker(int $templateId, string $key): string {
        return (string)(4000000000 + (hexdec(substr(hash('sha256', "opnmgr-tracker:{$templateId}:{$key}"), 0, 7)) % 200000000));
    }
}

if (!function_exists('policy_ip_in')) {
    /** Whether an IP lies in an IP/CIDR. */
    function policy_ip_in(string $ip, string $net): bool {
        if (!str_contains($net, '/')) {
            return @inet_pton($ip) !== false && @inet_pton($ip) === @inet_pton($net);
        }
        [$base, $bits] = explode('/', $net, 2);
        $a = @inet_pton($ip);
        $b = @inet_pton($base);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = (int)$bits;
        for ($i = 0; $i < $bits; $i++) {
            $byte = intdiv($i, 8);
            $mask = 1 << (7 - $i % 8);
            if ((ord($a[$byte]) & $mask) !== (ord($b[$byte]) & $mask)) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('policy_redact')) {
    /**
     * Last line of defence for anything persisted or shown: drop
     * credential-bearing keys and any value shaped like key material.
     */
    function policy_redact($data, int $depth = 0) {
        if ($depth > 10) {
            return '[truncated]';
        }
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                if (is_string($k) && preg_match('/pass(word|wd)?|secret|psk|pre-?shared|private|prv|key$|token|bcrypt|hash$/i', $k)
                    && !in_array($k, ['plan_hash', 'content_hash', 'key', 'object_key'], true)) {
                    $out[$k] = '[redacted]';
                    continue;
                }
                $out[$k] = policy_redact($v, $depth + 1);
            }
            return $out;
        }
        if (is_string($data) && preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----|\$2[aby]\$\d\d\$/', $data)) {
            return '[redacted]';
        }
        return $data;
    }
}

// ---------------------------------------------------------------------------
// DOM helpers (structured edits only)
// ---------------------------------------------------------------------------

if (!function_exists('policy_dom_load')) {
    function policy_dom_load(string $xml): ?DOMDocument {
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false) {
            return null;
        }
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $ok ? $doc : null;
    }
}

if (!function_exists('policy_dom_child')) {
    function policy_dom_child(DOMNode $parent, string $name): ?DOMElement {
        foreach ($parent->childNodes as $c) {
            if ($c instanceof DOMElement && $c->nodeName === $name) {
                return $c;
            }
        }
        return null;
    }
}

if (!function_exists('policy_dom_children')) {
    /** @return DOMElement[] */
    function policy_dom_children(DOMNode $parent, ?string $name = null): array {
        $out = [];
        foreach ($parent->childNodes as $c) {
            if ($c instanceof DOMElement && ($name === null || $c->nodeName === $name)) {
                $out[] = $c;
            }
        }
        return $out;
    }
}

if (!function_exists('policy_dom_path')) {
    /** Walk element names from a node; null when any step is missing. */
    function policy_dom_path(DOMNode $from, string $path): ?DOMElement {
        $n = $from;
        foreach (explode('/', $path) as $step) {
            $n = policy_dom_child($n, $step);
            if ($n === null) {
                return null;
            }
        }
        return $n instanceof DOMElement ? $n : null;
    }
}

if (!function_exists('policy_dom_text')) {
    function policy_dom_text(DOMElement $el, string $child): ?string {
        $c = policy_dom_child($el, $child);
        return $c === null ? null : $c->textContent;
    }
}

if (!function_exists('policy_dom_indent')) {
    /** Whitespace preceding a node, i.e. its indentation, or null. */
    function policy_dom_indent(DOMNode $n): ?string {
        $p = $n->previousSibling;
        if ($p instanceof DOMText && trim($p->data) === '') {
            $nl = strrpos($p->data, "\n");
            return $nl === false ? null : substr($p->data, $nl + 1);
        }
        return null;
    }
}

if (!function_exists('policy_dom_child_indent')) {
    /** [childIndent, unit] for a container. */
    function policy_dom_child_indent(DOMElement $parent): array {
        $own = policy_dom_indent($parent) ?? '';
        $unit = str_contains($own, "\t") ? "\t" : '  ';
        foreach (policy_dom_children($parent) as $c) {
            $ci = policy_dom_indent($c);
            if ($ci !== null) {
                if (strlen($ci) > strlen($own)) {
                    $unit = substr($ci, strlen($own)) ?: $unit;
                }
                return [$ci, $unit];
            }
        }
        return [$own . $unit, $unit];
    }
}

if (!function_exists('policy_dom_insert')) {
    /**
     * Insert $new into $parent before $ref (or at the end), with indentation
     * that matches its siblings so the file stays readable.
     */
    function policy_dom_insert(DOMElement $parent, DOMElement $new, ?DOMNode $ref = null): void {
        $doc = $parent->ownerDocument;
        [$indent] = policy_dom_child_indent($parent);
        $hasElements = (bool)policy_dom_children($parent);
        if ($ref !== null) {
            $parent->insertBefore($new, $ref);
            $parent->insertBefore($doc->createTextNode("\n" . $indent), $ref);
            return;
        }
        if (!$hasElements) {
            // Empty container (<rules/> or whitespace only): give it a body.
            while ($parent->firstChild) {
                $parent->removeChild($parent->firstChild);
            }
            $parent->appendChild($doc->createTextNode("\n" . $indent));
            $parent->appendChild($new);
            $parent->appendChild($doc->createTextNode("\n" . (policy_dom_indent($parent) ?? '')));
            return;
        }
        $last = $parent->lastChild;
        if ($last instanceof DOMText && trim($last->data) === '') {
            $parent->insertBefore($doc->createTextNode("\n" . $indent), $last);
            $parent->insertBefore($new, $last);
        } else {
            $parent->appendChild($doc->createTextNode("\n" . $indent));
            $parent->appendChild($new);
        }
    }
}

if (!function_exists('policy_dom_remove')) {
    function policy_dom_remove(DOMElement $el): void {
        $parent = $el->parentNode;
        $prev = $el->previousSibling;
        if ($prev instanceof DOMText && trim($prev->data) === '') {
            $parent->removeChild($prev);
        }
        $parent->removeChild($el);
    }
}

if (!function_exists('policy_dom_build')) {
    /**
     * Build an element from an ordered field list.
     * Values: string -> text, '' -> empty element, array -> nested element.
     */
    function policy_dom_build(DOMDocument $doc, string $name, array $fields, array $attrs, string $indent, string $unit): DOMElement {
        $el = $doc->createElement($name);
        foreach ($attrs as $k => $v) {
            $el->setAttribute($k, $v);
        }
        foreach ($fields as $k => $v) {
            $el->appendChild($doc->createTextNode("\n" . $indent . $unit));
            if (is_array($v)) {
                $el->appendChild(policy_dom_build($doc, $k, $v, [], $indent . $unit, $unit));
            } else {
                $c = $doc->createElement($k);
                if ((string)$v !== '') {
                    $c->appendChild($doc->createTextNode((string)$v));
                }
                $el->appendChild($c);
            }
        }
        if ($fields) {
            $el->appendChild($doc->createTextNode("\n" . $indent));
        }
        return $el;
    }
}

if (!function_exists('policy_dom_set')) {
    /**
     * Set a scalar child field; returns true when it changed.
     * Missing children are appended with matching indentation.
     */
    function policy_dom_set(DOMElement $el, string $child, string $value): bool {
        $c = policy_dom_child($el, $child);
        if ($c !== null) {
            if ($c->textContent === $value && !policy_dom_children($c)) {
                return false;
            }
            while ($c->firstChild) {
                $c->removeChild($c->firstChild);
            }
            if ($value !== '') {
                $c->appendChild($el->ownerDocument->createTextNode($value));
            }
            return true;
        }
        $n = $el->ownerDocument->createElement($child);
        if ($value !== '') {
            $n->appendChild($el->ownerDocument->createTextNode($value));
        }
        policy_dom_insert($el, $n);
        return true;
    }
}

if (!function_exists('policy_dom_set_flag')) {
    /** pfSense/legacy presence flag (<log/>). */
    function policy_dom_set_flag(DOMElement $el, string $child, bool $on): bool {
        $c = policy_dom_child($el, $child);
        if ($on && $c === null) {
            policy_dom_insert($el, $el->ownerDocument->createElement($child));
            return true;
        }
        if (!$on && $c !== null) {
            policy_dom_remove($c);
            return true;
        }
        return false;
    }
}

if (!function_exists('policy_dom_ensure')) {
    /** Find or create a container chain below $from. */
    function policy_dom_ensure(DOMElement $from, string $path): DOMElement {
        $n = $from;
        foreach (explode('/', $path) as $step) {
            $c = policy_dom_child($n, $step);
            if ($c === null) {
                $c = $n->ownerDocument->createElement($step);
                policy_dom_insert($n, $c);
            }
            $n = $c;
        }
        return $n;
    }
}

// ---------------------------------------------------------------------------
// Dialect and inventory
// ---------------------------------------------------------------------------

if (!function_exists('policy_config_dialect')) {
    /** Which layout this configuration uses for each section we manage. */
    function policy_config_dialect(DOMElement $root): array {
        $isOpn = $root->nodeName === 'opnsense';
        return [
            'aliases' => $isOpn && policy_dom_path($root, 'OPNsense/Firewall/Alias/aliases') ? 'mvc' : 'legacy',
            'rules'   => $isOpn && policy_dom_path($root, 'OPNsense/Firewall/Filter/rules') ? 'mvc' : 'legacy',
            'syslog'  => $isOpn ? (policy_dom_path($root, 'OPNsense/Syslog') ? 'mvc' : 'none') : 'legacy',
        ];
    }
}

if (!function_exists('policy_config_interfaces')) {
    function policy_config_interfaces(DOMElement $root): array {
        $out = [];
        if ($ifs = policy_dom_child($root, 'interfaces')) {
            foreach (policy_dom_children($ifs) as $c) {
                $out[$c->nodeName] = true;
            }
        }
        if ($groups = policy_dom_child($root, 'ifgroups')) {
            foreach (policy_dom_children($groups, 'ifgroupentry') as $g) {
                $n = policy_dom_text($g, 'ifname');
                if ($n !== null && $n !== '') { $out[$n] = true; }
            }
        }
        return array_keys($out);
    }
}

if (!function_exists('policy_alias_nodes')) {
    /** @return array{container:?DOMElement, nodes:DOMElement[], descr:string, content:string} */
    function policy_alias_nodes(DOMElement $root, string $dialect): array {
        if ($dialect === 'mvc') {
            $c = policy_dom_path($root, 'OPNsense/Firewall/Alias/aliases');
            return ['container' => $c, 'nodes' => $c ? policy_dom_children($c, 'alias') : [],
                    'descr' => 'description', 'content' => 'content'];
        }
        $c = policy_dom_child($root, 'aliases');
        return ['container' => $c, 'nodes' => $c ? policy_dom_children($c, 'alias') : [],
                'descr' => 'descr', 'content' => 'address'];
    }
}

if (!function_exists('policy_rule_nodes')) {
    function policy_rule_nodes(DOMElement $root, string $dialect): array {
        if ($dialect === 'mvc') {
            $c = policy_dom_path($root, 'OPNsense/Firewall/Filter/rules');
            return ['container' => $c, 'nodes' => $c ? policy_dom_children($c, 'rule') : [], 'descr' => 'description'];
        }
        $c = policy_dom_child($root, 'filter');
        return ['container' => $c, 'nodes' => $c ? policy_dom_children($c, 'rule') : [], 'descr' => 'descr'];
    }
}

// ---------------------------------------------------------------------------
// Substitution and concrete validation
// ---------------------------------------------------------------------------

if (!function_exists('policy_subst')) {
    /**
     * Replace {{var}} references. A value that is exactly one reference takes
     * the variable's value as-is (lists stay lists); a reference embedded in
     * text must be scalar.
     *
     * @throws InvalidArgumentException on an unknown or list-in-text reference
     */
    function policy_subst($value, array $values) {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $v) {
                $r = policy_subst($v, $values);
                if (is_array($r)) { array_push($out, ...$r); } else { $out[] = $r; }
            }
            return $out;
        }
        if (!is_string($value)) {
            return $value;
        }
        if (preg_match('/^\{\{\s*([a-z][a-z0-9_]*)\s*\}\}$/', $value, $m)) {
            if (!isset($values[$m[1]])) {
                throw new InvalidArgumentException("variable {$m[1]} has no value");
            }
            return $values[$m[1]]['value'];
        }
        return preg_replace_callback('/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/', function ($m) use ($values) {
            if (!isset($values[$m[1]])) {
                throw new InvalidArgumentException("variable {$m[1]} has no value");
            }
            if (is_array($values[$m[1]]['value'])) {
                throw new InvalidArgumentException("list variable {$m[1]} cannot be embedded in text");
            }
            return (string)$values[$m[1]]['value'];
        }, $value);
    }
}

if (!function_exists('policy_concrete_objects')) {
    /**
     * Turn template components into concrete objects for one firewall:
     * variables substituted, management_access expanded, every value
     * validated against the target configuration.
     *
     * @return array{objects:array, errors:string[]}
     */
    function policy_concrete_objects(array $content, array $values, array $ifaces, array $configAliases): array {
        $errors = [];
        $objects = [];
        $tplAliases = [];
        foreach ($content['components'] as $c) {
            if ($c['type'] === 'alias') { $tplAliases[strtolower((string)$c['name'])] = $c['alias_type']; }
            if ($c['type'] === 'management_access') { $tplAliases[strtolower(policy_mgmt_alias_name($c))] = 'network'; }
        }
        $aliasKnown = fn(string $n) => isset($tplAliases[strtolower($n)]) || isset($configAliases[strtolower($n)]);

        $checkIface = function (string $i, string $where) use ($ifaces, &$errors) {
            if (!in_array($i, $ifaces, true)) {
                $errors[] = "{$where}: interface '{$i}' does not exist on this firewall (check the interface mapping)";
                return false;
            }
            return true;
        };
        $checkEndpoint = function (string $v, string $where) use ($checkIface, $aliasKnown, &$errors) {
            if ($v === 'any' || $v === 'self') { return; }
            if (str_starts_with($v, 'net:')) { $checkIface(substr($v, 4), $where); return; }
            if (policy_valid_ip($v) || policy_valid_cidr($v)) { return; }
            if (policy_valid_alias_name($v) && $aliasKnown($v)) { return; }
            $errors[] = "{$where}: '{$v}' is not any, self, net:<interface>, an IP, a network-aligned CIDR or a known alias";
        };
        $checkPortRef = function (string $v, string $where) use ($aliasKnown, &$errors) {
            if ($v === '' || policy_valid_port($v) || (policy_valid_alias_name($v) && $aliasKnown($v))) { return; }
            $errors[] = "{$where}: '{$v}' is not a port, port range or known port alias";
        };

        foreach ($content['components'] as $c) {
            $where = "Component {$c['key']}";
            try {
                $s = [];
                foreach ($c as $f => $v) {
                    $s[$f] = in_array($f, ['key', 'type'], true) ? $v : policy_subst($v, $values);
                }
            } catch (InvalidArgumentException $e) {
                $errors[] = "{$where}: " . $e->getMessage();
                continue;
            }

            switch ($c['type']) {
                case 'alias':
                    $entries = array_values(array_map('strval', (array)$s['content']));
                    $type = $s['alias_type'];
                    foreach ($entries as $i => $e) {
                        $ok = match ($type) {
                            'host'    => policy_valid_ip($e) || policy_valid_hostname($e),
                            'network' => policy_valid_ip($e) || policy_valid_cidr($e),
                            'port'    => policy_valid_port($e),
                            default   => false,
                        };
                        if (!$ok) { $errors[] = "{$where}: '{$e}' is not a valid {$type} alias entry"; }
                        if ($type === 'port') { $entries[$i] = str_replace('-', ':', $e); }
                    }
                    if (!policy_valid_alias_name((string)$s['name'])) {
                        $errors[] = "{$where}: alias name '{$s['name']}' is invalid";
                    }
                    $objects[] = ['kind' => 'alias', 'key' => $c['key'], 'origin' => $c['key'], 'name' => (string)$s['name'],
                                  'alias_type' => $type, 'content' => $entries, 'description' => (string)$s['description']];
                    break;

                case 'filter_rule':
                    $ifs = array_values(array_map('strval', (array)$s['interfaces']));
                    foreach ($ifs as $i) { $checkIface($i, $where); }
                    $proto = strtolower((string)$s['protocol']);
                    if (!in_array($proto, ['any', 'tcp', 'udp', 'tcp/udp', 'icmp'], true)) {
                        $errors[] = "{$where}: protocol '{$proto}' is not supported";
                    }
                    if (!in_array($s['action'], ['pass', 'block', 'reject'], true)) {
                        $errors[] = "{$where}: action '{$s['action']}' is invalid";
                    }
                    foreach (['source', 'destination'] as $ep) { $checkEndpoint((string)$s[$ep], "{$where} {$ep}"); }
                    foreach (['source_port', 'destination_port'] as $pp) {
                        $checkPortRef((string)$s[$pp], "{$where} {$pp}");
                        if ((string)$s[$pp] !== '' && !in_array($proto, ['tcp', 'udp', 'tcp/udp'], true)) {
                            $errors[] = "{$where}: {$pp} needs protocol tcp, udp or tcp/udp";
                        }
                    }
                    $floating = (bool)$s['floating'] || count($ifs) > 1;
                    if ($s['direction'] === 'any' && !$floating) {
                        $errors[] = "{$where}: direction any is only valid on a floating rule";
                    }
                    $objects[] = ['kind' => 'rule', 'key' => $c['key'], 'origin' => $c['key'], 'action' => $s['action'],
                                  'interfaces' => $ifs, 'direction' => (string)$s['direction'],
                                  'ipprotocol' => (string)$s['ipprotocol'], 'protocol' => $proto,
                                  'source' => (string)$s['source'], 'source_port' => (string)$s['source_port'],
                                  'destination' => (string)$s['destination'], 'destination_port' => (string)$s['destination_port'],
                                  'log' => (bool)$s['log'], 'floating' => $floating, 'position' => (string)$s['position'],
                                  'description' => (string)$s['description']];
                    break;

                case 'management_access':
                    $iface = (string)$s['interface'];
                    $checkIface($iface, $where);
                    $src = [];
                    foreach ((array)$s['sources'] as $v) {
                        $v = (string)$v;
                        if (!policy_valid_ip($v) && !policy_valid_cidr($v)) { $errors[] = "{$where}: source '{$v}' is invalid"; }
                        $src[] = $v;
                    }
                    $ports = [];
                    foreach ((array)$s['ports'] as $p) {
                        if (!policy_valid_port((string)$p)) { $errors[] = "{$where}: port '{$p}' is invalid"; }
                        $ports[] = str_replace('-', ':', (string)$p);
                    }
                    $aliasName = policy_mgmt_alias_name($s);
                    $portAlias = substr($aliasName, 0, 25) . '_PORTS';
                    $desc = (string)$s['description'];
                    $objects[] = ['kind' => 'alias', 'key' => $c['key'] . '__src', 'origin' => $c['key'], 'name' => $aliasName,
                                  'alias_type' => 'network', 'content' => $src, 'description' => $desc . ' (sources)'];
                    $objects[] = ['kind' => 'alias', 'key' => $c['key'] . '__ports', 'origin' => $c['key'], 'name' => $portAlias,
                                  'alias_type' => 'port', 'content' => $ports, 'description' => $desc . ' (ports)'];
                    $objects[] = ['kind' => 'rule', 'key' => $c['key'] . '__allow', 'origin' => $c['key'], 'action' => 'pass',
                                  'interfaces' => [$iface], 'direction' => 'in', 'ipprotocol' => 'inet', 'protocol' => 'tcp',
                                  'source' => $aliasName, 'source_port' => '', 'destination' => 'self',
                                  'destination_port' => $portAlias, 'log' => false, 'floating' => false,
                                  'position' => 'top', 'description' => $desc . ': allow', 'mgmt_sources' => $src];
                    if (!empty($s['block_others'])) {
                        $objects[] = ['kind' => 'rule', 'key' => $c['key'] . '__block', 'origin' => $c['key'], 'action' => 'block',
                                      'interfaces' => [$iface], 'direction' => 'in', 'ipprotocol' => 'inet', 'protocol' => 'tcp',
                                      'source' => 'any', 'source_port' => '', 'destination' => 'self',
                                      'destination_port' => $portAlias, 'log' => true, 'floating' => false,
                                      'position' => 'top', 'description' => $desc . ': block others',
                                      'mgmt_sources' => $src];
                    }
                    break;

                case 'dns_servers':
                    $list = array_values(array_map('strval', (array)$s['servers']));
                    foreach ($list as $v) { if (!policy_valid_ip($v)) { $errors[] = "{$where}: '{$v}' is not an IP address"; } }
                    $objects[] = ['kind' => 'dns', 'key' => $c['key'], 'origin' => $c['key'], 'servers' => $list];
                    break;

                case 'ntp_servers':
                    $list = array_values(array_map('strval', (array)$s['servers']));
                    foreach ($list as $v) {
                        if (!policy_valid_ip($v) && !policy_valid_hostname($v)) { $errors[] = "{$where}: '{$v}' is not a host"; }
                    }
                    $objects[] = ['kind' => 'ntp', 'key' => $c['key'], 'origin' => $c['key'], 'servers' => $list];
                    break;

                case 'remote_syslog':
                    $host = (string)$s['host'];
                    if (!policy_valid_ip($host) && !policy_valid_hostname($host)) { $errors[] = "{$where}: '{$host}' is not a host"; }
                    if (!policy_valid_port((string)$s['port']) || str_contains((string)$s['port'], '-')) {
                        $errors[] = "{$where}: '{$s['port']}' is not a port";
                    }
                    $objects[] = ['kind' => 'syslog', 'key' => $c['key'], 'origin' => $c['key'], 'host' => $host,
                                  'port' => (string)$s['port'], 'transport' => (string)$s['transport']];
                    break;
            }
        }
        return ['objects' => $objects, 'errors' => $errors];
    }
}

// ---------------------------------------------------------------------------
// The planner
// ---------------------------------------------------------------------------

if (!function_exists('policy_plan')) {
    /**
     * Plan one template revision against one firewall's configuration.
     *
     * @param string $baseXml raw bytes of the base configuration
     * @param array  $ctx template_id, revision_id, content_hash, content, values
     *                    (resolved variables), family, exceptions (by key),
     *                    mappings (key => ref), manager_ips, tracked
     *                    (key => previously applied value, for slot settings)
     * @return array plan: ok, errors, blockers, warnings, changes, unsupported,
     *               skipped, managed, sync_items, base_fingerprint,
     *               artifact_sha256, plan_hash, xml (artifact; never persist)
     */
    function policy_plan(string $baseXml, array $ctx): array {
        $tpl = (int)$ctx['template_id'];
        $plan = ['ok' => false, 'errors' => [], 'blockers' => [], 'warnings' => [], 'changes' => [],
                 'unsupported' => [], 'skipped' => [], 'managed' => [], 'sync_items' => [],
                 'base_fingerprint' => hash('sha256', $baseXml), 'artifact_sha256' => null,
                 'plan_hash' => null, 'xml' => null, 'dialect' => []];

        $doc = policy_dom_load($baseXml);
        if ($doc === null) {
            $plan['errors'][] = 'The base configuration is not well-formed XML';
            return $plan;
        }
        $root = $doc->documentElement;
        $family = (string)($ctx['family'] ?? '');
        if (!in_array($root->nodeName, ['opnsense', 'pfsense'], true) || $root->nodeName !== $family) {
            $plan['errors'][] = sprintf('The base configuration is <%s> but the firewall is %s; never planned across products',
                                        substr($root->nodeName, 0, 32), $family ?: 'unverified');
            return $plan;
        }
        $dialect = policy_config_dialect($root);
        $plan['dialect'] = $dialect;

        $values = $ctx['values'] ?? [];
        $exceptions = $ctx['exceptions'] ?? [];
        $mappings = $ctx['mappings'] ?? [];
        $tracked = $ctx['tracked'] ?? [];

        // Aliases already in the configuration, for endpoint validation and
        // collision detection.
        $aliasInfo = policy_alias_nodes($root, $dialect['aliases']);
        $configAliases = [];
        foreach ($aliasInfo['nodes'] as $n) {
            $configAliases[strtolower((string)policy_dom_text($n, 'name'))] = $n;
        }

        if (isset($exceptions['*'])) {
            $plan['skipped'][] = ['key' => '*', 'reason' => 'Exception for the whole template: ' . $exceptions['*']['reason'],
                                  'owner' => $exceptions['*']['owner'], 'expires_at' => $exceptions['*']['expires_at']];
            $plan['ok'] = true;
            return policy_plan_finish($plan, $baseXml, null, $ctx);
        }

        $conc = policy_concrete_objects($ctx['content'], $values, policy_config_interfaces($root), $configAliases);
        if ($conc['errors']) {
            $plan['errors'] = array_merge($plan['errors'], $conc['errors']);
            return $plan;
        }

        $objects = [];
        foreach ($conc['objects'] as $o) {
            if (isset($exceptions[$o['origin']])) {
                $e = $exceptions[$o['origin']];
                $plan['skipped'][$o['origin']] = ['key' => $o['origin'], 'reason' => $e['reason'], 'owner' => $e['owner'],
                                                  'expires_at' => $e['expires_at']];
                continue;
            }
            $kindType = ['alias' => 'alias', 'rule' => 'filter_rule', 'dns' => 'dns_servers',
                         'ntp' => 'ntp_servers', 'syslog' => 'remote_syslog'][$o['kind']];
            $compat = policy_component_compat($kindType, $family, $dialect);
            if (!$compat['supported']) {
                $plan['unsupported'][] = ['key' => $o['key'], 'reason' => $compat['reason']];
                continue;
            }
            if ($o['kind'] === 'syslog' && $family === 'pfsense' && $o['transport'] !== 'udp4') {
                $plan['unsupported'][] = ['key' => $o['key'], 'reason' => 'pfSense remote syslog is UDP only'];
                continue;
            }
            $objects[] = $o;
        }
        $plan['skipped'] = array_values($plan['skipped']);
        foreach ($plan['unsupported'] as $u) {
            $plan['blockers'][] = "Component {$u['key']} is not supported on this firewall: {$u['reason']}";
        }

        $keep = array_flip(array_map(fn($o) => $o['key'], $objects));
        $excepted = [];
        foreach ($conc['objects'] as $o) {
            if (isset($exceptions[$o['origin']])) { $excepted[$o['key']] = true; }
        }

        // --- aliases ---------------------------------------------------------
        foreach ($objects as $o) {
            if ($o['kind'] === 'alias') {
                policy_plan_alias($doc, $root, $dialect['aliases'], $tpl, $o, $mappings, $configAliases, $plan);
            }
        }
        policy_plan_remove_orphans($root, 'alias', $dialect, $tpl, $keep, $excepted, $plan);

        // --- rules -----------------------------------------------------------
        $rules = array_values(array_filter($objects, fn($o) => $o['kind'] === 'rule'));
        if ($rules || policy_plan_has_managed($root, 'rule', $dialect, $tpl)) {
            policy_plan_rules($doc, $root, $dialect['rules'], $tpl, $rules, $mappings, $plan);
        }
        policy_plan_remove_orphans($root, 'rule', $dialect, $tpl, $keep, $excepted, $plan);

        // --- settings --------------------------------------------------------
        foreach ($objects as $o) {
            match ($o['kind']) {
                'dns'    => policy_plan_dns($root, $o, $plan),
                'ntp'    => policy_plan_ntp($root, $o, $plan),
                'syslog' => policy_plan_syslog($doc, $root, $family, $tpl, $o, $mappings, $tracked, $plan),
                default  => null,
            };
        }
        if ($family === 'opnsense' && $dialect['syslog'] === 'mvc') {
            policy_plan_remove_orphans($root, 'syslog', $dialect, $tpl, $keep, $excepted, $plan);
        }
        policy_plan_management_path($objects, $configAliases, $ctx['manager_ips'] ?? [], $plan);

        // HA: which sync items the changes touch.
        $catalog = policy_component_catalog();
        $origins = [];
        foreach ($conc['objects'] as $o) { $origins[$o['key']] = $o['origin']; }
        $types = [];
        foreach ($ctx['content']['components'] as $c) { $types[$c['key']] = $c['type']; }
        foreach ($plan['changes'] as $ch) {
            $type = $types[$origins[$ch['key']] ?? $ch['key']] ?? ($ch['kind'] === 'rule' ? 'filter_rule' : $ch['kind']);
            foreach ($catalog[$type]['sync'] ?? [] as $si) { $plan['sync_items'][$si] = true; }
        }
        $plan['sync_items'] = array_keys($plan['sync_items']);

        $plan['ok'] = !$plan['errors'];
        $changed = (bool)array_filter($plan['changes'], fn($c) => $c['op'] !== 'unchanged');
        return policy_plan_finish($plan, $baseXml, $changed ? $doc : null, $ctx);
    }
}

if (!function_exists('policy_plan_finish')) {
    function policy_plan_finish(array $plan, string $baseXml, ?DOMDocument $doc, array $ctx): array {
        $plan['changes'] = array_values(array_filter($plan['changes'], fn($c) => $c['op'] !== 'unchanged'));
        $plan['change_count'] = count($plan['changes']);
        if ($doc !== null) {
            $xml = $doc->saveXML();
            // Keep the declaration the device wrote, if any, rather than libxml's.
            if (preg_match('/^<\?xml[^>]*\?>/', $baseXml, $decl) && str_starts_with($xml, '<?xml')) {
                $xml = $decl[0] . substr($xml, strpos($xml, '?>') + 2);
            }
            $plan['xml'] = $xml;
            $plan['artifact_sha256'] = hash('sha256', $xml);
        } else {
            $plan['xml'] = null;
            $plan['artifact_sha256'] = $plan['change_count'] ? null : $plan['base_fingerprint'];
        }
        $plan['plan_hash'] = hash('sha256', policy_canonical_json([
            'base' => $plan['base_fingerprint'], 'revision' => (int)($ctx['revision_id'] ?? 0),
            'content_hash' => (string)($ctx['content_hash'] ?? ''), 'changes' => policy_redact($plan['changes']),
            'artifact' => $plan['artifact_sha256'], 'skipped' => array_column($plan['skipped'], 'key'),
        ]));
        return $plan;
    }
}

if (!function_exists('policy_plan_public')) {
    /** What may be stored, shown or audited: no artifact, everything redacted. */
    function policy_plan_public(array $plan): array {
        unset($plan['xml']);
        return policy_redact($plan);
    }
}

if (!function_exists('policy_find_managed')) {
    /** The node carrying the marker for tpl/key among $nodes. */
    function policy_find_managed(array $nodes, string $descrField, int $tpl, string $key): ?DOMElement {
        foreach ($nodes as $n) {
            $m = policy_parse_marker(policy_dom_text($n, $descrField));
            if ($m && $m[0] === $tpl && $m[1] === $key) {
                return $n;
            }
        }
        return null;
    }
}

if (!function_exists('policy_with_marker')) {
    function policy_with_marker(string $descr, int $tpl, string $key, bool $bottom = false): string {
        $descr = trim(preg_replace(POLICY_MARKER_RE, '', $descr));
        return trim($descr . ' ' . policy_marker($tpl, $key, $bottom));
    }
}

if (!function_exists('policy_apply_fields')) {
    /**
     * Set fields on an existing node; returns [field => [from, to]] for what
     * changed. $flags are presence flags (legacy dialect).
     */
    function policy_apply_fields(DOMElement $n, array $fields, array $flags = []): array {
        $diff = [];
        foreach ($fields as $f => $v) {
            $before = policy_dom_text($n, $f);
            if (policy_dom_set($n, $f, (string)$v)) {
                $diff[$f] = [$before, (string)$v];
            }
        }
        foreach ($flags as $f => $on) {
            $before = policy_dom_child($n, $f) !== null;
            if (policy_dom_set_flag($n, $f, $on)) {
                $diff[$f] = [$before ? 'set' : 'unset', $on ? 'set' : 'unset'];
            }
        }
        return $diff;
    }
}

// --- aliases ----------------------------------------------------------------

if (!function_exists('policy_plan_alias')) {
    function policy_plan_alias(DOMDocument $doc, DOMElement $root, string $dialect, int $tpl, array $o,
                               array $mappings, array $configAliases, array &$plan): void {
        $info = policy_alias_nodes($root, $dialect);
        $descr = policy_with_marker($o['description'], $tpl, $o['key']);
        $content = $dialect === 'mvc' ? implode("\n", $o['content']) : implode(' ', $o['content']);
        $fields = $dialect === 'mvc'
            ? ['enabled' => '1', 'name' => $o['name'], 'type' => $o['alias_type'], 'content' => $content, 'description' => $descr]
            : ['name' => $o['name'], 'type' => $o['alias_type'], 'address' => $content, 'descr' => $descr];
        $summary = ['name' => $o['name'], 'type' => $o['alias_type'], 'content' => $o['content']];
        $plan['managed'][] = ['kind' => 'alias', 'key' => $o['key'], 'name' => $o['name']];

        $node = policy_find_managed($info['nodes'], $info['descr'], $tpl, $o['key']);
        $op = 'modify';
        if ($node === null && isset($mappings[$o['key']])) {
            $node = $configAliases[strtolower($mappings[$o['key']])] ?? null;
            if ($node === null) {
                $plan['blockers'][] = "Alias {$o['name']}: the mapped object '{$mappings[$o['key']]}' no longer exists";
                return;
            }
            if (policy_parse_marker(policy_dom_text($node, $info['descr']))) {
                $plan['blockers'][] = "Alias {$o['name']}: the mapped object is already managed by a template";
                return;
            }
            $op = 'adopt';
        }
        if ($node !== null) {
            $other = $configAliases[strtolower($o['name'])] ?? null;
            if ($other !== null && !$other->isSameNode($node)) {
                $plan['blockers'][] = "Alias {$o['name']}: another alias already uses that name";
                return;
            }
            $diff = policy_apply_fields($node, $fields);
            $plan['changes'][] = ['op' => $diff ? $op : 'unchanged', 'kind' => 'alias', 'key' => $o['key'],
                                  'identity' => $o['name'], 'fields' => $diff, 'object' => $summary];
            return;
        }
        if (isset($configAliases[strtolower($o['name'])])) {
            $plan['blockers'][] = "Alias {$o['name']} already exists and is not managed by this template. "
                . 'It is not adopted automatically: map it explicitly or rename the template alias.';
            return;
        }
        $container = $info['container'] ?? ($dialect === 'mvc'
            ? policy_dom_ensure($root, 'OPNsense/Firewall/Alias/aliases') : policy_dom_ensure($root, 'aliases'));
        [$indent, $unit] = policy_dom_child_indent($container);
        if ($dialect === 'mvc') {
            $new = policy_dom_build($doc, 'alias', [
                'enabled' => '1', 'name' => $o['name'], 'type' => $o['alias_type'], 'proto' => '', 'interface' => '',
                'counters' => '0', 'updatefreq' => '', 'content' => $content, 'categories' => '', 'description' => $descr,
            ], ['uuid' => policy_uuid($tpl, $o['key'])], $indent, $unit);
        } else {
            $new = policy_dom_build($doc, 'alias', $fields, [], $indent, $unit);
        }
        policy_dom_insert($container, $new);
        $plan['changes'][] = ['op' => 'create', 'kind' => 'alias', 'key' => $o['key'], 'identity' => $o['name'],
                              'fields' => [], 'object' => $summary];
    }
}

// --- rules ------------------------------------------------------------------

if (!function_exists('policy_rule_fields')) {
    /** [fields, flags, newNodeFields] for a rule in a dialect. */
    function policy_rule_fields(array $o, string $dialect, int $tpl, int $sequence): array {
        $descr = policy_with_marker($o['description'], $tpl, $o['key'], $o['position'] === 'bottom');
        if ($dialect === 'mvc') {
            $net = fn(string $v) => $v === 'self' ? '(self)' : (str_starts_with($v, 'net:') ? substr($v, 4) : $v);
            $proto = ['any' => 'any', 'tcp' => 'TCP', 'udp' => 'UDP', 'tcp/udp' => 'TCP/UDP', 'icmp' => 'ICMP'][$o['protocol']];
            $f = [
                'enabled' => '1', 'sequence' => (string)$sequence, 'action' => $o['action'], 'quick' => '1',
                'interface' => implode(',', $o['interfaces']), 'direction' => $o['direction'],
                'ipprotocol' => $o['ipprotocol'], 'protocol' => $proto,
                'source_net' => $net($o['source']), 'source_not' => '0', 'source_port' => $o['source_port'],
                'destination_net' => $net($o['destination']), 'destination_not' => '0',
                'destination_port' => $o['destination_port'], 'log' => $o['log'] ? '1' : '0', 'description' => $descr,
            ];
            $new = ['enabled' => '1', 'statetype' => 'keep', 'sequence' => (string)$sequence, 'action' => $o['action'],
                    'quick' => '1', 'interfacenot' => '0', 'interface' => $f['interface'], 'direction' => $o['direction'],
                    'ipprotocol' => $o['ipprotocol'], 'protocol' => $proto, 'source_net' => $f['source_net'],
                    'source_not' => '0', 'source_port' => $o['source_port'], 'destination_net' => $f['destination_net'],
                    'destination_not' => '0', 'destination_port' => $o['destination_port'], 'gateway' => '',
                    'log' => $f['log'], 'categories' => '', 'description' => $descr];
            return [$f, [], $new];
        }
        $ep = function (string $v, string $port) {
            $e = match (true) {
                $v === 'any'                => ['any' => ''],
                $v === 'self'               => ['network' => '(self)'],
                str_starts_with($v, 'net:') => ['network' => substr($v, 4)],
                default                     => ['address' => $v],
            };
            if ($port !== '') { $e['port'] = $port; }
            return $e;
        };
        $f = ['type' => $o['action'], 'interface' => implode(',', $o['interfaces']), 'ipprotocol' => $o['ipprotocol'],
              'descr' => $descr];
        if ($o['protocol'] !== 'any') { $f['protocol'] = $o['protocol']; }
        if ($o['floating']) { $f['floating'] = 'yes'; $f['quick'] = 'yes'; $f['direction'] = $o['direction']; }
        $new = ['tracker' => policy_tracker($tpl, $o['key'])] + $f
             + ['source' => $ep($o['source'], $o['source_port']), 'destination' => $ep($o['destination'], $o['destination_port'])];
        if ($o['log']) { $new['log'] = ''; }
        return [$f, ['log' => $o['log']], $new];
    }
}

if (!function_exists('policy_legacy_endpoint_set')) {
    /** Rewrite a legacy <source>/<destination> block; returns [from, to] or null. */
    function policy_legacy_endpoint_set(DOMElement $rule, string $which, array $want): ?array {
        $cur = policy_dom_child($rule, $which);
        $have = [];
        if ($cur) {
            foreach (policy_dom_children($cur) as $c) { $have[$c->nodeName] = $c->nodeName === 'any' ? '' : $c->textContent; }
        }
        ksort($have);
        $w = $want;
        ksort($w);
        if ($have === $w) {
            return null;
        }
        $doc = $rule->ownerDocument;
        $indent = $cur ? (policy_dom_indent($cur) ?? '') : policy_dom_child_indent($rule)[0];
        [, $unit] = policy_dom_child_indent($rule);
        $new = policy_dom_build($doc, $which, $want, [], $indent, $unit);
        if ($cur) {
            $rule->replaceChild($new, $cur);
        } else {
            policy_dom_insert($rule, $new);
        }
        $fmt = fn($a) => implode(' ', array_map(fn($k, $v) => $v === '' ? $k : "{$k}={$v}", array_keys($a), $a));
        return [$fmt($have), $fmt($w)];
    }
}

if (!function_exists('policy_plan_rules')) {
    /**
     * Create / modify a template's rules and place them as one block per
     * position. Only this template's nodes are ever moved.
     */
    function policy_plan_rules(DOMDocument $doc, DOMElement $root, string $dialect, int $tpl, array $rules,
                               array $mappings, array &$plan): void {
        $info = policy_rule_nodes($root, $dialect);
        $container = $info['container'] ?? ($dialect === 'mvc'
            ? policy_dom_ensure($root, 'OPNsense/Firewall/Filter/rules') : policy_dom_ensure($root, 'filter'));
        $descrF = $info['descr'];
        $before = array_map(fn($n) => spl_object_id($n), policy_dom_children($container, 'rule'));

        $byPos = ['top' => [], 'bottom' => []];
        foreach ($rules as $o) { $byPos[$o['position'] === 'bottom' ? 'bottom' : 'top'][] = $o; }

        $placed = ['top' => [], 'bottom' => []];
        foreach (['top', 'bottom'] as $pos) {
            foreach ($byPos[$pos] as $i => $o) {
                $seq = $pos === 'top' ? $i + 1 : 99000 + $i;
                [$fields, $flags, $newFields] = policy_rule_fields($o, $dialect, $tpl, $seq);
                $plan['managed'][] = ['kind' => 'rule', 'key' => $o['key'], 'position' => $pos];
                $summary = ['action' => $o['action'], 'interfaces' => $o['interfaces'], 'protocol' => $o['protocol'],
                            'source' => $o['source'], 'source_port' => $o['source_port'],
                            'destination' => $o['destination'], 'destination_port' => $o['destination_port'],
                            'direction' => $o['direction'], 'floating' => $o['floating'], 'log' => $o['log'],
                            'position' => $pos, 'order' => $i];
                $node = policy_find_managed(policy_dom_children($container, 'rule'), $descrF, $tpl, $o['key']);
                $op = 'modify';
                if ($node === null && isset($mappings[$o['key']])) {
                    foreach (policy_dom_children($container, 'rule') as $n) {
                        $ref = $n->getAttribute('uuid') ?: (string)policy_dom_text($n, 'tracker');
                        if ($ref !== '' && $ref === $mappings[$o['key']]) { $node = $n; }
                    }
                    if ($node === null || policy_parse_marker(policy_dom_text($node, $descrF))) {
                        $plan['blockers'][] = "Rule {$o['key']}: the mapped rule '{$mappings[$o['key']]}' is missing or already managed";
                        continue;
                    }
                    $op = 'adopt';
                }
                if ($node !== null) {
                    $diff = policy_apply_fields($node, $fields, $flags);
                    if ($dialect === 'legacy') {
                        foreach (['source', 'destination'] as $w) {
                            if ($d = policy_legacy_endpoint_set($node, $w, $newFields[$w])) { $diff[$w] = $d; }
                        }
                        foreach (['floating', 'quick', 'direction', 'protocol'] as $opt) {
                            if (!isset($fields[$opt]) && ($c = policy_dom_child($node, $opt))) {
                                $diff[$opt] = [$c->textContent, null];
                                policy_dom_remove($c);
                            }
                        }
                        if (policy_dom_child($node, 'disabled')) {
                            $diff['disabled'] = ['set', 'unset'];
                            policy_dom_remove(policy_dom_child($node, 'disabled'));
                        }
                    }
                    $plan['changes'][] = ['op' => $diff ? $op : 'unchanged', 'kind' => 'rule', 'key' => $o['key'],
                                          'identity' => $o['description'] ?: $o['key'], 'fields' => $diff, 'object' => $summary];
                } else {
                    [$indent, $unit] = policy_dom_child_indent($container);
                    $attrs = $dialect === 'mvc' ? ['uuid' => policy_uuid($tpl, $o['key'])] : [];
                    $node = policy_dom_build($doc, 'rule', $newFields, $attrs, $indent, $unit);
                    policy_dom_insert($container, $node);
                    $plan['changes'][] = ['op' => 'create', 'kind' => 'rule', 'key' => $o['key'],
                                          'identity' => $o['description'] ?: $o['key'], 'fields' => [], 'object' => $summary];
                }
                $placed[$pos][] = $node;
            }
        }

        // Placement: detach this template's placed nodes, then insert the top
        // block after leading blocks of lower-id templates and the bottom
        // block before trailing blocks of higher-id templates.
        foreach (array_merge($placed['top'], $placed['bottom']) as $n) {
            if ($n->parentNode) { policy_dom_remove($n); }
        }
        $marker = fn(DOMElement $n) => policy_parse_marker(policy_dom_text($n, $descrF));
        $topRef = null;
        foreach (policy_dom_children($container, 'rule') as $n) {
            $m = $marker($n);
            if ($m && $m[0] < $tpl && !$m[2]) { continue; }     // lower-id template's top block
            $topRef = $n;
            break;
        }
        foreach ($placed['top'] as $n) {
            policy_dom_insert($container, $n, $topRef);
        }
        $all = policy_dom_children($container, 'rule');
        $bottomRef = null;
        for ($i = count($all) - 1; $i >= 0; $i--) {
            $m = $marker($all[$i]);
            if ($m && $m[0] > $tpl && $m[2]) { $bottomRef = $all[$i]; continue; }   // higher-id template's bottom block
            break;
        }
        foreach ($placed['bottom'] as $n) {
            policy_dom_insert($container, $n, $bottomRef);
        }

        // Did the order of pre-existing rules change?
        $existing = array_flip($before);
        $after = array_values(array_filter(array_map(fn($n) => spl_object_id($n), policy_dom_children($container, 'rule')),
                                           fn($id) => isset($existing[$id])));
        if ($after !== $before) {
            $plan['changes'][] = ['op' => 'reorder', 'kind' => 'rule', 'key' => '*', 'identity' => 'rule order',
                                  'fields' => [], 'object' => ['section' => $dialect]];
        }

        if ($dialect === 'mvc' && $placed['top']) {
            $ties = 0;
            foreach (policy_dom_children($container, 'rule') as $n) {
                if (!policy_parse_marker(policy_dom_text($n, $descrF)) && (int)policy_dom_text($n, 'sequence') <= count($placed['top'])) {
                    $ties++;
                }
            }
            if ($ties) {
                $plan['warnings'][] = sprintf('%d unmanaged MVC rule(s) use sequence numbers inside the managed top band (1-%d); '
                    . 'order between equal sequence numbers is decided by OPNsense and has not been verified on a live device.',
                    $ties, count($placed['top']));
            }
        }
    }
}

if (!function_exists('policy_plan_has_managed')) {
    function policy_plan_has_managed(DOMElement $root, string $kind, array $dialect, int $tpl): bool {
        $info = $kind === 'rule' ? policy_rule_nodes($root, $dialect['rules']) : policy_alias_nodes($root, $dialect['aliases']);
        foreach ($info['nodes'] as $n) {
            $m = policy_parse_marker(policy_dom_text($n, $info['descr']));
            if ($m && $m[0] === $tpl) { return true; }
        }
        return false;
    }
}

if (!function_exists('policy_plan_remove_orphans')) {
    /**
     * Remove this template's managed objects that the revision no longer
     * contains. Objects under an exception are left alone.
     */
    function policy_plan_remove_orphans(DOMElement $root, string $kind, array $dialect, int $tpl, array $keep,
                                        array $excepted, array &$plan): void {
        if ($kind === 'syslog') {
            $c = policy_dom_path($root, 'OPNsense/Syslog/destinations');
            $info = ['nodes' => $c ? policy_dom_children($c, 'destination') : [], 'descr' => 'description'];
        } else {
            $info = $kind === 'rule' ? policy_rule_nodes($root, $dialect['rules']) : policy_alias_nodes($root, $dialect['aliases']);
        }
        foreach ($info['nodes'] as $n) {
            $m = policy_parse_marker(policy_dom_text($n, $info['descr']));
            if (!$m || $m[0] !== $tpl || isset($keep[$m[1]])) {
                continue;
            }
            if (isset($excepted[$m[1]])) {
                $plan['warnings'][] = "Managed {$kind} {$m[1]} is under an exception and is left as it is";
                continue;
            }
            $identity = (string)(policy_dom_text($n, 'name') ?? $m[1]);
            policy_dom_remove($n);
            $plan['changes'][] = ['op' => 'delete', 'kind' => $kind, 'key' => $m[1], 'identity' => $identity,
                                  'fields' => [], 'object' => []];
        }
    }
}

// --- settings ---------------------------------------------------------------

if (!function_exists('policy_plan_dns')) {
    function policy_plan_dns(DOMElement $root, array $o, array &$plan): void {
        $sys = policy_dom_ensure($root, 'system');
        $nodes = policy_dom_children($sys, 'dnsserver');
        $cur = array_values(array_filter(array_map(fn($n) => trim($n->textContent), $nodes), fn($v) => $v !== ''));
        $plan['managed'][] = ['kind' => 'dns', 'key' => $o['key'], 'value' => $o['servers']];
        if ($cur === $o['servers'] && count($nodes) === count($cur)) {
            return;
        }
        $ref = null;
        if ($nodes) {
            $last = end($nodes);
            $ref = $last->nextSibling;
            while ($ref && !($ref instanceof DOMElement)) { $ref = $ref->nextSibling; }
            foreach ($nodes as $n) { policy_dom_remove($n); }
        }
        foreach ($o['servers'] as $s) {
            $n = $root->ownerDocument->createElement('dnsserver');
            $n->appendChild($root->ownerDocument->createTextNode($s));
            policy_dom_insert($sys, $n, $ref);
        }
        if ($root->nodeName === 'pfsense') {
            foreach (policy_dom_children($sys) as $c) {
                if (preg_match('/^dns\dgw$/', $c->nodeName) && !in_array(trim($c->textContent), ['', 'none'], true)) {
                    $plan['warnings'][] = "pfSense {$c->nodeName} binds a DNS server to a gateway; it is not managed and may now refer to a different server";
                }
            }
        }
        $plan['changes'][] = ['op' => 'modify', 'kind' => 'dns', 'key' => $o['key'], 'identity' => 'system/dnsserver',
                              'fields' => ['servers' => [implode(' ', $cur), implode(' ', $o['servers'])]],
                              'object' => ['servers' => $o['servers']]];
    }
}

if (!function_exists('policy_plan_ntp')) {
    function policy_plan_ntp(DOMElement $root, array $o, array &$plan): void {
        $sys = policy_dom_ensure($root, 'system');
        $want = implode(' ', $o['servers']);
        $before = policy_dom_text($sys, 'timeservers');
        $plan['managed'][] = ['kind' => 'ntp', 'key' => $o['key'], 'value' => $o['servers']];
        if (trim((string)$before) === $want) {
            return;
        }
        policy_dom_set($sys, 'timeservers', $want);
        $plan['changes'][] = ['op' => 'modify', 'kind' => 'ntp', 'key' => $o['key'], 'identity' => 'system/timeservers',
                              'fields' => ['servers' => [$before, $want]], 'object' => ['servers' => $o['servers']]];
    }
}

if (!function_exists('policy_plan_syslog')) {
    function policy_plan_syslog(DOMDocument $doc, DOMElement $root, string $family, int $tpl, array $o,
                                array $mappings, array $tracked, array &$plan): void {
        $summary = ['host' => $o['host'], 'port' => $o['port'], 'transport' => $o['transport']];
        if ($family === 'pfsense') {
            $sys = policy_dom_ensure($root, 'syslog');
            $want = $o['host'] . ':' . $o['port'];
            $slots = ['remoteserver', 'remoteserver2', 'remoteserver3'];
            $prev = $tracked[$o['key']] ?? null;
            $plan['managed'][] = ['kind' => 'syslog', 'key' => $o['key'], 'value' => $want];
            $target = null;
            foreach ($slots as $s) {
                if (trim((string)policy_dom_text($sys, $s)) === $want) {
                    if (policy_dom_set_flag($sys, 'enable', true)) {
                        $plan['changes'][] = ['op' => 'modify', 'kind' => 'syslog', 'key' => $o['key'],
                                              'identity' => 'syslog/enable', 'fields' => ['enable' => ['unset', 'set']],
                                              'object' => $summary];
                    }
                    return;
                }
                if ($prev !== null && trim((string)policy_dom_text($sys, $s)) === $prev) { $target = $s; }
            }
            $op = 'modify';
            if ($target === null) {
                foreach ($slots as $s) {
                    if (trim((string)policy_dom_text($sys, $s)) === '') { $target = $s; $op = 'create'; break; }
                }
            }
            if ($target === null) {
                $plan['blockers'][] = 'All three pfSense remote syslog slots are used by settings this template does not manage';
                return;
            }
            $before = policy_dom_text($sys, $target);
            policy_dom_set($sys, $target, $want);
            policy_dom_set_flag($sys, 'enable', true);
            $plan['warnings'][] = 'pfSense remote syslog categories (logall, filter, ...) are not managed; review them on the firewall';
            $plan['changes'][] = ['op' => $op, 'kind' => 'syslog', 'key' => $o['key'], 'identity' => "syslog/{$target}",
                                  'fields' => [$target => [$before, $want]], 'object' => $summary];
            return;
        }

        $dest = policy_dom_ensure($root, 'OPNsense/Syslog/destinations');
        $nodes = policy_dom_children($dest, 'destination');
        $descr = policy_with_marker('Remote syslog', $tpl, $o['key']);
        $fields = ['enabled' => '1', 'transport' => $o['transport'], 'hostname' => $o['host'], 'port' => $o['port'],
                   'description' => $descr];
        $plan['managed'][] = ['kind' => 'syslog', 'key' => $o['key'], 'value' => $o['host'] . ':' . $o['port']];
        $node = policy_find_managed($nodes, 'description', $tpl, $o['key']);
        $op = 'modify';
        if ($node === null && isset($mappings[$o['key']])) {
            foreach ($nodes as $n) { if ($n->getAttribute('uuid') === $mappings[$o['key']]) { $node = $n; } }
            if ($node === null || policy_parse_marker(policy_dom_text($node, 'description'))) {
                $plan['blockers'][] = "Syslog {$o['key']}: the mapped destination is missing or already managed";
                return;
            }
            $op = 'adopt';
        }
        if ($node !== null) {
            $diff = policy_apply_fields($node, $fields);
            $plan['changes'][] = ['op' => $diff ? $op : 'unchanged', 'kind' => 'syslog', 'key' => $o['key'],
                                  'identity' => $o['host'], 'fields' => $diff, 'object' => $summary];
            return;
        }
        foreach ($nodes as $n) {
            if (policy_dom_text($n, 'hostname') === $o['host'] && policy_dom_text($n, 'port') === $o['port']) {
                $plan['blockers'][] = "An unmanaged syslog destination for {$o['host']}:{$o['port']} already exists; map it explicitly";
                return;
            }
        }
        [$indent, $unit] = policy_dom_child_indent($dest);
        $new = policy_dom_build($doc, 'destination', [
            'enabled' => '1', 'transport' => $o['transport'], 'program' => '', 'level' => '', 'facility' => '',
            'hostname' => $o['host'], 'certificate' => '', 'port' => $o['port'], 'rfc5424' => '0', 'description' => $descr,
        ], ['uuid' => policy_uuid($tpl, $o['key'])], $indent, $unit);
        policy_dom_insert($dest, $new);
        $plan['changes'][] = ['op' => 'create', 'kind' => 'syslog', 'key' => $o['key'], 'identity' => $o['host'],
                              'fields' => [], 'object' => $summary];
    }
}

// --- management path ----------------------------------------------------------

if (!function_exists('policy_plan_management_path')) {
    /**
     * Refuse plans that could cut the manager off.
     *
     * The manager reaches a firewall from known source addresses
     * (policy_manager_source_ips) for SSH, the GUI and tunnels, and the agent
     * reaches the manager outbound on 443. A managed block/reject rule that
     * could match either is a blocker. When the manager's addresses are not
     * configured nothing can be proven, so every block/reject rule is a blocker
     * until they are.
     */
    function policy_plan_management_path(array $objects, array $configAliases, array $managerIps, array &$plan): void {
        $tplAlias = [];
        foreach ($objects as $o) {
            if ($o['kind'] === 'alias') { $tplAlias[strtolower($o['name'])] = $o; }
        }
        $mgmtPorts = ['22', '80', '443', '8443'];
        $matches = function (string $ep, string $ip) use ($tplAlias, $configAliases): bool {
            if ($ep === 'any' || str_starts_with($ep, 'net:') || $ep === 'self') {
                return $ep !== 'self';
            }
            if (policy_valid_ip($ep) || policy_valid_cidr($ep)) {
                return policy_ip_in($ip, $ep);
            }
            $a = $tplAlias[strtolower($ep)] ?? null;
            if ($a !== null) {
                foreach ($a['content'] as $c) { if (policy_valid_ip($c) || policy_valid_cidr($c)) { if (policy_ip_in($ip, $c)) { return true; } } }
                return false;
            }
            return true;   // an alias we cannot see into could contain anything
        };
        $portsHit = function (string $port) use ($tplAlias, $mgmtPorts): bool {
            if ($port === '') { return true; }
            $list = isset($tplAlias[strtolower($port)]) ? $tplAlias[strtolower($port)]['content'] : [$port];
            foreach ($list as $p) {
                $p = str_replace(':', '-', $p);
                if (!policy_valid_port($p)) { return true; }
                [$lo, $hi] = str_contains($p, '-') ? array_map('intval', explode('-', $p)) : [(int)$p, (int)$p];
                foreach ($mgmtPorts as $m) { if ((int)$m >= $lo && (int)$m <= $hi) { return true; } }
            }
            return false;
        };

        foreach ($objects as $o) {
            if ($o['kind'] !== 'rule' || $o['action'] === 'pass') {
                continue;
            }
            if (!$managerIps) {
                $plan['blockers'][] = "Rule {$o['key']} blocks traffic, and the manager's source addresses are not configured "
                    . '(setting policy_manager_source_ips), so the plan cannot prove it keeps the management path open.';
                continue;
            }
            foreach ($managerIps as $ip) {
                $inbound = $matches($o['source'], $ip) && $portsHit($o['destination_port']);
                if ($inbound && isset($o['mgmt_sources'])) {
                    // A management_access block rule is preceded by its allow rule.
                    $covered = false;
                    foreach ($o['mgmt_sources'] as $src) { if (policy_ip_in($ip, $src)) { $covered = true; } }
                    $inbound = !$covered;
                }
                $outbound = $o['destination'] !== 'self' && $o['destination'] !== 'any' && $matches($o['destination'], $ip)
                            && ($o['destination_port'] === '' || $portsHit($o['destination_port']));
                if ($inbound || $outbound) {
                    $plan['blockers'][] = sprintf('Rule %s would block the management path %s the manager (%s). '
                        . 'Narrow the rule or add an explicit allow for the manager first.',
                        $o['key'], $inbound ? 'from' : 'to', $ip);
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Read-back verification
// ---------------------------------------------------------------------------

if (!function_exists('policy_verify_readback')) {
    /**
     * Whether a configuration read back from the firewall contains exactly the
     * planned managed objects: re-planning the same revision on it must yield
     * no changes, and the set of managed identities must match.
     *
     * @return array{ok:bool, reasons:string[]}
     */
    function policy_verify_readback(string $xml, array $ctx, array $expectedManaged): array {
        $p = policy_plan($xml, $ctx);
        $reasons = array_merge($p['errors'], array_map(fn($c) => "{$c['op']} {$c['kind']} {$c['key']}", $p['changes']));
        $want = array_map(fn($m) => $m['kind'] . ':' . $m['key'], $expectedManaged);
        $got = array_map(fn($m) => $m['kind'] . ':' . $m['key'], $p['managed']);
        sort($want);
        sort($got);
        if ($want !== $got) {
            $reasons[] = 'Managed objects differ from the plan';
        }
        return ['ok' => !$reasons, 'reasons' => $reasons];
    }
}
