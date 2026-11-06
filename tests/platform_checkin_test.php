<?php
/**
 * Platform handling through the real check-in endpoint.
 *
 *   - a pfSense agent's platform block is recorded (family, edition, probe)
 *   - a legacy-style OPNsense command queued for that firewall is not handed
 *     out; it is closed as failed with the reason
 *   - a firmware request on pfSense is closed as unsupported, not answered
 *     with opnsense-update
 *   - a legacy OPNsense plugin agent with no platform block is still
 *     recognised as OPNsense, and its commands still flow
 *
 *   tests/lib/make_test_db.sh && DB_NAME=opnsense_fw_test php tests/platform_checkin_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/crypto.php';

$made = [];
register_shutdown_function(function () use (&$made) {
    foreach ($made as $id) {
        foreach (['firewall_commands', 'firewall_agents', 'agent_request_nonces', 'audit_log', 'system_logs'] as $t) {
            try { db()->prepare("DELETE FROM {$t} WHERE firewall_id = ?")->execute([$id]); } catch (Throwable $e) {}
        }
        db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$id]);
    }
});

function fw_with_creds(string $tag): array {
    global $made;
    $hw = md5('pc-' . $tag . random_bytes(6));
    $key = opnmgr_random_secret(32);
    db()->prepare('INSERT INTO firewalls (hostname, hardware_id, ip_address, agent_api_key, agent_api_secret,
                   api_key_confirmed, api_key_issued_at, status) VALUES (?,?,?,?,?,1,NOW(),"online")')
        ->execute(['__pc_' . $tag . '__', $hw, '198.51.100.9', opnmgr_encrypt($key), opnmgr_encrypt(opnmgr_random_secret(32))]);
    $id = (int)db()->lastInsertId();
    $made[] = $id;
    return ['id' => $id, 'hw' => $hw, 'key' => $key];
}

function checkin(array $fw, array $payload): array {
    return call_endpoint('agent_checkin.php', [
        'body'    => array_merge(['hardware_id' => $fw['hw']], $payload),
        'headers' => ['X-OPNMGR-API-Key' => $fw['key']],
    ]);
}

// ===========================================================================
T::group('pfSense agent check-in');

$pf = fw_with_creds('pf');
db()->prepare('INSERT INTO firewall_commands (firewall_id, command, description, status) VALUES (?,?,?,"pending")')
    ->execute([$pf['id'], '/usr/local/sbin/configctl unbound restart', 'legacy direct insert']);
$legacyCmd = (int)db()->lastInsertId();
db()->prepare('UPDATE firewalls SET update_requested = 1 WHERE id = ?')->execute([$pf['id']]);

$r = checkin($pf, [
    'agent_version' => '1.0.0', 'opnsense_version' => '2.7.2-RELEASE', 'uptime' => '3 days',
    'platform' => [
        'family' => 'pfsense', 'agent_flavor' => 'pfsense-agent', 'product_version' => '2.7.2-RELEASE',
        'base_version' => '14.0-CURRENT', 'config_version' => '23.3',
        'markers' => ['pfsense_globals' => true, 'plus_globals' => false, 'opnsense_version_bin' => false],
        'packages' => ['pfSense-pkg-WireGuard-0.2.1'],
        'probes' => ['php' => true, 'pfssh' => true, 'pfsense_config_restore' => true, 'wg' => true],
    ],
]);
T::eq(200, $r['status'], 'check-in accepted (' . substr($r['body'], 0, 120) . ')');
$row = db()->query('SELECT * FROM firewalls WHERE id = ' . $pf['id'])->fetch(PDO::FETCH_ASSOC);
T::eq('pfsense', $row['platform_family'], 'platform recorded as pfSense');
T::eq('community', $row['platform_edition'], 'edition recorded as CE');
T::eq('high', $row['platform_detection_confidence'], 'with high confidence');
T::eq('pfsense-agent', $row['agent_flavor'], 'agent flavour recorded');
T::ok(str_contains((string)$row['platform_probe'], 'pfsense_config_restore'), 'probe stored');
T::ok(empty($r['json']['queued_commands']), 'the configctl command was not handed to the pfSense agent');
$cmd = db()->query("SELECT status, result FROM firewall_commands WHERE id = $legacyCmd")->fetch(PDO::FETCH_ASSOC);
T::eq('failed', $cmd['status'], 'and it was closed as failed');
T::ok(str_contains((string)$cmd['result'], 'configctl'), 'with the reason');
T::ok(empty($r['json']['opnsense_update_requested']), 'no opnsense-update was sent');
T::eq('unsupported', $row['last_update_result'], 'the firmware request was closed as unsupported');
T::eq('0', (string)$row['update_requested'], 'and cleared');

// ===========================================================================
T::group('Legacy OPNsense plugin agent (no platform block)');

$op = fw_with_creds('opn');
db()->prepare('INSERT INTO firewall_commands (firewall_id, command, description, status) VALUES (?,?,?,"pending")')
    ->execute([$op['id'], 'uptime', 'legacy raw']);
$okCmd = (int)db()->lastInsertId();
$r = checkin($op, ['agent_version' => '1.7.0', 'opnsense_version' => '26.7.8', 'uptime' => '1 day']);
T::eq(200, $r['status'], 'check-in accepted');
$row = db()->query('SELECT * FROM firewalls WHERE id = ' . $op['id'])->fetch(PDO::FETCH_ASSOC);
T::eq('opnsense', $row['platform_family'], 'recognised as OPNsense from opnsense-version output');
T::eq('medium', $row['platform_detection_confidence'], 'at medium confidence');
$ids = array_column($r['json']['queued_commands'] ?? [], 'id');
T::ok(in_array($okCmd, array_map('intval', $ids), true), 'its commands are still delivered');

// ===========================================================================
T::group('Unknown platform');

$un = fw_with_creds('unk');
db()->prepare('INSERT INTO firewall_commands (firewall_id, command, description, status) VALUES (?,?,?,"pending")')
    ->execute([$un['id'], 'uptime', 'raw']);
$unkCmd = (int)db()->lastInsertId();
$r = checkin($un, ['agent_version' => '0.9.0', 'uptime' => '1 day']);
T::eq(200, $r['status'], 'check-in accepted');
T::ok(empty($r['json']['queued_commands']), 'raw shell is not delivered to an unverified platform');
T::eq('failed', db()->query("SELECT status FROM firewall_commands WHERE id = $unkCmd")->fetchColumn(), 'and is closed');

exit(T::summary());
