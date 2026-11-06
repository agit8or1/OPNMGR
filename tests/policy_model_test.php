<?php
/**
 * Policy templates: model, lifecycle, variables, precedence, conflicts,
 * exceptions.
 *
 *   tests/lib/make_test_db.sh opnsense_fw_test_policy
 *   DB_NAME=opnsense_fw_test_policy php tests/policy_model_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/policy/policy.php';

if (strpos((string)db()->query('SELECT DATABASE()')->fetchColumn(), 'opnsense_fw_test') !== 0) {
    fwrite(STDERR, "Refusing: not connected to a test database\n");
    exit(2);
}

$_SESSION = ['user_id' => 1, 'username' => 'policy-test', 'role' => 'admin'];
$made = ['fw' => [], 'cust' => [], 'tpl' => []];
register_shutdown_function(function () use (&$made) {
    foreach ($made['tpl'] as $t) {
        foreach (['policy_assignments', 'policy_template_revisions', 'policy_exceptions', 'policy_object_mappings'] as $tb) {
            db()->prepare("DELETE FROM {$tb} WHERE template_id = ?")->execute([$t]);
        }
        db()->prepare('DELETE FROM policy_variable_values WHERE template_id = ?')->execute([$t]);
        db()->prepare('DELETE FROM policy_templates WHERE id = ?')->execute([$t]);
    }
    db()->exec("DELETE FROM policy_variable_values WHERE updated_by = 'policy-test'");
    foreach ($made['fw'] as $f) { db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$f]); }
    foreach ($made['cust'] as $c) { db()->prepare('DELETE FROM customers WHERE id = ?')->execute([$c]); }
});

// ===========================================================================
T::group('Variable validation');

$ok = fn($type, $v) => policy_validate_value($type, $v)['ok'];
T::ok($ok('ip', '10.0.0.1') && $ok('ip', '2001:db8::1'), 'IPv4 and IPv6 addresses accepted');
T::ok(!$ok('ip', '10.0.0.256') && !$ok('ip', 'ten'), 'bad IPs rejected');
T::ok($ok('cidr', '10.0.0.0/24') && $ok('cidr', '2001:db8::/32'), 'network-aligned CIDRs accepted');
T::ok(!$ok('cidr', '10.0.0.1/24'), 'a CIDR with host bits set is rejected');
T::ok(!$ok('cidr', '10.0.0.0/33') && !$ok('cidr', '10.0.0.0'), 'bad prefix / missing prefix rejected');
T::ok($ok('port', '443') && $ok('port', '1000-2000'), 'ports and ranges accepted');
T::ok(!$ok('port', '0') && !$ok('port', '65536') && !$ok('port', '2000-1000') && !$ok('port', 'http'), 'bad ports rejected');
T::ok($ok('protocol', 'TCP') && !$ok('protocol', 'gre'), 'protocol enum enforced');
T::ok($ok('interface', 'opt1') && !$ok('interface', 'Opt 1') && !$ok('interface', 'lan;rm'), 'interface keys validated');
T::ok($ok('list:ip', '1.1.1.1, 9.9.9.9') && !$ok('list:ip', ['1.1.1.1', 'nope']), 'lists validate every entry');
T::ok(!$ok('list:cidr', []), 'an empty required list is rejected');
T::eq(['1.1.1.1'], policy_validate_value('list:ip', ['1.1.1.1', '1.1.1.1'])['value'], 'list values are de-duplicated');
T::ok(!$ok('string', '<script>'), 'markup is not acceptable text');

// ===========================================================================
T::group('Content validation');

$good = ['variables' => [['name' => 'lan_subnet', 'type' => 'cidr'], ['name' => 'lan_if', 'type' => 'interface']],
         'components' => [
             ['key' => 'lan_net', 'type' => 'alias', 'name' => 'OPNMGR_LAN', 'alias_type' => 'network', 'content' => ['{{lan_subnet}}']],
             ['key' => 'allow', 'type' => 'filter_rule', 'action' => 'pass', 'interfaces' => ['{{lan_if}}'], 'source' => 'OPNMGR_LAN'],
         ]];
$v = policy_validate_content($good);
T::ok($v['ok'], 'a well-formed revision validates');
T::eq('in', $v['content']['components'][1]['direction'], 'defaults are filled in');
$bad = $good;
$bad['components'][1]['source'] = '{{nope}}';
T::ok(!policy_validate_content($bad)['ok'], 'a reference to an undeclared variable is rejected');
$bad = $good;
$bad['components'][] = $bad['components'][0];
T::ok(!policy_validate_content($bad)['ok'], 'duplicate component keys are rejected');
$bad = $good;
$bad['components'][0]['shell'] = 'rm -rf /';
T::ok(!policy_validate_content($bad)['ok'], 'unknown fields are rejected, not ignored');
$bad = $good;
$bad['components'][0]['type'] = 'raw_xml';
T::ok(!policy_validate_content($bad)['ok'], 'unknown component types are rejected');
$bad = $good;
$bad['variables'][0]['default'] = '10.0.0.1/24';
T::ok(!policy_validate_content($bad)['ok'], 'an invalid default is rejected');

// ===========================================================================
T::group('Lifecycle: draft, published (immutable), retired');

$t = policy_template_create('__policytest_base_' . getmypid(), 'test', $good);
$made['tpl'][] = $t['id'];
T::ok($t['ok'], 'template created with a draft');
$draft = $t['revision_id'];
T::ok(policy_revision_update_draft($draft, $good)['ok'], 'a draft can be edited');
T::ok(policy_revision_publish($draft)['ok'], 'the draft publishes');
$r1 = policy_revision_get($draft);
T::eq('published', $r1['status'], 'status published');
T::ok($r1['integrity_ok'], 'content hash matches');
T::ok(!policy_revision_update_draft($draft, ['variables' => [], 'components' => []])['ok'], 'a published revision cannot be edited');
db()->prepare('UPDATE policy_template_revisions SET content = ? WHERE id = ?')
    ->execute([str_replace('OPNMGR_LAN', 'OPNMGR_EVIL', $r1['content']), $draft]);
T::ok(!policy_revision_get($draft)['integrity_ok'], 'tampering with a published revision is detected');
db()->prepare('UPDATE policy_template_revisions SET content = ? WHERE id = ?')->execute([$r1['content'], $draft]);
T::ok(!policy_revision_publish($draft)['ok'], 'a published revision cannot be re-published');
$d2 = policy_revision_create_draft($t['id'], null, $draft);
T::ok($d2['ok'], 'a new draft copies the published content');
T::eq(2, (int)policy_revision_get($d2['id'])['revision'], 'and is revision 2');
T::ok(!policy_revision_create_draft($t['id'])['ok'], 'only one draft at a time');
T::ok(!policy_revision_publish(policy_template_create('__policytest_empty_' . getmypid())['revision_id'] ?? 0)['ok'],
      'an empty template cannot be published');
$made['tpl'][] = (int)db()->query("SELECT id FROM policy_templates WHERE name = '__policytest_empty_" . getmypid() . "'")->fetchColumn();
policy_revision_publish($d2['id']);

// ===========================================================================
T::group('Assignment precedence and conflicts');

db()->prepare('INSERT INTO customers (name) VALUES (?)')->execute(['__policytest_cust_' . getmypid()]);
$cust = (int)db()->lastInsertId();
$made['cust'][] = $cust;
db()->prepare('INSERT INTO sites (customer_id, name) VALUES (?,?)')->execute([$cust, 'site-a']);
$site = (int)db()->lastInsertId();
db()->prepare('INSERT INTO firewalls (hostname, ip_address, hardware_id, status, customer_id, site_id) VALUES (?,?,?,?,?,?)')
    ->execute(['policytest-fw-' . getmypid(), '198.51.100.9', bin2hex(random_bytes(16)), 'online', $cust, $site]);
$fwId = (int)db()->lastInsertId();
$made['fw'][] = $fwId;
$fw = platform_firewall($fwId);

T::ok(!policy_assignment_create($t['id'], $t['revision_id'] + 999, 'global', null)['ok'], 'an unknown revision cannot be assigned');
$dr = policy_revision_create_draft($t['id'], $good);
T::ok(!policy_assignment_create($t['id'], $dr['id'], 'global', null)['ok'], 'a draft cannot be assigned');

$ag = policy_assignment_create($t['id'], $draft, 'global', null);
$ac = policy_assignment_create($t['id'], $d2['id'], 'customer', $cust);
$res = policy_resolve_for_firewall($fw);
T::eq('customer', $res['effective'][$t['id']]['scope_type'], 'customer beats global');
T::eq($d2['id'], $res['effective'][$t['id']]['revision_id'], 'and its revision is the effective one');
$states = array_column($res['chain'], 'state', 'assignment_id');
T::eq('overridden', $states[$ag['id']], 'the global assignment is displayed as overridden');

$af = policy_assignment_create($t['id'], $draft, 'firewall', $fwId);
$res = policy_resolve_for_firewall($fw);
T::eq('firewall', $res['effective'][$t['id']]['scope_type'], 'firewall beats site and customer');
policy_assignment_remove($af['id']);

$as1 = policy_assignment_create($t['id'], $draft, 'site', $site);
$as2 = policy_assignment_create($t['id'], $d2['id'], 'site', $site);
$res = policy_resolve_for_firewall($fw);
T::ok(!isset($res['effective'][$t['id']]), 'two revisions at the winning scope: no silent winner');
T::ok((bool)array_filter($res['conflicts'], fn($c) => in_array($t['id'], $c['template_ids'], true)), 'reported as a conflict');
policy_assignment_remove($as2['id']);
$res = policy_resolve_for_firewall($fw);
T::eq('site', $res['effective'][$t['id']]['scope_type'] ?? null, 'resolving the conflict restores the site assignment');

// Cross-template ownership conflict: a second template with the same alias name.
$other = policy_template_create('__policytest_other_' . getmypid(), '', ['variables' => [], 'components' => [
    ['key' => 'x', 'type' => 'alias', 'name' => 'OPNMGR_LAN', 'alias_type' => 'host', 'content' => ['10.1.1.1']]]]);
$made['tpl'][] = $other['id'];
policy_revision_publish($other['revision_id']);
policy_assignment_create($other['id'], $other['revision_id'], 'global', null);
$res = policy_resolve_for_firewall($fw);
T::ok(!isset($res['effective'][$t['id']]) && !isset($res['effective'][$other['id']]),
      'two templates owning the same alias: neither is planned');
T::ok((bool)array_filter($res['conflicts'], fn($c) => str_contains($c['reason'], 'alias:opnmgr_lan')), 'the conflict names the object');
db()->prepare('UPDATE policy_assignments SET is_active = 0 WHERE template_id = ?')->execute([$other['id']]);

policy_revision_retire($draft);
$res = policy_resolve_for_firewall($fw);
T::ok(isset($res['invalid'][$t['id']]), 'an assignment pinned to a retired revision is reported invalid');

// ===========================================================================
T::group('Variable inheritance');

$content = policy_revision_get($d2['id'])['content_decoded'];
$r = policy_resolve_variables($fw, $t['id'], $content);
T::ok(!$r['ok'] && count($r['errors']) === 2, 'missing required variables are errors');
T::ok(!policy_variable_set('global', 0, 0, 'lan_subnet', 'cidr', '10.0.0.1/24')['ok'], 'an invalid value is refused when stored');
policy_variable_set('global', 0, 0, 'lan_subnet', 'cidr', '10.0.0.0/24');
policy_variable_set('customer', $cust, 0, 'lan_subnet', 'cidr', '10.1.0.0/24');
policy_variable_set('site', $site, 0, 'lan_if', 'interface', 'opt1');
policy_variable_set('site', $site, $t['id'], 'lan_if', 'interface', 'lan');
$r = policy_resolve_variables($fw, $t['id'], $content);
T::ok($r['ok'], 'all variables resolve');
T::eq('10.1.0.0/24', $r['values']['lan_subnet']['value'], 'customer value overrides global');
T::eq('lan', $r['values']['lan_if']['value'], 'a template-specific value beats a generic one at the same scope');
policy_variable_set('firewall', $fwId, 0, 'lan_subnet', 'cidr', '10.9.0.0/24');
T::eq('10.9.0.0/24', policy_resolve_variables($fw, $t['id'], $content)['values']['lan_subnet']['value'], 'firewall value wins');
db()->prepare("UPDATE policy_variable_values SET value = '\"10.9.0.1/24\"' WHERE scope_type = 'firewall' AND scope_id = ?")->execute([$fwId]);
T::ok(!policy_resolve_variables($fw, $t['id'], $content)['ok'], 'a stored value that became invalid is still rejected at plan time');
db()->prepare('DELETE FROM policy_variable_values WHERE scope_type IN ("customer","site","firewall") AND scope_id IN (?,?,?)')
    ->execute([$cust, $site, $fwId]);
db()->exec("DELETE FROM policy_variable_values WHERE scope_type = 'global' AND name = 'lan_subnet'");

// ===========================================================================
T::group('Exceptions');

T::ok(!policy_exception_create($fwId, $t['id'], 'allow', '', 'noc')['ok'], 'an exception needs a reason');
T::ok(!policy_exception_create($fwId, $t['id'], 'allow', 'because the vendor', '')['ok'], 'an exception needs an owner');
T::ok(!policy_exception_create($fwId, $t['id'], 'allow', 'because the vendor', 'noc', '2000-01-01')['ok'], 'expiry must be in the future');
$e1 = policy_exception_create($fwId, $t['id'], 'allow', 'Vendor appliance needs its own rule', 'noc', date('Y-m-d H:i:s', time() + 3600));
T::ok($e1['ok'], 'exception created with reason, owner and expiry');
T::ok(isset(policy_active_exceptions($fwId, $t['id'])['allow']), 'it is active');
db()->prepare('UPDATE policy_exceptions SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?')->execute([$e1['id']]);
T::ok(!isset(policy_active_exceptions($fwId, $t['id'])['allow']), 'an expired exception no longer applies');
$hist = policy_exception_history($fwId, $t['id']);
T::eq('expired', $hist[0]['state'] ?? null, 'and is kept in the history as expired');
$e2 = policy_exception_create($fwId, $t['id'], '*', 'Customer manages this site themselves', 'account manager');
T::ok(policy_exception_revoke($e2['id'])['ok'], 'exceptions are revoked, not deleted');
T::ok(!policy_exception_revoke($e2['id'])['ok'], 'and only once');
T::eq('revoked', policy_exception_history($fwId, $t['id'])[0]['state'], 'revocation is in the history');
$audit = db()->prepare("SELECT COUNT(*) FROM audit_log WHERE action IN ('policy.exception.create','policy.exception.revoke') AND firewall_id = ?");
$audit->execute([$fwId]);
T::ok((int)$audit->fetchColumn() >= 1, 'exception changes are in the audit log');
db()->prepare('DELETE FROM audit_log WHERE firewall_id = ?')->execute([$fwId]);

exit(T::summary());
