<?php
/**
 * OPNMGR fleet update, bulk operation, config search and restore tests.
 *
 * Run with: php tests/fleet_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/permissions.php';
require_once TEST_ROOT . '/inc/fleet_updates.php';
require_once TEST_ROOT . '/inc/bulk_ops.php';
require_once TEST_ROOT . '/inc/config_search.php';
require_once TEST_ROOT . '/inc/config_restore.php';

$fw = ['a' => 0, 'b' => 0, 'solo' => 0];
$custId = 0;

register_shutdown_function(function () use (&$fw, &$custId) {
    foreach (array_filter($fw) as $id) {
        foreach (['update_campaign_targets','bulk_operation_targets','firewall_commands',
                  'config_restores','backups','audit_log','firewall_carp'] as $t) {
            try { db()->prepare("DELETE FROM {$t} WHERE firewall_id = ?")->execute([$id]); } catch (Throwable $e) {}
        }
        db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$id]);
    }
    try {
        db()->exec('DELETE FROM update_campaigns WHERE name LIKE "__test%"');
        db()->exec('DELETE FROM bulk_operations WHERE created_by_username = "fleet-test"');
    } catch (Throwable $e) {}
    if ($custId) {
        db()->prepare('DELETE FROM customers WHERE id = ?')->execute([$custId]);
    }
});

$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'fleet-test';
$_SESSION['role'] = 'admin';

db()->prepare('INSERT INTO customers (name) VALUES (?)')->execute(['__test_fleet_cust__']);
$custId = (int)db()->lastInsertId();

/** Create a test firewall. */
function mkfw(string $name, array $extra = []): int {
    global $custId;
    $cols = array_merge([
        'hostname' => $name, 'ip_address' => '198.51.100.30',
        'hardware_id' => hash('md5', $name . random_bytes(6)),
        'status' => 'online', 'customer_id' => $custId,
        'updates_available' => 1, 'alerts_enabled' => 1,
    ], $extra);
    $ph = implode(',', array_fill(0, count($cols), '?'));
    db()->prepare('INSERT INTO firewalls (' . implode(',', array_keys($cols)) . ") VALUES ({$ph})")
        ->execute(array_values($cols));
    return (int)db()->lastInsertId();
}

// ===========================================================================
T::group('Update rings');

$fw['solo'] = mkfw('__test_fw_solo__');
T::eq('production', db()->query("SELECT update_ring FROM firewalls WHERE id = {$fw['solo']}")->fetchColumn(),
      'firewalls default to the production ring');

T::ok(set_update_ring($fw['solo'], 'canary')['ok'], 'a firewall can be moved to canary');
T::eq('canary', db()->query("SELECT update_ring FROM firewalls WHERE id = {$fw['solo']}")->fetchColumn(),
      'the ring is persisted');
T::ok(!set_update_ring($fw['solo'], 'gold')['ok'], 'an unknown ring is rejected');
T::ok(!set_update_ring(999999, 'canary')['ok'], 'a missing firewall is rejected');

// ===========================================================================
T::group('HA safety');

// Standalone firewall: nothing to coordinate.
$solo = ha_dispatch_check($fw['solo']);
T::ok($solo['safe'], 'a standalone firewall is always safe to dispatch to');

// Build a CARP pair.
$fw['a'] = mkfw('__test_fw_master__', ['carp_enabled' => 1, 'carp_state' => 'MASTER']);
$fw['b'] = mkfw('__test_fw_backup__', ['carp_enabled' => 1, 'carp_state' => 'BACKUP']);
db()->prepare('UPDATE firewalls SET ha_peer_firewall_id = ? WHERE id = ?')->execute([$fw['b'], $fw['a']]);
db()->prepare('UPDATE firewalls SET ha_peer_firewall_id = ? WHERE id = ?')->execute([$fw['a'], $fw['b']]);

$master = ha_dispatch_check($fw['a']);
T::ok(!$master['safe'], 'the MASTER is held while the BACKUP still needs the update');
T::ok(str_contains($master['reason'], 'BACKUP'), 'and the reason names the BACKUP member');

$backup = ha_dispatch_check($fw['b']);
T::ok($backup['safe'], 'the BACKUP member is safe to update first');

// Never both at once.
$both = ha_dispatch_check($fw['b'], [$fw['a']]);
T::ok(!$both['safe'], 'a firewall is held while its HA partner is mid-update');
T::ok(str_contains($both['reason'], 'partner'), 'and the reason says so');

// A partner that just failed blocks the other member.
db()->prepare('UPDATE firewalls SET last_update_attempt_at = NOW(), last_update_result = "failed" WHERE id = ?')
    ->execute([$fw['b']]);
