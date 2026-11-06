<?php
/**
 * Policy templates: deployment workflow against the disposable database.
 *
 * Agent behaviour is simulated by writing command results and backups the way
 * the agent endpoints would. No firewall is contacted.
 *
 * Proves: preview-only outside lab firewalls; stale base backup is not
 * approvable; ring ordering, one target at a time; read-back + check-in before
 * confirm; failure stops later targets; stale-plan rejection when the
 * fingerprint changes; the per-firewall deployment lock; HA secondary never
 * written; an apply without an outcome marker is never confirmed; failure
 * event emitted; no secrets in plans, commands or audit metadata.
 *
 *   DB_NAME=opnsense_fw_test_policy php tests/policy_deploy_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/policy/policy.php';

if (strpos((string)db()->query('SELECT DATABASE()')->fetchColumn(), 'opnsense_fw_test') !== 0) {
    fwrite(STDERR, "Refusing: not connected to a test database\n");
    exit(2);
}

$EVENTS = [];
if (!function_exists('integration_emit')) {
    function integration_emit(string $event, array $ctx): void { $GLOBALS['EVENTS'][] = [$event, $ctx]; }
}

$_SESSION = ['user_id' => 1, 'username' => 'policy-deploy-test', 'role' => 'admin'];
$FIX = TEST_ROOT . '/tests/fixtures/configs';
$TMP = sys_get_temp_dir() . '/opnmgr-policy-deploy-' . getmypid();
@mkdir($TMP, 0700, true);
$FWS = [];
$TPLS = [];
$savedSettings = db()->query("SELECT name, value FROM settings WHERE name IN
    ('policy_apply_lab_firewall_ids','policy_manager_source_ips','policy_max_backup_age_minutes')")->fetchAll(PDO::FETCH_KEY_PAIR);

register_shutdown_function(function () use (&$FWS, &$TPLS, $TMP, $savedSettings) {
    foreach ($FWS as $id) {
        foreach (['firewall_commands', 'backups', 'audit_log', 'policy_deployment_targets', 'policy_firewall_locks',
                  'policy_firewall_state', 'policy_exceptions'] as $t) {
            db()->prepare("DELETE FROM {$t} WHERE firewall_id = ?")->execute([$id]);
        }
        db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$id]);
    }
    foreach ($TPLS as $t) {
        foreach (['policy_deployments', 'policy_assignments', 'policy_template_revisions', 'policy_variable_values'] as $tb) {
            db()->prepare("DELETE FROM {$tb} WHERE template_id = ?")->execute([$t]);
        }
        db()->prepare('DELETE FROM policy_templates WHERE id = ?')->execute([$t]);
    }
    foreach ($savedSettings as $k => $v) {
        db()->prepare('UPDATE settings SET value = ? WHERE name = ?')->execute([$v, $k]);
    }
    exec('rm -rf ' . escapeshellarg($TMP));
});

function set_setting(string $k, string $v): void {
    db()->prepare('INSERT INTO settings (name, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$k, $v]);
}

function mkfw(string $family, string $ring = 'production', array $extra = []): int {
    global $FWS;
    $cols = array_merge([
        'hostname' => 'pd-' . $family . '-' . bin2hex(random_bytes(3)), 'ip_address' => '198.51.100.50',
        'hardware_id' => bin2hex(random_bytes(16)), 'status' => 'online', 'update_ring' => $ring,
        'platform_family' => $family, 'platform_edition' => $family === 'pfsense' ? 'community' : null,
        'platform_os_version' => $family === 'pfsense' ? '2.7.2-RELEASE' : '26.7.8',
        'platform_detection_confidence' => 'high', 'platform_verified_at' => date('Y-m-d H:i:s'),
        'platform_probe' => json_encode(['opnsense_config_api' => true, 'pfsense_config_restore' => true]),
        'last_checkin' => date('Y-m-d H:i:s', time() - 60),
    ], $extra);
    $ph = implode(',', array_fill(0, count($cols), '?'));
    db()->prepare('INSERT INTO firewalls (' . implode(',', array_keys($cols)) . ") VALUES ({$ph})")->execute(array_values($cols));
    $id = (int)db()->lastInsertId();
    $FWS[] = $id;
    return $id;
}

function add_backup(int $fwId, string $xml, int $ageMinutes = 1): int {
    global $TMP;
    $path = sprintf('%s/fw%d-%s.xml', $TMP, $fwId, bin2hex(random_bytes(4)));
    file_put_contents($path, $xml);
    $at = date('Y-m-d H:i:s', time() - $ageMinutes * 60);
    db()->prepare('INSERT INTO backups (firewall_id, backup_file, description, backup_type, file_size, storage_path,
                                        checksum_sha256, validated, uploaded_at, created_at)
                   VALUES (?,?,?,"automated",?,?,?,1,?,?)')
        ->execute([$fwId, basename($path), 'test', strlen($xml), $path, hash('sha256', $xml), $at, $at]);
    return (int)db()->lastInsertId();
}

/** What an agent upload does to a requested backup row. */
function fulfil_backup(int $backupId, string $xml): void {
    global $TMP;
    $path = "{$TMP}/upload-{$backupId}.xml";
    file_put_contents($path, $xml);
    db()->prepare('UPDATE backups SET storage_path = ?, checksum_sha256 = ?, file_size = ?, validated = 1, uploaded_at = NOW() WHERE id = ?')
        ->execute([$path, hash('sha256', $xml), strlen($xml), $backupId]);
}

