<?php
/**
 * Policy templates: planning and rendering against the four fixtures.
 *
 * Proves:
 *   - re-planning a rendered result produces zero changes (all fixtures)
 *   - everything outside the managed nodes is canonically identical
 *   - a new revision modifies/deletes only managed objects, then converges
 *   - two templates' rule blocks are ordered deterministically and never
 *     reorder each other
 *   - unmanaged objects are never adopted without an explicit mapping
 *   - management-path, wrong-product and unsupported-component refusals
 *   - exceptions, interface-mapping validation, secret redaction
 *
 *   DB_NAME=opnsense_fw_test_policy php tests/policy_render_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/policy/policy.php';

$FIX = TEST_ROOT . '/tests/fixtures/configs';
$fixtures = [
    'opnsense-26.7-edge-sanitized.xml' => ['opnsense', 'opt4', 'mvc'],
    'opnsense-26.7-home-sanitized.xml' => ['opnsense', 'lan', 'mvc'],
    'pfsense-ce-2.7.2-synthetic.xml'   => ['pfsense', 'lan', 'legacy'],
    'pfsense-plus-25.07-synthetic.xml' => ['pfsense', 'lan', 'legacy'],
];

function rt_content(array $components, array $vars = null): array {
    $v = policy_validate_content(['variables' => $vars ?? [
        ['name' => 'mgmt_subnet', 'type' => 'cidr'], ['name' => 'dns', 'type' => 'list:ip'],
        ['name' => 'lan_if', 'type' => 'interface'], ['name' => 'ntp', 'type' => 'list:host'],
    ], 'components' => $components]);
    if (!$v['ok']) { throw new RuntimeException(implode('; ', $v['errors'])); }
    return $v['content'];
}

function rt_full(): array {
    return [
        ['key' => 'web', 'type' => 'alias', 'name' => 'OPNMGR_WEB', 'alias_type' => 'port', 'content' => ['80', '443', '8000-8100']],
        ['key' => 'nets', 'type' => 'alias', 'name' => 'OPNMGR_NETS', 'alias_type' => 'network', 'content' => ['{{mgmt_subnet}}', '10.9.9.9']],
        ['key' => 'allow_web', 'type' => 'filter_rule', 'action' => 'pass', 'interfaces' => ['{{lan_if}}'], 'protocol' => 'tcp',
         'source' => 'OPNMGR_NETS', 'destination' => 'any', 'destination_port' => 'OPNMGR_WEB', 'log' => true, 'description' => 'Allow web'],
        ['key' => 'block_tel', 'type' => 'filter_rule', 'action' => 'block', 'interfaces' => ['{{lan_if}}'], 'protocol' => 'tcp',
         'destination_port' => '23', 'position' => 'bottom', 'description' => 'No telnet'],
        ['key' => 'dns', 'type' => 'dns_servers', 'servers' => '{{dns}}'],
        ['key' => 'ntp', 'type' => 'ntp_servers', 'servers' => '{{ntp}}'],
        ['key' => 'mgmt', 'type' => 'management_access', 'interface' => '{{lan_if}}', 'sources' => ['{{mgmt_subnet}}'],
         'ports' => ['443', '22'], 'block_others' => true],
        ['key' => 'logs', 'type' => 'remote_syslog', 'host' => '10.1.2.3', 'port' => '514'],
    ];
}

function rt_ctx(int $tpl, array $content, string $family, string $lan, array $extra = []): array {
    return array_merge([
        'template_id' => $tpl, 'revision_id' => $tpl * 10, 'content_hash' => hash('sha256', policy_canonical_json($content)),
        'content' => $content, 'family' => $family, 'manager_ips' => ['10.50.0.5'], 'exceptions' => [], 'mappings' => [], 'tracked' => [],
        'values' => [
            'mgmt_subnet' => ['type' => 'cidr', 'value' => '10.50.0.0/24'],
            'dns' => ['type' => 'list:ip', 'value' => ['9.9.9.9', '1.1.1.1']],
            'lan_if' => ['type' => 'interface', 'value' => $lan],
            'ntp' => ['type' => 'list:host', 'value' => ['ntp1.example.net', '10.0.0.123']],
        ],
    ], $extra);
}

/**
 * Canonical XML of a configuration with every managed node (marker), the
 * managed settings and all inter-element whitespace removed. Equal output for
 * base and result proves nothing outside the managed nodes changed.
 */