$afterFail = ha_dispatch_check($fw['a']);
T::ok(!$afterFail['safe'], 'a failed partner update blocks the other member');
T::ok(str_contains($afterFail['reason'], 'failed'), 'and the reason says the partner failed');

// A partner that is still offline blocks it too.
db()->prepare('UPDATE firewalls SET last_update_result = "succeeded", status = "offline",
               last_checkin = NOW() - INTERVAL 2 HOUR WHERE id = ?')->execute([$fw['b']]);
$notBack = ha_dispatch_check($fw['a']);
T::ok(!$notBack['safe'], 'a partner that has not come back online blocks the other member');

// ===========================================================================
T::group('Update campaigns');

db()->prepare('UPDATE firewalls SET status = "online", last_checkin = NOW(),
               last_update_attempt_at = NULL, last_update_result = NULL WHERE id IN (?,?)')
    ->execute([$fw['a'], $fw['b']]);

$bad = campaign_create(['name' => ''], [$fw['solo']]);
T::ok(!$bad['ok'], 'a campaign needs a name');

$empty = campaign_create(['name' => '__test campaign__'], []);
T::ok(!$empty['ok'], 'a campaign needs at least one target');

$c = campaign_create(['name' => '__test campaign__', 'operation' => 'check'],
                     [$fw['solo'], $fw['a'], $fw['b']]);
T::ok($c['ok'], 'a campaign can be created');
$cid = $c['id'];

$summary = campaign_ring_summary($cid);
T::eq(1, $summary['canary']['total'], 'targets land in the ring their firewall belongs to');
T::eq(2, $summary['production']['total'], 'and the rest in production');

T::eq('draft', db()->query("SELECT status FROM update_campaigns WHERE id = {$cid}")->fetchColumn(),
      'a new campaign is a draft and dispatches nothing');

$d = campaign_dispatch($cid);
T::eq(0, $d['dispatched'], 'a draft campaign dispatches nothing even if asked');

T::ok(!campaign_start_ring($cid, 'nonsense')['ok'], 'an unknown ring cannot be started');

T::ok(campaign_start_ring($cid, 'canary')['ok'], 'the canary ring can be started');
$d = campaign_dispatch($cid);
T::eq(1, $d['dispatched'], 'exactly the canary target is dispatched');

$clean = campaign_ring_is_clean($cid, 'canary');
T::ok(!$clean['clean'], 'a ring still in progress is not clean');
T::ok(str_contains($clean['reason'], 'progress'), 'and says it is in progress');

// Production ring with an HA pair: only the BACKUP should go out first.
T::ok(campaign_start_ring($cid, 'production')['ok'], 'the production ring can be started');
$d = campaign_dispatch($cid);
T::eq(1, $d['dispatched'], 'only one member of the HA pair is dispatched');
T::eq(1, $d['held'], 'the other member is held, not failed');

$held = db()->prepare('SELECT firewall_id, hold_reason FROM update_campaign_targets
                        WHERE campaign_id = ? AND status = "holding"');
$held->execute([$cid]);
$heldRow = $held->fetch(PDO::FETCH_ASSOC);
T::eq($fw['a'], (int)$heldRow['firewall_id'], 'the MASTER is the one held back');
T::ok($heldRow['hold_reason'] !== null, 'and the hold carries an explanation');

// A ring with a failure never counts as clean, so auto-progress cannot roll on.
db()->prepare('UPDATE update_campaign_targets SET status = "failed", completed_at = NOW()
                WHERE campaign_id = ? AND ring = "canary"')->execute([$cid]);
$clean = campaign_ring_is_clean($cid, 'canary');
T::ok(!$clean['clean'], 'a ring containing a failure is never clean');
T::ok(str_contains($clean['reason'], 'failed'), 'and the reason names the failures');

// ===========================================================================
T::group('Bulk operations');

$catalog = bulk_operation_catalog();
T::ok(!isset($catalog['raw_command']), 'raw shell is NOT available as a bulk operation');
T::ok(isset($catalog['reboot']), 'reboot is available');
T::eq('CRITICAL', $catalog['reboot']['risk'], 'and is classified critical');

T::ok(bulk_requires_confirmation('reboot'),          'a critical operation requires confirmation');
T::ok(bulk_requires_confirmation('install_updates'), 'a high-risk operation requires confirmation');
T::ok(!bulk_requires_confirmation('check_updates'),  'a low-risk operation does not');

$phrase2 = bulk_confirmation_phrase('reboot', 2);
$phrase9 = bulk_confirmation_phrase('reboot', 9);
T::ok($phrase2 !== $phrase9, 'the confirmation phrase includes the target count');

$noConfirm = bulk_execute('reboot', [$fw['solo']], [], '');
T::ok(!$noConfirm['ok'], 'a critical bulk operation without confirmation is refused');

$wrongCount = bulk_execute('reboot', [$fw['solo']], [], bulk_confirmation_phrase('reboot', 99));
T::ok(!$wrongCount['ok'], 'a confirmation for a different target count is refused');

$unknown = bulk_execute('do_something_bad', [$fw['solo']]);
T::ok(!$unknown['ok'], 'an unknown bulk operation is refused');

$noTargets = bulk_execute('check_updates', []);
T::ok(!$noTargets['ok'], 'a bulk operation with no targets is refused');

$ok = bulk_execute('check_updates', [$fw['solo'], $fw['a']]);
T::ok($ok['ok'], 'a low-risk bulk operation runs');
T::eq(2, $ok['queued'], 'and queues a command per target');

$tagged = bulk_execute('add_tag', [$fw['solo']], ['tag' => '__test_bulk_tag__']);
T::ok($tagged['ok'], 'a server-side bulk operation runs');
$tagCount = db()->prepare('SELECT COUNT(*) FROM firewall_tags ft JOIN tags t ON t.id = ft.tag_id
                            WHERE ft.firewall_id = ? AND t.name = ?');
$tagCount->execute([$fw['solo'], '__test_bulk_tag__']);
T::eq(1, (int)$tagCount->fetchColumn(), 'and actually applies the change');
bulk_execute('remove_tag', [$fw['solo']], ['tag' => '__test_bulk_tag__']);
db()->prepare('DELETE FROM tags WHERE name = ?')->execute(['__test_bulk_tag__']);

// Role gating.
$_SESSION['role'] = 'readonly';
$denied = bulk_execute('check_updates', [$fw['solo']]);
T::ok(!$denied['ok'], 'a read-only user cannot run bulk operations');
$_SESSION['role'] = 'technician';
$deniedReboot = bulk_execute('reboot', [$fw['solo']], [], bulk_confirmation_phrase('reboot', 1));
T::ok(!$deniedReboot['ok'], 'a technician cannot bulk reboot');
$_SESSION['role'] = 'admin';

// ===========================================================================
T::group('Configuration search');

$r = config_search_fleet('check:not_a_real_check');
T::ok($r['error'] !== '', 'an unknown named check reports an error');

$r = config_search_fleet('');
T::eq(0, $r['scanned'], 'an empty query scans nothing');

$r = config_search_fleet('999.999.999.0/24');
T::ok($r['error'] !== '', 'a malformed CIDR is rejected');

$r = config_search_fleet('check:ssh_on_wan');
T::eq('check', $r['kind'], 'a named check is recognised');
$r = config_search_fleet('10.0.0.0/8');
T::eq('cidr', $r['kind'], 'a CIDR is recognised');
$r = config_search_fleet('somestring');
T::eq('literal', $r['kind'], 'anything else is a literal search');

// config_search_rules must cope with one rule and with many.
$one  = ['filter' => ['rule' => ['type' => 'pass', 'descr' => 'only']]];
$many = ['filter' => ['rule' => [['type' => 'pass'], ['type' => 'block']]]];
T::eq(1, count(config_search_rules($one)),  'a single rule is normalised to a one-element list');
T::eq(2, count(config_search_rules($many)), 'multiple rules stay a list');
T::eq(0, count(config_search_rules([])),    'a config with no rules yields none');

// ===========================================================================
T::group('Restore safety');

$v = restore_validate(999999);
T::ok(!$v['ok'], 'restoring a backup that does not exist is refused');

db()->prepare('INSERT INTO backups (firewall_id, backup_file, backup_type) VALUES (?,?,"manual")')
    ->execute([$fw['solo'], 'no-such-file.xml']);
$missingId = (int)db()->lastInsertId();
$v = restore_validate($missingId);
T::ok(!$v['ok'], 'restoring a backup whose file is missing is refused');
T::ok(str_contains(strtolower($v['error']), 'missing'), 'and says the file is missing');

$offline = mkfw('__test_fw_offline__', ['status' => 'offline']);
$fw['offline'] = $offline;
db()->prepare('INSERT INTO backups (firewall_id, backup_file, backup_type) VALUES (?,?,"manual")')
    ->execute([$offline, 'x.xml']);
$offlineBackup = (int)db()->lastInsertId();
$v = restore_validate($offlineBackup);
T::ok(!$v['ok'], 'restoring to an offline firewall is refused');

$r = restore_start($missingId, 'wrong-hostname');
T::ok(!$r['ok'], 'a restore with the wrong confirmation is refused');

exit(T::summary());
