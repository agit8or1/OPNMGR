<?php
/**
 * OPNMGR reboot-state derivation tests.
 *
 * Run with: php tests/reboot_state_test.php
 * Creates and removes its own fixtures.
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/reboot_state.php';

$fwId = 0;
register_shutdown_function(function () use (&$fwId) {
    if (!$fwId) return;
    try { db()->prepare('DELETE FROM firewall_commands WHERE firewall_id = ?')->execute([$fwId]); } catch (Throwable $e) {}
    db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$fwId]);
});

db()->prepare('INSERT INTO firewalls (hostname, ip_address, hardware_id, status) VALUES (?,?,?,"online")')
    ->execute(['__test_fw_reboot__', '198.51.100.31', hash('md5', 'rb' . random_bytes(6))]);
$fwId = (int)db()->lastInsertId();

// ---------------------------------------------------------------------------
T::group('Uptime parsing');

T::eq(187 * 86400, parse_uptime_to_seconds('187 days'), 'multi-day uptime');
T::eq(86400,       parse_uptime_to_seconds('1 day'),    'singular day');
T::eq(4 * 3600 + 12 * 60, parse_uptime_to_seconds('4:12'), 'h:mm form');
T::eq(42 * 60,     parse_uptime_to_seconds('42 mins'),  'minutes');
T::eq(23,          parse_uptime_to_seconds('23 secs'),  'seconds');
T::eq(null,        parse_uptime_to_seconds('Unknown'),  'unknown is null, not zero');
T::eq(null,        parse_uptime_to_seconds(''),         'empty is null, not zero');
T::eq(null,        parse_uptime_to_seconds(null),       'null input is null');
T::eq(null,        parse_uptime_to_seconds('nonsense'), 'unparseable is null');

// A zero here would place boot time at the check-in instant and silently
// declare every pending reboot satisfied, so the null distinction matters.
T::ok(parse_uptime_to_seconds('garbage') !== 0, 'unparseable never collapses to 0 seconds');

// ---------------------------------------------------------------------------
T::group('Reboot state without an installed update');

$fw = ['id' => $fwId, 'uptime' => '13 days', 'last_checkin' => date('Y-m-d H:i:s')];
$v  = firewall_reboot_state($fw);
T::eq('not_pending', $v['state'], 'no update on record means no evidence of a pending reboot');

// This is the fw.agit8or.net case: a flag set in March by code that guessed,
// on a box that has rebooted many times since.
db()->prepare('UPDATE firewalls SET reboot_required = 1, uptime = ?, last_checkin = NOW() WHERE id = ?')
    ->execute(['13 days', $fwId]);
$r = recompute_reboot_required($fwId);
T::eq('not_pending', $r['state'], 'stale flag is recomputed away');
T::ok($r['changed'], 'recompute reports that it changed the stored value');
T::eq(0, (int)db()->query("SELECT reboot_required FROM firewalls WHERE id = {$fwId}")->fetchColumn(),
    'stored flag cleared');

// ---------------------------------------------------------------------------
T::group('Reboot state after a successful update');

$installedAt = date('Y-m-d H:i:s', time() - 3600);
db()->prepare(
    "INSERT INTO firewall_commands (firewall_id, command, action, description, status, result, created_at, completed_at)
     VALUES (?, 'opnsense-update', 'install_updates', 'test', 'completed', ?, ?, ?)"
)->execute([$fwId, "OPNMGR_UPDATE_EXIT=0\nYour packages are up to date.", $installedAt, $installedAt]);

// Booted long before the update landed -> the new kernel is not running.
db()->prepare('UPDATE firewalls SET uptime = ?, last_checkin = NOW() WHERE id = ?')->execute(['187 days', $fwId]);
$r = recompute_reboot_required($fwId);
T::eq('pending', $r['state'], 'update installed after last boot means a reboot is outstanding');
T::eq(1, (int)db()->query("SELECT reboot_required FROM firewalls WHERE id = {$fwId}")->fetchColumn(),
    'stored flag set');

// Booted after the update landed -> already running it.
db()->prepare('UPDATE firewalls SET uptime = ?, last_checkin = NOW() WHERE id = ?')->execute(['5 mins', $fwId]);
$r = recompute_reboot_required($fwId);
T::eq('not_pending', $r['state'], 'boot after the update clears the requirement');

// ---------------------------------------------------------------------------
T::group('A failed update is not treated as installed');

db()->prepare('DELETE FROM firewall_commands WHERE firewall_id = ?')->execute([$fwId]);
db()->prepare(
    "INSERT INTO firewall_commands (firewall_id, command, action, description, status, result, created_at, completed_at)
     VALUES (?, 'opnsense-update', 'install_updates', 'test', 'completed', ?, ?, ?)"
)->execute([$fwId, "OPNMGR_UPDATE_EXIT=70\nsomething broke", $installedAt, $installedAt]);

db()->prepare('UPDATE firewalls SET uptime = ?, last_checkin = NOW() WHERE id = ?')->execute(['187 days', $fwId]);
$v = firewall_reboot_state(['id' => $fwId, 'uptime' => '187 days', 'last_checkin' => date('Y-m-d H:i:s')]);
T::eq('not_pending', $v['state'], 'a non-zero exit is not an installed update');

// The agent reports every command as completed, so status alone must not count.
db()->prepare('DELETE FROM firewall_commands WHERE firewall_id = ?')->execute([$fwId]);
db()->prepare(
    "INSERT INTO firewall_commands (firewall_id, command, action, description, status, result, created_at, completed_at)
     VALUES (?, 'opnsense-update', 'install_updates', 'test', 'completed', ?, ?, ?)"
)->execute([$fwId, "no marker at all", $installedAt, $installedAt]);
$v = firewall_reboot_state(['id' => $fwId, 'uptime' => '187 days', 'last_checkin' => date('Y-m-d H:i:s')]);
T::eq('not_pending', $v['state'], 'completed status without an exit marker proves nothing');

// ---------------------------------------------------------------------------
T::group('Unreadable uptime never clears a real flag');

db()->prepare('DELETE FROM firewall_commands WHERE firewall_id = ?')->execute([$fwId]);
db()->prepare(
    "INSERT INTO firewall_commands (firewall_id, command, action, description, status, result, created_at, completed_at)
     VALUES (?, 'opnsense-update', 'install_updates', 'test', 'completed', ?, ?, ?)"
)->execute([$fwId, "OPNMGR_UPDATE_EXIT=0", $installedAt, $installedAt]);

db()->prepare('UPDATE firewalls SET reboot_required = 1, uptime = ?, last_checkin = NOW() WHERE id = ?')
    ->execute(['Unknown', $fwId]);
$r = recompute_reboot_required($fwId);
T::eq('unknown', $r['state'], 'unreadable uptime yields unknown');
T::ok(!$r['changed'], 'unknown state leaves the stored value alone');
T::eq(1, (int)db()->query("SELECT reboot_required FROM firewalls WHERE id = {$fwId}")->fetchColumn(),
    'a real pending reboot survives an unreadable uptime');

exit(T::summary());