function rt_unmanaged_c14n(string $xml): string {
    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = false;
    $doc->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE);
    $xp = new DOMXPath($doc);
    $kill = [];
    foreach ($xp->query('//*[self::description or self::descr][contains(., "[opnmgr:tpl=")]/..') as $n) { $kill[] = $n; }
    foreach (['/*/system/dnsserver', '/*/system/timeservers', '/pfsense/syslog/remoteserver', '/pfsense/syslog/remoteserver2',
              '/pfsense/syslog/remoteserver3', '/pfsense/syslog/enable'] as $q) {
        foreach ($xp->query($q) as $n) { $kill[] = $n; }
    }
    foreach ($kill as $n) { $n->parentNode->removeChild($n); }
    foreach ($xp->query('//text()[normalize-space(.) = ""]') as $t) { $t->parentNode->removeChild($t); }
    return $doc->documentElement->C14N();
}

/** Ordered identities of rules in the section a dialect writes. */
function rt_rule_order(string $xml, string $dialect): array {
    $doc = policy_dom_load($xml);
    $info = policy_rule_nodes($doc->documentElement, $dialect);
    return array_map(function ($n) use ($info) {
        $m = policy_parse_marker(policy_dom_text($n, $info['descr']));
        return $m ? "T{$m[0]}:{$m[1]}" : 'u:' . ($n->getAttribute('uuid') ?: policy_dom_text($n, 'tracker') ?: md5($n->C14N()));
    }, $info['nodes']);
}

$content = rt_content(rt_full());

foreach ($fixtures as $file => [$family, $lan, $dialect]) {
    $xml = file_get_contents("{$FIX}/{$file}");
    T::group("Plan & idempotency: {$file}");
    $ctx = rt_ctx(7, $content, $family, $lan);
    $p = policy_plan($xml, $ctx);
    T::ok($p['ok'] && !$p['errors'], 'plans without errors');
    T::eq([], $p['blockers'], 'no blockers');
    T::ok($p['change_count'] >= 10, "semantic changes listed ({$p['change_count']})");
    $ops = array_count_values(array_map(fn($c) => "{$c['op']}:{$c['kind']}", $p['changes']));
    T::eq(4, $ops['create:alias'] ?? 0, 'four aliases created (two template, two management)');
    T::eq(4, $ops['create:rule'] ?? 0, 'four rules created');
    T::eq($dialect, $p['dialect']['rules'], "rules are written to the {$dialect} section");
    T::ok($p['xml'] !== null && hash('sha256', $p['xml']) === $p['artifact_sha256'], 'artifact sha256 is of the rendered bytes');
    T::eq($p['plan_hash'], policy_plan($xml, $ctx)['plan_hash'], 'planning is deterministic (same plan hash twice)');
    T::eq($p['artifact_sha256'], policy_plan($xml, $ctx)['artifact_sha256'], 'and byte-identical artifacts');

    $p2 = policy_plan($p['xml'], $ctx);
    T::eq(0, $p2['change_count'], 'reapplying the same revision produces no changes');
    T::eq(null, $p2['xml'], 'and no artifact');
    T::eq(rt_unmanaged_c14n($xml), rt_unmanaged_c14n($p['xml']), 'everything outside managed nodes is canonically identical');

    $nb = platform_normalize_config($xml);
    $na = platform_normalize_config($p['xml']);
    foreach (['interfaces', 'nat', 'routes', 'gateways', 'vpn', 'ha'] as $sec) {
        T::eq($nb[$sec], $na[$sec], "normalized {$sec} unchanged");
    }
    $unmanagedRules = fn($m) => array_values(array_map(fn($r) => array_diff_key($r, ['order' => 1]),
        array_filter($m['filter_rules'], fn($r) => !policy_parse_marker($r['description']))));
    T::eq($unmanagedRules($nb), $unmanagedRules($na), 'unmanaged rules unchanged, in the same order');
    T::eq($nb['system']['webgui'], $na['system']['webgui'], 'the web GUI settings are never rewritten');

    $order = rt_rule_order($p['xml'], $dialect);
    $managedIdx = array_keys(array_filter($order, fn($o) => str_starts_with($o, 'T7:')));
    T::eq(['T7:allow_web', 'T7:mgmt__allow', 'T7:mgmt__block'], array_slice($order, 0, 3), 'top rules lead the section in template order');
    T::eq('T7:block_tel', end($order), 'the bottom rule ends the section');

    // A second revision: change content, drop a component, change a description.
    $c2 = rt_full();
    $c2[0]['content'] = ['443'];
    unset($c2[3]);
    $c2[2]['description'] = 'Allow web (v2)';
    $ctx2 = rt_ctx(7, rt_content(array_values($c2)), $family, $lan);
    $p3 = policy_plan($p['xml'], $ctx2);
    $ops3 = array_map(fn($c) => "{$c['op']}:{$c['key']}", $p3['changes']);
    sort($ops3);
    T::eq(['delete:block_tel', 'modify:allow_web', 'modify:web'], $ops3, 'revision 2: exactly the changed objects are modified / removed');
    T::eq(0, policy_plan($p3['xml'], $ctx2)['change_count'], 'revision 2 converges');
    T::eq(rt_unmanaged_c14n($xml), rt_unmanaged_c14n($p3['xml']), 'unrelated configuration still untouched');

    // Secrets
    $pub = json_encode(policy_plan_public($p));
    T::ok(!str_contains($pub, 'FIXTURE-') && !str_contains($pub, 'PRIVATE KEY') && !preg_match('/\$2[aby]\$/', $pub),
          'the plan contains no secrets from the configuration');
    T::ok(!str_contains($pub, '"xml"'), 'the stored plan never contains the artifact');
}