function complete_cmd(?int $cmdId, string $result, string $status = 'completed'): void {
    db()->prepare('UPDATE firewall_commands SET status = ?, result = ?, completed_at = NOW() WHERE id = ?')->execute([$status, $result, $cmdId]);
}

function checkin(int $fwId): void {
    db()->prepare('UPDATE firewalls SET last_checkin = DATE_ADD(NOW(), INTERVAL 5 SECOND), status = "online" WHERE id = ?')->execute([$fwId]);
}

function target(int $depId, int $fwId): array {
    $s = db()->prepare('SELECT * FROM policy_deployment_targets WHERE deployment_id = ? AND firewall_id = ?');
    $s->execute([$depId, $fwId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
}

function commands(int $fwId, string $action): array {
    $s = db()->prepare('SELECT * FROM firewall_commands WHERE firewall_id = ? AND action = ? ORDER BY id');
    $s->execute([$fwId, $action]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/** Render what the device would end up with after applying a target. */
function artifact_for(int $fwId, int $revisionId): string {
    $fw = platform_firewall($fwId);
    $c = policy_context($fw, $revisionId);
    return policy_plan(policy_base_config($fwId)['xml'], $c['ctx'])['xml'];
}

/** Drive one target through apply -> verify -> confirm successfully. */
function succeed_target(int $depId, int $fwId, int $revisionId): void {
    $t = target($depId, $fwId);
    $art = artifact_for($fwId, $revisionId);
    complete_cmd((int)$t['apply_command_id'], "fetched\nOPNMGR_POLICY_APPLY=ok deadline=123");
    policy_reconcile();
    $t = target($depId, $fwId);
    fulfil_backup((int)$t['verify_backup_id'], $art);
    checkin($fwId);
    policy_reconcile();
    $t = target($depId, $fwId);
    complete_cmd((int)$t['confirm_command_id'], 'OPNMGR_POLICY_CONFIRM=ok');
    policy_reconcile();
}

set_setting('policy_manager_source_ips', '10.50.0.5');
set_setting('policy_max_backup_age_minutes', '60');
set_setting('policy_apply_lab_firewall_ids', '');

// --- the template ------------------------------------------------------------
$content = ['variables' => [['name' => 'lan_if', 'type' => 'interface'], ['name' => 'dns', 'type' => 'list:ip']],
            'components' => [
                ['key' => 'svc', 'type' => 'alias', 'name' => 'OPNMGR_SVC', 'alias_type' => 'host', 'content' => ['10.20.0.80']],
                ['key' => 'allow_svc', 'type' => 'filter_rule', 'action' => 'pass', 'interfaces' => ['{{lan_if}}'],
                 'protocol' => 'tcp', 'destination' => 'OPNMGR_SVC', 'destination_port' => '443'],
                ['key' => 'dns', 'type' => 'dns_servers', 'servers' => '{{dns}}'],
            ]];
$tpl = policy_template_create('__policydeploy_' . getmypid(), '', $content);
$TPLS[] = $tpl['id'];
policy_revision_publish($tpl['revision_id']);
$rev = $tpl['revision_id'];
policy_variable_set('global', 0, $tpl['id'], 'lan_if', 'interface', 'lan');
policy_variable_set('global', 0, $tpl['id'], 'dns', 'list:ip', '9.9.9.9');

$homeXml = file_get_contents("{$FIX}/opnsense-26.7-home-sanitized.xml");
$pfXml = file_get_contents("{$FIX}/pfsense-ce-2.7.2-synthetic.xml");

// ===========================================================================
T::group('Preview only outside lab firewalls; freshness');

$fwP = mkfw('opnsense');
add_backup($fwP, $homeXml);
$pv = policy_preview_firewall(platform_firewall($fwP), $rev);
T::ok($pv['plan']['change_count'] > 0, 'the preview still shows the semantic changes');
T::ok($pv['preview_only'] && !$pv['approvable'], 'policy.apply is untested: preview only');
T::ok((bool)array_filter($pv['blockers'], fn($b) => str_starts_with($b, 'Preview only:') && str_contains($b, 'lab')),
      'and the UI is told why (not a designated lab device)');
$d = policy_deployment_create($rev, [$fwP]);
T::eq('blocked', target($d['id'], $fwP)['status'], 'the deployment target is blocked');
T::ok(!policy_deployment_approve($d['id'])['ok'], 'nothing can be approved');

$fwOld = mkfw('opnsense');
add_backup($fwOld, $homeXml, 180);
set_setting('policy_apply_lab_firewall_ids', (string)$fwOld);
$pv = policy_preview_firewall(platform_firewall($fwOld), $rev);
T::ok(!$pv['approvable'] && (bool)array_filter($pv['blockers'], fn($b) => str_contains($b, 'minutes old')),
      'a base backup older than the limit is not approvable');
$req = policy_request_backup($fwOld, 'test fresh backup');
T::ok($req['ok'] && count(commands($fwOld, 'retrieve_config')) === 1, 'a fresh backup can be requested');

// ===========================================================================
T::group('Rings, one target at a time, verify before confirm, failure stops later targets');

$fwC = mkfw('opnsense', 'canary');
$fwPi = mkfw('opnsense', 'pilot');
$fwPr = mkfw('opnsense', 'production');
foreach ([$fwC, $fwPi, $fwPr] as $f) { add_backup($f, $homeXml); }
set_setting('policy_apply_lab_firewall_ids', implode(',', [$fwC, $fwPi, $fwPr]));

$d = policy_deployment_create($rev, [$fwPr, $fwPi, $fwC]);
T::ok($d['ok'], 'deployment created');
T::eq([0, 1, 2], [(int)target($d['id'], $fwC)['order_index'], (int)target($d['id'], $fwPi)['order_index'],
                  (int)target($d['id'], $fwPr)['order_index']], 'ordered canary, pilot, production');
T::ok((int)target($d['id'], $fwC)['approvable'] === 1, 'lab firewall with a fresh backup: approvable');
$_SESSION['username'] = 'approver';
$ap = policy_deployment_approve($d['id']);
T::eq(3, $ap['approved'], 'three plans approved');
$t = target($d['id'], $fwC);
T::ok(strlen((string)$t['approval_hash']) === 64 && $t['approved_fingerprint'] === $t['base_fingerprint'],
      'approval binds the plan hash and base fingerprint');

$ex = policy_deployment_execute($d['id']);
T::ok($ex['ok'], 'execution starts');
T::eq('applying', target($d['id'], $fwC)['status'], 'canary first');
T::eq('approved', target($d['id'], $fwPi)['status'], 'pilot waits');
T::eq(1, count(commands($fwC, 'retrieve_config')), 'a pre-change backup was queued');
$apply = commands($fwC, 'policy_apply');
T::eq(1, count($apply), 'one apply command queued');
T::ok(str_contains($apply[0]['command'], target($d['id'], $fwC)['base_fingerprint'])
      && str_contains($apply[0]['command'], target($d['id'], $fwC)['artifact_sha256']), 'it carries the base and artifact sha256');
T::ok(!str_contains($apply[0]['command'], '<opnsense>'), 'the configuration itself is never in the command');

complete_cmd((int)$apply[0]['id'], 'OPNMGR_POLICY_APPLY=ok deadline=1');
policy_reconcile();
$t = target($d['id'], $fwC);
T::eq('verifying', $t['status'], 'apply ok: verifying, not succeeded');
T::ok($t['verify_backup_id'] > 0, 'a read-back backup was requested');
policy_reconcile();
T::eq('verifying', target($d['id'], $fwC)['status'], 'without the read-back nothing is confirmed');
fulfil_backup((int)$t['verify_backup_id'], artifact_for($fwC, $rev));
policy_reconcile();
T::eq('verifying', target($d['id'], $fwC)['status'], 'read-back alone is not enough: the agent must check in after the apply');
checkin($fwC);
policy_reconcile();
$t = target($d['id'], $fwC);
T::eq('confirming', $t['status'], 'read-back exact and agent back: confirm queued');
T::eq(1, count(commands($fwC, 'policy_confirm')), 'one confirm command');
complete_cmd((int)$t['confirm_command_id'], 'OPNMGR_POLICY_CONFIRM=ok');
policy_reconcile();
T::eq('succeeded', target($d['id'], $fwC)['status'], 'canary succeeded');
$st = db()->prepare('SELECT * FROM policy_firewall_state WHERE firewall_id = ? AND template_id = ?');
$st->execute([$fwC, $tpl['id']]);
T::eq($rev, (int)($st->fetch(PDO::FETCH_ASSOC)['last_applied_revision_id'] ?? 0), 'last applied revision recorded');
T::eq('applying', target($d['id'], $fwPi)['status'], 'pilot started after canary succeeded');

// Pilot: the read-back does not contain the change.
$t = target($d['id'], $fwPi);
complete_cmd((int)$t['apply_command_id'], 'OPNMGR_POLICY_APPLY=ok deadline=1');
policy_reconcile();
$t = target($d['id'], $fwPi);
fulfil_backup((int)$t['verify_backup_id'], $homeXml);
checkin($fwPi);
$EVENTS = [];
policy_reconcile();
T::eq('rolled_back', target($d['id'], $fwPi)['status'], 'a read-back mismatch is rolled back, not confirmed');
T::eq(0, count(commands($fwPi, 'policy_confirm')), 'no confirm was ever sent');
T::eq(1, count(commands($fwPi, 'policy_rollback')), 'an explicit rollback was queued');
T::eq('held', target($d['id'], $fwPr)['status'], 'the production target is held');
T::eq(0, count(commands($fwPr, 'policy_apply')), 'and never received an apply');
T::eq('stopped', policy_deployment_get($d['id'])['status'], 'the deployment stopped');
T::eq('policy.deployment_failed', $EVENTS[0][0] ?? null, 'the failure event was emitted');
T::ok(($EVENTS[0][1]['firewall_id'] ?? 0) === $fwPi && ($EVENTS[0][1]['template_id'] ?? 0) === $tpl['id']
      && ($EVENTS[0][1]['deployment_id'] ?? 0) === $d['id'], 'with firewall, template and deployment context');
$lk = db()->prepare('SELECT COUNT(*) FROM policy_firewall_locks WHERE firewall_id IN (?,?)');
$lk->execute([$fwC, $fwPi]);
T::eq(0, (int)$lk->fetchColumn(), 'locks released on success and on failure');

// No outcome marker: never confirmed.
$fwM = mkfw('opnsense');
add_backup($fwM, $homeXml);
set_setting('policy_apply_lab_firewall_ids', (string)$fwM);
$d = policy_deployment_create($rev, [$fwM]);
policy_deployment_approve($d['id']);
policy_deployment_execute($d['id']);
complete_cmd((int)target($d['id'], $fwM)['apply_command_id'], 'some output but no marker');
policy_reconcile();
T::eq('rolled_back', target($d['id'], $fwM)['status'], 'a "completed" apply without an outcome marker is treated as unverified');
$d2 = policy_deployment_create($rev, [$fwM]);
policy_deployment_approve($d2['id']);
policy_deployment_execute($d2['id']);
complete_cmd((int)target($d2['id'], $fwM)['apply_command_id'], 'OPNMGR_POLICY_APPLY=refused_fingerprint');
policy_reconcile();
T::ok(target($d2['id'], $fwM)['status'] === 'failed' && str_contains(target($d2['id'], $fwM)['detail'], 'refused_fingerprint'),
      'a device-side fingerprint refusal fails the target with the reason');

// ===========================================================================
T::group('Stale plan: approval invalidated when the configuration changes');

$fwS = mkfw('opnsense');
add_backup($fwS, $homeXml);
set_setting('policy_apply_lab_firewall_ids', (string)$fwS);
$d = policy_deployment_create($rev, [$fwS]);
policy_deployment_approve($d['id']);
$doc = policy_dom_load($homeXml);
policy_dom_set(policy_dom_child($doc->documentElement, 'system'), 'hostname', 'changed-on-the-box');
add_backup($fwS, $doc->saveXML());
$r = policy_deployment_execute($d['id']);
$t = target($d['id'], $fwS);
T::eq('stale', $t['status'], 'a new configuration fingerprint invalidates the approval');
T::ok($t['approval_hash'] === null && $t['approved_fingerprint'] === null, 'the stored approval is cleared');
T::eq('planned', policy_deployment_get($d['id'])['status'], 'the deployment returns to planned');
T::eq(0, count(commands($fwS, 'policy_apply')), 'nothing was queued');
T::ok(policy_replan_target((int)$t['id'])['ok'] && target($d['id'], $fwS)['status'] === 'planned', 're-planning makes it approvable again');
T::ok(policy_deployment_approve($d['id'])['ok'], 'and it can be approved against the new fingerprint');
policy_variable_set('global', 0, $tpl['id'], 'dns', 'list:ip', '9.9.9.9,1.0.0.1');
policy_deployment_execute($d['id']);
T::eq('stale', target($d['id'], $fwS)['status'], 'a variable change after approval also invalidates it');
policy_variable_set('global', 0, $tpl['id'], 'dns', 'list:ip', '9.9.9.9');

// ===========================================================================
T::group('Per-firewall deployment lock');

$fwL = mkfw('opnsense');
add_backup($fwL, $homeXml);
set_setting('policy_apply_lab_firewall_ids', (string)$fwL);
$dA = policy_deployment_create($rev, [$fwL]);
$dB = policy_deployment_create($rev, [$fwL]);
policy_deployment_approve($dA['id']);
policy_deployment_approve($dB['id']);
policy_deployment_execute($dA['id']);
T::eq('applying', target($dA['id'], $fwL)['status'], 'first deployment applying');
$rb = policy_deployment_execute($dB['id']);
T::ok(!empty($rb['locked']), 'the second deployment cannot take the firewall');
T::eq('approved', target($dB['id'], $fwL)['status'], 'it waits, still approved');
T::eq(1, count(commands($fwL, 'policy_apply')), 'only one apply was queued');
T::ok(!policy_lock_acquire($fwL, 999999), 'the lock refuses another target');
T::ok(policy_lock_acquire($fwL, (int)target($dA['id'], $fwL)['id']), 'and is re-entrant for its holder');
db()->prepare('UPDATE policy_firewall_locks SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE firewall_id = ?')->execute([$fwL]);
T::ok(policy_lock_acquire($fwL, 999999), 'an expired lock is reclaimed');
policy_lock_release($fwL, 999999);

// ===========================================================================
T::group('Artifact endpoint');

$tA = target($dA['id'], $fwL);
$lb = drift_load_backup_xml((int)$tA['base_backup_id']);
$re = policy_plan($lb['xml'], policy_context(platform_firewall($fwL), $rev)['ctx']);
T::eq($tA['artifact_sha256'], $re['artifact_sha256'], 're-rendering the approved base gives the approved artifact bytes');
T::eq($tA['approved_plan_hash'], $re['plan_hash'], 'and the approved plan hash');
$resp = call_endpoint('api/policy_artifact.php', ['post' => ['firewall_id' => $fwL, 'target_id' => $tA['id'],
    'token' => (string)$tA['fetch_token'], 'hardware_id' => 'wrong', 'api_key' => 'wrong']]);
T::ok(in_array($resp['status'], [401, 403], true), 'without agent credentials the artifact is refused (HTTP ' . $resp['status'] . ')');
T::ok(!str_contains($resp['body'], '<opnsense'), 'and no configuration is returned');
$resp = call_endpoint('api/policy_artifact.php', ['method' => 'GET']);
T::eq(405, $resp['status'], 'GET is refused');

// ===========================================================================
T::group('HA: the sync source is written, the partner verified by read-back');

$pfContent = $content;
$tplH = policy_template_create('__policyha_' . getmypid(), '', $pfContent);
$TPLS[] = $tplH['id'];
policy_revision_publish($tplH['revision_id']);
policy_variable_set('global', 0, $tplH['id'], 'lan_if', 'interface', 'lan');
policy_variable_set('global', 0, $tplH['id'], 'dns', 'list:ip', '9.9.9.9');
$revH = $tplH['revision_id'];

$fwA = mkfw('pfsense', 'production', ['carp_enabled' => 1, 'carp_state' => 'MASTER', 'updates_available' => 0]);
$fwB = mkfw('pfsense', 'production', ['carp_enabled' => 1, 'carp_state' => 'BACKUP', 'updates_available' => 0,
                                      'ha_peer_firewall_id' => $fwA]);
db()->prepare('UPDATE firewalls SET ha_peer_firewall_id = ? WHERE id = ?')->execute([$fwB, $fwA]);
add_backup($fwA, $pfXml);   // synchronizetoip set; syncs rules and aliases
$docB = policy_dom_load(file_get_contents("{$FIX}/pfsense-plus-25.07-synthetic.xml"));
$hs = policy_dom_child($docB->documentElement, 'hasync');
policy_dom_remove(policy_dom_child($hs, 'synchronizetoip'));
$bXml = $docB->saveXML();
add_backup($fwB, $bXml);
set_setting('policy_apply_lab_firewall_ids', "{$fwA},{$fwB}");

$solo = policy_deployment_create($revH, [$fwB]);
$tb = target($solo['id'], $fwB);
T::eq('blocked', $tb['status'], 'the secondary alone is not deployable');
T::ok(str_contains((string)$tb['blockers'], 'HA primary'), 'because it receives the sections from its HA primary');

$d = policy_deployment_create($revH, [$fwA, $fwB]);
T::eq('primary', target($d['id'], $fwA)['ha_role'], 'the sync source is the primary');
T::eq('secondary_verify', target($d['id'], $fwB)['ha_role'], 'the partner is verify-only');
policy_deployment_approve($d['id']);
policy_deployment_execute($d['id']);
T::eq(0, count(commands($fwB, 'policy_apply')), 'the secondary is not written while the primary applies');
succeed_target($d['id'], $fwA, $revH);
T::eq('succeeded', target($d['id'], $fwA)['status'], 'primary succeeded');
$tb = target($d['id'], $fwB);
T::eq('verifying', $tb['status'], 'secondary now verifying by read-back');
// What config sync would have delivered: the primary's managed objects in the secondary's config.
$synced = policy_plan($bXml, policy_context(platform_firewall($fwB), $revH)['ctx'])['xml'] ?? null;
fulfil_backup((int)$tb['verify_backup_id'], $synced);
checkin($fwB);
policy_reconcile();
T::eq('succeeded', target($d['id'], $fwB)['status'], 'secondary verified without ever being written');
T::eq(0, count(commands($fwB, 'policy_apply')) + count(commands($fwB, 'policy_confirm')), 'no apply or confirm ever went to the secondary');
T::eq('completed', policy_deployment_get($d['id'])['status'], 'deployment completed');

// ===========================================================================
T::group('Secrets never reach plans, commands or audit metadata');

$ids = implode(',', array_map('intval', $FWS));
$blob = implode("\n", array_merge(
    db()->query("SELECT plan_json FROM policy_deployment_targets WHERE firewall_id IN ({$ids})")->fetchAll(PDO::FETCH_COLUMN),
    db()->query("SELECT command FROM firewall_commands WHERE firewall_id IN ({$ids})")->fetchAll(PDO::FETCH_COLUMN),
    db()->query("SELECT CONCAT_WS(' ', message, metadata) FROM audit_log WHERE firewall_id IN ({$ids}) OR action LIKE 'policy.%'")->fetchAll(PDO::FETCH_COLUMN)
));
T::ok(strlen($blob) > 1000, 'there is plenty to inspect');
T::ok(!str_contains($blob, 'FIXTURE-'), 'no fixture secret (PSK, WireGuard key, HA/CARP password) appears');
T::ok(!preg_match('/\$2[aby]\$\d\d\$/', $blob), 'no password hash appears');
T::ok(!str_contains($blob, '<hasync>') && !str_contains($blob, '<pfsense>'), 'no configuration XML appears');

exit(T::summary());

