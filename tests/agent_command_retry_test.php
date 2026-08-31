<?php
/**
 * Redelivery behaviour for commands that take the firewall down.
 *
 * The bug these cover: checkQueuedCommands() resets any command stuck in 'sent'
 * for ten minutes back to 'pending', which is right for a command whose result
 * went missing and catastrophically wrong for a reboot, whose result can never
 * arrive because the box stops executing partway through. That reset handed
 * home.agit8or.net its own reboot again on every check-in.
 *
 * Run with: php tests/agent_command_retry_test.php
 * Creates and removes its own fixtures.
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/agent_commands.php';

$fwId = 0;
register_shutdown_function(function () use (&$fwId) {
    if (!$fwId) return;
    try { db()->prepare('DELETE FROM firewall_commands WHERE firewall_id = ?')->execute([$fwId]); } catch (Throwable $e) {}
    db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$fwId]);
});

db()->prepare('INSERT INTO firewalls (hostname, ip_address, hardware_id, status) VALUES (?,?,?,"online")')
    ->execute(['__test_fw_retry__', '198.51.100.32', hash('md5', 'rt' . random_bytes(6))]);
$fwId = (int)db()->lastInsertId();

/**
 * Queue a command already in 'sent', sent $minsAgo minutes ago.
 */
function seed_sent(int $fwId, string $command, int $minsAgo, int $isUpdate = 0): int {
    $stmt = db()->prepare(
        "INSERT INTO firewall_commands (firewall_id, command, description, status, is_update_command, created_at, sent_at)
         VALUES (?, ?, 'test', 'sent', ?, DATE_SUB(NOW(), INTERVAL ? MINUTE), DATE_SUB(NOW(), INTERVAL ? MINUTE))"
    );
    $stmt->execute([$fwId, $command, $isUpdate, $minsAgo, $minsAgo]);
    return (int)db()->lastInsertId();
}

function status_of(int $id): ?string {
    $s = db()->prepare('SELECT status FROM firewall_commands WHERE id = ?');
    $s->execute([$id]);
    $r = $s->fetchColumn();
    return $r === false ? null : (string)$r;
}

// ---------------------------------------------------------------------------
T::group('Recognising commands that cannot acknowledge');

T::ok(agent_command_is_unacknowledgeable('/sbin/reboot'), '/sbin/reboot');
T::ok(agent_command_is_unacknowledgeable('/sbin/shutdown -r +1 "via OPNManager"'), 'shutdown -r');
T::ok(agent_command_is_unacknowledgeable('/sbin/shutdown -p now'), 'shutdown -p');
T::ok(agent_command_is_unacknowledgeable('/sbin/halt'), '/sbin/halt');
T::ok(agent_command_is_unacknowledgeable('/SBIN/REBOOT'), 'matching is case-insensitive');

T::ok(!agent_command_is_unacknowledgeable('/usr/local/sbin/opnsense-update -bkp'), 'an update is not a reboot');
T::ok(!agent_command_is_unacknowledgeable('run_speedtest'), 'a speedtest is not a reboot');
T::ok(!agent_command_is_unacknowledgeable('grep reboot_required /var/log/agent.log'), 'merely mentioning reboot is not rebooting');
T::ok(!agent_command_is_unacknowledgeable(''), 'empty command');
T::ok(!agent_command_is_unacknowledgeable(null), 'null command');

// ---------------------------------------------------------------------------
T::group('Settling replaces redelivery');

$reboot = seed_sent($fwId, '/sbin/reboot', 20);
$fresh  = seed_sent($fwId, '/sbin/reboot', 2);
$normal = seed_sent($fwId, 'run_speedtest', 20);

$n = settle_unacknowledgeable_commands($fwId, 10);

T::eq('completed', status_of($reboot), 'a timed-out reboot is settled, not requeued');
T::eq('sent',      status_of($fresh),  'a reboot still inside the window is left alone');
T::eq('sent',      status_of($normal), 'settling does not touch ordinary commands');
T::eq(1, $n, 'exactly one command settled');

$res = db()->prepare('SELECT result FROM firewall_commands WHERE id = ?');
$res->execute([$reboot]);
T::ok(strpos((string)$res->fetchColumn(), 'went down executing this command') !== false,
      'the settled row explains why there is no output');

// The loop itself: the timeout reset must not pick the reboot back up.
$reset = db()->prepare(
    "UPDATE firewall_commands SET status = 'pending', sent_at = NULL
      WHERE firewall_id = ? AND status = 'sent'
        AND sent_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        AND NOT " . agent_unacknowledgeable_command_sql()
);
$reset->execute([$fwId]);

T::eq('completed', status_of($reboot), 'the reset leaves a settled reboot completed');
T::eq('pending',   status_of($normal), 'an ordinary stuck command is still retried');

// A reboot that somehow escaped settling must still not be reissued.
$escaped = seed_sent($fwId, '/sbin/reboot', 30);
$reset->execute([$fwId]);
T::eq('sent', status_of($escaped), 'the reset never returns a reboot to pending');

// ---------------------------------------------------------------------------
T::group('Update-agent path');

db()->prepare('DELETE FROM firewall_commands WHERE firewall_id = ?')->execute([$fwId]);

$updReboot = seed_sent($fwId, '/sbin/shutdown -r +1', 20, 1);
$updNormal = seed_sent($fwId, '/usr/local/sbin/opnsense-update -bkp', 20, 1);

settle_unacknowledgeable_commands($fwId, 10);

$resetUpd = db()->prepare(
    "UPDATE firewall_commands SET status = 'pending', sent_at = NULL
      WHERE firewall_id = ? AND status = 'sent' AND is_update_command = 1
        AND sent_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        AND NOT " . agent_unacknowledgeable_command_sql()
);
$resetUpd->execute([$fwId]);

T::eq('completed', status_of($updReboot), 'update-path reboot is settled too');
T::eq('pending',   status_of($updNormal), 'a stuck update command is still retried');

exit(T::summary());