// ===========================================================================
T::group('Two templates: deterministic, non-interfering order');

foreach (['opnsense-26.7-home-sanitized.xml' => ['opnsense', 'lan', 'mvc'], 'pfsense-ce-2.7.2-synthetic.xml' => ['pfsense', 'lan', 'legacy']]
         as $file => [$family, $lan, $dialect]) {
    $xml = file_get_contents("{$FIX}/{$file}");
    $mk = fn(string $p) => rt_content([
        ['key' => "{$p}_top", 'type' => 'filter_rule', 'action' => 'pass', 'interfaces' => ['{{lan_if}}'], 'description' => "{$p} top"],
        ['key' => "{$p}_bot", 'type' => 'filter_rule', 'action' => 'pass', 'interfaces' => ['{{lan_if}}'], 'position' => 'bottom'],
    ]);
    $a = rt_ctx(5, $mk('a'), $family, $lan);
    $b = rt_ctx(9, $mk('b'), $family, $lan);
    $ab = policy_plan(policy_plan($xml, $a)['xml'], $b)['xml'];
    $ba = policy_plan(policy_plan($xml, $b)['xml'], $a)['xml'];
    T::eq(rt_rule_order($ab, $dialect), rt_rule_order($ba, $dialect), "[{$dialect}] the same order whichever template is applied first");
    $o = rt_rule_order($ab, $dialect);
    T::eq(['T5:a_top', 'T9:b_top'], array_slice($o, 0, 2), "[{$dialect}] top blocks by template id");
    T::eq(['T5:a_bot', 'T9:b_bot'], array_slice($o, -2), "[{$dialect}] bottom blocks by template id");
    T::eq(0, policy_plan($ab, $a)['change_count'], "[{$dialect}] re-planning template A does not reorder template B");
    T::eq(0, policy_plan($ab, $b)['change_count'], "[{$dialect}] re-planning template B does not reorder template A");
}

// ===========================================================================
T::group('Unmanaged objects are never adopted implicitly');

$xml = file_get_contents("{$FIX}/pfsense-ce-2.7.2-synthetic.xml");
$adopt = rt_content([['key' => 'web', 'type' => 'alias', 'name' => 'WEB_PORTS', 'alias_type' => 'port', 'content' => ['443']]]);
$p = policy_plan($xml, rt_ctx(7, $adopt, 'pfsense', 'lan'));
T::ok((bool)array_filter($p['blockers'], fn($b) => str_contains($b, 'WEB_PORTS') && str_contains($b, 'not adopted')),
      'a name collision with an unmanaged alias blocks the plan');
