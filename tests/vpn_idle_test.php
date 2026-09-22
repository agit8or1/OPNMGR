<?php
/**
 * A quiet WireGuard peer is not a down one.
 *
 * WireGuard is connectionless: a peer handshakes when it has traffic to send,
 * so a phone with its screen off stops handshaking without anything being
 * wrong. The agent called anything past 180 seconds "down", which raised a
 * vpn.down incident for every idle peer and resolved it on the next packet -
 * alerts that looked exactly like a tunnel that had genuinely failed.
 *
 * Run with: php tests/vpn_idle_test.php
 * Creates and removes its own fixtures.
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/firewall_health.php';

$fwId = 0;
register_shutdown_function(function () use (&$fwId) {
    if (!$fwId) return;
    foreach (['firewall_vpn_tunnels', 'firewall_vpn_events'] as $t) {
        try { db()->prepare("DELETE FROM {$t} WHERE firewall_id = ?")->execute([$fwId]); } catch (Throwable $e) {}
    }
    try { db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$fwId]); } catch (Throwable $e) {}
});

db()->prepare('INSERT INTO firewalls (hostname, ip_address, hardware_id, status) VALUES (?,?,?,"online")')
    ->execute(['__test_fw_vpn__', '198.51.100.12', hash('md5', 'wg' . random_bytes(6))]);
$fwId = (int)db()->lastInsertId();

T::group('what a handshake says about a peer');

$at = static fn(int $secondsAgo): string => date('Y-m-d H:i:s', time() - $secondsAgo);

T::eq('up',   health_wireguard_status($at(30)),   'a peer that handshook 30 seconds ago is up');
T::eq('up',   health_wireguard_status($at(179)),  'and one just inside the rekey window is still up');
T::eq('idle', health_wireguard_status($at(300)),  'five minutes of quiet is idle, not down');
T::eq('idle', health_wireguard_status($at(840)),  'and so is fourteen minutes');
T::eq('down', health_wireguard_status($at(1200)), 'past the window it is down');
T::eq('down', health_wireguard_status(null),      'a peer that has never handshook is down');
T::eq('down', health_wireguard_status(''),        'an empty handshake is down, not up by accident');
T::eq('up',   health_wireguard_status($at(-60)),  'a handshake in the future is a clock difference, not a fault');

T::group('the verdict is the stored one');

// Every agent in the field sends the 180-second verdict. The server decides
// again from the handshake, so a deployed agent needs no upgrade for this.
health_ingest_vpn($fwId, [[
    'type' => 'wireguard', 'name' => 'wg0:quietpeer', 'status' => 'down',
    'peer' => 'AAAA', 'endpoint' => '203.0.113.5:51820',
    'latest_handshake' => date('c', time() - 300),
]]);

$row = db()->prepare('SELECT status FROM firewall_vpn_tunnels WHERE firewall_id = ? AND name = ?');
$row->execute([$fwId, 'wg0:quietpeer']);
T::eq('idle', $row->fetchColumn(), "the agent's \"down\" for a 5-minute-quiet peer is stored as idle");

health_ingest_vpn($fwId, [[
    'type' => 'wireguard', 'name' => 'wg0:gonepeer', 'status' => 'up',
    'peer' => 'BBBB', 'latest_handshake' => date('c', time() - 3600),
]]);
$row->execute([$fwId, 'wg0:gonepeer']);
T::eq('down', $row->fetchColumn(), 'and an hour of silence is down even if the agent said up');

// OpenVPN and IPsec are connection-oriented: their status is the truth.
health_ingest_vpn($fwId, [[
    'type' => 'openvpn', 'name' => 'roadwarrior', 'status' => 'down',
]]);
$row->execute([$fwId, 'roadwarrior']);
T::eq('down', $row->fetchColumn(), 'a non-WireGuard tunnel keeps the status the agent reported');

T::group('idle does not count as a fault');

$src = file_get_contents(TEST_ROOT . '/inc/firewall_health.php');
T::ok(str_contains($src, "NOT IN ('up','connected','idle')"),
      'the fleet health summary does not count idle tunnels as down');

$page = file_get_contents(TEST_ROOT . '/firewall_health.php');
T::ok(str_contains($page, "NOT IN ('up','connected','idle')"),
      'and neither does the per-firewall count on the health page');
T::ok(str_contains($page, "\$vState === 'idle' ? 'warning text-dark' : 'danger'"),
      'an idle peer reads as amber rather than as a failure');

$cron = file_get_contents(TEST_ROOT . '/cron/evaluate_alerts.php');
T::ok(str_contains($cron, "['up', 'connected', 'idle']"),
      'the alert evaluator raises vpn.down only for a peer that is really down');
T::ok(str_contains($cron, 'No handshake has ever been recorded'),
      'and the incident says when the tunnel was last working');

exit(T::summary());