$p = policy_plan($xml, rt_ctx(7, $adopt, 'pfsense', 'lan', ['mappings' => ['web' => 'WEB_PORTS']]));
T::eq([], $p['blockers'], 'with an explicit mapping the plan is clean');
T::eq('adopt', $p['changes'][0]['op'] ?? null, 'and shows the adoption');
T::ok(isset($p['changes'][0]['fields']['address']), 'with the exact fields that change');
T::eq(0, policy_plan($p['xml'], rt_ctx(7, $adopt, 'pfsense', 'lan'))['change_count'], 'once adopted it is managed and converged');

// ===========================================================================
T::group('Refusals');

$home = file_get_contents("{$FIX}/opnsense-26.7-home-sanitized.xml");
$pf = file_get_contents("{$FIX}/pfsense-ce-2.7.2-synthetic.xml");
$p = policy_plan($pf, rt_ctx(7, $content, 'opnsense', 'lan'));
T::ok(!$p['ok'] && str_contains($p['errors'][0] ?? '', 'never planned across products'), 'a pfSense config is never planned as OPNsense');
$p = policy_plan($home, rt_ctx(7, $content, 'pfsense', 'lan'));
T::ok(!$p['ok'], 'and the reverse');

$p = policy_plan($home, rt_ctx(7, $content, 'opnsense', 'opt9'));
T::ok(!$p['ok'] && (bool)array_filter($p['errors'], fn($e) => str_contains($e, "'opt9' does not exist")), 'a missing interface mapping is rejected');

$bad = rt_content([['key' => 'r', 'type' => 'filter_rule', 'action' => 'pass', 'interfaces' => ['lan'], 'protocol' => 'icmp',
                    'destination_port' => '80']], []);
T::ok(!policy_plan($home, rt_ctx(7, $bad, 'opnsense', 'lan'))['ok'], 'a port on a non-TCP/UDP rule is rejected');
$bad = rt_content([['key' => 'r', 'type' => 'filter_rule', 'action' => 'pass', 'interfaces' => ['lan'], 'source' => '10.0.0.1/8']], []);
T::ok(!policy_plan($home, rt_ctx(7, $bad, 'opnsense', 'lan'))['ok'], 'a non-aligned CIDR endpoint is rejected');

$block22 = rt_content([['key' => 'no_ssh', 'type' => 'filter_rule', 'action' => 'block', 'interfaces' => ['lan'], 'protocol' => 'tcp',
                        'destination' => 'self', 'destination_port' => '22']], []);
$p = policy_plan($home, rt_ctx(7, $block22, 'opnsense', 'lan'));
T::ok((bool)array_filter($p['blockers'], fn($b) => str_contains($b, 'management path')), 'a rule blocking SSH from the manager is refused');
$p = policy_plan($home, rt_ctx(7, $block22, 'opnsense', 'lan', ['manager_ips' => []]));
T::ok((bool)array_filter($p['blockers'], fn($b) => str_contains($b, 'policy_manager_source_ips')),
      'with the manager address unknown, a block rule cannot be proven safe');
$mg = rt_content([['key' => 'mgmt', 'type' => 'management_access', 'interface' => 'lan', 'sources' => ['192.0.2.0/24'],
                   'ports' => ['443'], 'block_others' => true]], []);
T::ok((bool)policy_plan($home, rt_ctx(7, $mg, 'opnsense', 'lan'))['blockers'], 'management restriction that excludes the manager is refused');
$mg2 = rt_content([['key' => 'mgmt', 'type' => 'management_access', 'interface' => 'lan', 'sources' => ['10.50.0.0/24'],
                    'ports' => ['443'], 'block_others' => true]], []);
T::eq([], policy_plan($home, rt_ctx(7, $mg2, 'opnsense', 'lan'))['blockers'], 'one that includes the manager is accepted');
$out = rt_content([['key' => 'egress', 'type' => 'filter_rule', 'action' => 'block', 'interfaces' => ['lan'],
                    'destination' => '10.50.0.0/24']], []);
T::ok((bool)policy_plan($home, rt_ctx(7, $out, 'opnsense', 'lan'))['blockers'], 'a rule blocking traffic to the manager is refused');

// Unsupported components.
$doc = policy_dom_load($home);
$sys = policy_dom_path($doc->documentElement, 'OPNsense/Syslog');
$sys->parentNode->removeChild($sys);
$noSyslog = $doc->saveXML();
$sl = rt_content([['key' => 'logs', 'type' => 'remote_syslog', 'host' => '10.1.2.3']], []);
$p = policy_plan($noSyslog, rt_ctx(7, $sl, 'opnsense', 'lan'));
T::ok(count($p['unsupported']) === 1 && $p['blockers'], 'remote syslog without the MVC Syslog section is unsupported and blocks');
$tcp = rt_content([['key' => 'logs', 'type' => 'remote_syslog', 'host' => '10.1.2.3', 'transport' => 'tcp4']], []);
T::ok(count(policy_plan($pf, rt_ctx(7, $tcp, 'pfsense', 'lan'))['unsupported']) === 1, 'TCP syslog on pfSense is unsupported');
T::eq('syslog/remoteserver[1-3]', policy_component_compat('remote_syslog', 'pfsense')['target'], 'compatibility names the pfSense target');
T::ok(!policy_component_compat('alias', 'unknown')['supported'], 'nothing is supported on an unverified platform');

// pfSense syslog slots: the existing unmanaged slot is kept, the next one used.
$p = policy_plan($pf, rt_ctx(7, $sl, 'pfsense', 'lan'));
T::eq('syslog/remoteserver2', $p['changes'][0]['identity'] ?? null, 'pfSense syslog uses the first free slot, never the unmanaged one');
$moved = rt_content([['key' => 'logs', 'type' => 'remote_syslog', 'host' => '10.7.7.7']], []);
$p2 = policy_plan($p['xml'], rt_ctx(7, $moved, 'pfsense', 'lan', ['tracked' => ['logs' => '10.1.2.3:514']]));
T::eq('syslog/remoteserver2', $p2['changes'][0]['identity'] ?? null, 'a changed host updates the tracked slot');
T::eq('10.20.0.60:514', platform_normalize_config($p2['xml'])['logging']['remote_servers'][0], 'the unmanaged slot is untouched');

// ===========================================================================
T::group('Exceptions in plans');

$ex = ['web' => ['reason' => 'vendor', 'owner' => 'noc', 'expires_at' => null]];
$p = policy_plan($home, rt_ctx(7, $content, 'opnsense', 'lan', ['exceptions' => $ex]));
T::ok(!array_filter($p['changes'], fn($c) => $c['key'] === 'web'), 'an excepted object is not created');
T::eq('web', $p['skipped'][0]['key'] ?? null, 'and is listed as skipped with its reason');
$applied = policy_plan($home, rt_ctx(7, $content, 'opnsense', 'lan'))['xml'];
$p = policy_plan($applied, rt_ctx(7, $content, 'opnsense', 'lan', ['exceptions' => $ex]));
T::eq(0, $p['change_count'], 'an existing managed object under exception is left exactly as it is');
$p = policy_plan($home, rt_ctx(7, $content, 'opnsense', 'lan', ['exceptions' => ['*' => ['reason' => 'x', 'owner' => 'y', 'expires_at' => null]]]));
T::eq(0, $p['change_count'], 'a whole-template exception plans nothing');

// ===========================================================================
T::group('Redaction');

$r = policy_redact(['password' => 'p', 'nested' => ['pre-shared-key' => 'k', 'ok' => 'v'], 'plan_hash' => 'h',
                    'blob' => "-----BEGIN RSA PRIVATE KEY-----\nabc", 'hash' => '$2y$10$abcdefghijklmnopqrstuv']);
T::eq('[redacted]', $r['password'], 'password keys redacted');
T::eq('[redacted]', $r['nested']['pre-shared-key'], 'nested PSK keys redacted');
T::eq('v', $r['nested']['ok'], 'ordinary values kept');
T::eq('h', $r['plan_hash'], 'plan hashes kept');
T::eq('[redacted]', $r['blob'], 'PEM private keys redacted by shape');
T::eq('[redacted]', $r['hash'], 'bcrypt hashes redacted by shape');

exit(T::summary());
