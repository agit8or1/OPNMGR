<?php
/**
 * The real probe against a real manager (3.81.0).
 *
 * Starts this tree under `php -S` on the test database, runs
 * tools/opnmgr-probe/opnmgr_probe.py --once against it, and drives a campaign
 * with what the probe actually measured:
 *
 *   - internal DNS through the system resolver (localhost)
 *   - "a service across the VPN": TCP to this host's own private LAN address,
 *     one port listening (healthy) and one closed (the broken client path)
 *   - an internet service: HTTPS HEAD to https://github.com/ (skipped, and
 *     reported as skipped, when this host has no internet)
 *
 * What is simulated: there is no WireGuard tunnel and no firewall. The private
 * address is on this host's LAN, the firewall and its update are database rows,
 * and time passing is a timestamp shift. Customer firewalls are not contacted.
 *
 *   DB_NAME=opnsense_fw_test php tests/customer_probe_e2e_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/permissions.php';
require_once TEST_ROOT . '/inc/fleet_updates.php';

$made = ['fw' => 0, 'cust' => 0, 'probe' => 0, 'campaign' => 0];
$procs = [];
$listeners = [];
register_shutdown_function(function () use (&$made, &$procs, &$listeners) {
    foreach ($procs as $p) { @proc_terminate($p); }
    foreach ($listeners as $l) { @fclose($l); }
    if ($made['probe']) { cc_delete_probe($made['probe']); }
    if ($made['campaign']) {
        db()->prepare('DELETE FROM update_campaign_targets WHERE campaign_id = ?')->execute([$made['campaign']]);
        db()->prepare('DELETE FROM update_campaigns WHERE id = ?')->execute([$made['campaign']]);
    }
    if ($made['fw']) {
        db()->prepare('DELETE FROM firewall_commands WHERE firewall_id = ?')->execute([$made['fw']]);
        db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$made['fw']]);
    }
    if ($made['cust']) { db()->prepare('DELETE FROM customers WHERE id = ?')->execute([$made['cust']]); }
});

T::eq('opnsense_fw_test', substr((string)db()->query('SELECT DATABASE()')->fetchColumn(), 0, 16), 'running against a test database');
$_SESSION = ['user_id' => 1, 'username' => 'probe-e2e', 'role' => 'admin'];
db()->exec("INSERT INTO settings (`name`,`value`) VALUES ('update_ring_soak_hours','0') ON DUPLICATE KEY UPDATE `value`='0'");

// This host's private LAN address stands in for a host across the VPN.
$lan = '';
foreach (preg_split('/\s+/', trim((string)shell_exec('hostname -I 2>/dev/null'))) as $ip) {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && cc_ip_is_internal($ip)) { $lan = $ip; break; }
}
if ($lan === '') {
    echo "SKIP: this host has no private IPv4 address to stand in for a VPN service\n";
    exit(0);
}
$open = stream_socket_server("tcp://{$lan}:0");
$listeners[] = $open;
$openPort = (int)substr(strrchr(stream_socket_get_name($open, false), ':'), 1);
$tmp = stream_socket_server("tcp://{$lan}:0");
$closedPort = (int)substr(strrchr(stream_socket_get_name($tmp, false), ':'), 1);
fclose($tmp);                                   // nothing listens here now
$internet = (bool)@fsockopen('github.com', 443, $e, $es, 3);

db()->prepare('INSERT INTO customers (name) VALUES (?)')->execute(['__e2e probe customer__']);
$made['cust'] = $cust = (int)db()->lastInsertId();
db()->prepare('INSERT INTO firewalls (hostname, ip_address, hardware_id, status, last_checkin, customer_id, update_ring, updates_available,
               platform_family, platform_os_version, platform_detection_confidence, platform_verified_at)
               VALUES ("__e2e_fw__","198.51.100.80",?, "online", NOW(), ?, "canary", 1, "opnsense", "26.7.8", "high", NOW())')
    ->execute([md5(random_bytes(8)), $cust]);
$made['fw'] = $fw = (int)db()->lastInsertId();

$p = cc_create_probe($cust, $fw, 'E2E workstation');
$made['probe'] = $pid = $p['id'];
$token = $p['token'];
$dnsId    = cc_add_check($pid, ['check_type' => 'dns', 'target' => 'localhost', 'label' => 'Resolver answers'])['id'];
$vpnOk    = cc_add_check($pid, ['check_type' => 'vpn_service', 'target' => "{$lan}:{$openPort}", 'label' => 'App server'])['id'];
$vpnBad   = cc_add_check($pid, ['check_type' => 'vpn_service', 'target' => "{$lan}:{$closedPort}", 'label' => 'File server'])['id'];
$inetId   = $internet ? cc_add_check($pid, ['check_type' => 'internet_service', 'target' => 'https://github.com/', 'label' => 'GitHub'])['id'] : 0;
T::ok($dnsId && $vpnOk && $vpnBad, 'checks approved through the same validation the UI uses');

$port = (function () { $s = stream_socket_server('tcp://127.0.0.1:0'); $n = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1); fclose($s); return $n; })();
$procs[] = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', TEST_ROOT],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, TEST_ROOT,
    ['PATH' => getenv('PATH'), 'DB_NAME' => getenv('DB_NAME')]);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port, $e, $es, 0.1); $i++) { usleep(100000); }

$runProbe = function (string $tok) use ($port): array {
    db()->exec('UPDATE customer_probes SET last_seen_at = NULL');   // the 10 s rate limit, not under test here
    exec('env -i PATH=' . escapeshellarg((string)getenv('PATH')) . ' OPNMGR_PROBE_TOKEN=' . escapeshellarg($tok)
        . ' python3 -I ' . escapeshellarg(TEST_ROOT . '/tools/opnmgr-probe/opnmgr_probe.py')
        . ' --manager http://127.0.0.1:' . $port . ' --once 2>&1', $o, $rc);
    return ['rc' => $rc, 'out' => implode("\n", $o)];
};
$latest = fn(int $cid) => cc_latest_result($cid);

// ===========================================================================
T::group('The probe measures the paths');

$r = $runProbe($token);
T::eq(1, $r['rc'], 'one round, exit 1 because one path is down');
T::ok(str_contains($r['out'], "PASS check {$vpnOk}") && str_contains($r['out'], "FAIL check {$vpnBad}"), 'and it says which');
T::eq(1, (int)($latest($dnsId)['ok'] ?? -1), 'internal DNS: pass (' . ($latest($dnsId)['detail'] ?? '') . ')');
T::eq(1, (int)($latest($vpnOk)['ok'] ?? -1), "service across the VPN {$lan}:{$openPort}: pass");
T::eq(0, (int)($latest($vpnBad)['ok'] ?? -1), "service across the VPN {$lan}:{$closedPort}: fail");
T::ok(str_contains((string)($latest($vpnBad)['detail'] ?? ''), 'connection refused'), 'with the reason: ' . ($latest($vpnBad)['detail'] ?? ''));
if ($inetId) {
    T::eq(1, (int)($latest($inetId)['ok'] ?? -1), 'internet service https://github.com/: pass (' . ($latest($inetId)['detail'] ?? '') . ')');
} else {
    echo "  SKIP  internet service: this host cannot reach github.com:443\n";
}
T::ok($latest($vpnOk)['latency_ms'] !== null, 'latency is recorded');
T::ok(db()->query("SELECT probe_version FROM customer_probes WHERE id = {$pid}")->fetchColumn() === '1.0.0', 'and the probe version');

// ===========================================================================
T::group('A rollout driven by those results');

$c = campaign_create(['name' => '__e2e probe campaign__', 'operation' => 'install', 'customer_checks' => 1], [$fw]);
$made['campaign'] = $cid = $c['id'];
campaign_start_ring($cid, 'canary');
$d = campaign_dispatch($cid);
T::eq(1, $d['dispatched'], 'fresh results exist, so the target is dispatched');
$base = json_decode((string)db()->query("SELECT customer_baseline FROM update_campaign_targets WHERE campaign_id = {$cid}")->fetchColumn(), true);
$byId = array_column($base ?: [], 'state', 'check_id');
T::eq('fail', $byId[$vpnBad] ?? null, 'the baseline records the file server path as already failing');

$cmd = (int)db()->query("SELECT command_id FROM update_campaign_targets WHERE campaign_id = {$cid}")->fetchColumn();
db()->prepare('UPDATE firewall_commands SET status = "completed", result = "ok" WHERE id = ?')->execute([$cmd]);
campaign_reconcile($cid);
db()->prepare('UPDATE firewalls SET updates_available = 0 WHERE id = ?')->execute([$fw]);
db()->exec("UPDATE update_campaign_targets SET completed_at = completed_at - INTERVAL 300 SECOND, dispatched_at = dispatched_at - INTERVAL 300 SECOND WHERE campaign_id = {$cid}");
db()->exec("UPDATE customer_probe_results SET observed_at = observed_at - INTERVAL 300 SECOND WHERE probe_id = {$pid}");

$r = $runProbe($token);
$gate = cc_ring_gate($cid, 'canary');
T::eq('fail', $gate['state'], 'after the update the probe still cannot reach the file server: fail');
$why = implode(' ', $gate['reasons']);
T::ok(str_contains($why, 'Service across VPN') && str_contains($why, 'File server') && str_contains($why, 'already failing before the update'),
      'the reason names the path and that it was failing before: ' . substr($why, 0, 160));
T::ok(!campaign_ring_is_clean($cid, 'canary')['clean'], 'the ring is held');

$listeners[] = stream_socket_server("tcp://{$lan}:{$closedPort}");   // the file server comes back
$r = $runProbe($token);
T::eq(0, $r['rc'], 'next round: every path passes');
T::eq('pass', cc_ring_gate($cid, 'canary')['state'], 'the gate passes on real results');
T::ok(campaign_ring_is_clean($cid, 'canary')['clean'], 'and the ring is clean');

// ===========================================================================
T::group('Offline and revoked probes');

db()->exec("UPDATE customer_probe_results SET observed_at = observed_at - INTERVAL 3600 SECOND WHERE probe_id = {$pid}");
T::eq('stale', cc_ring_gate($cid, 'canary')['state'], 'a probe that has not reported for an hour: stale');

cc_set_probe_enabled($pid, false);
$r = $runProbe($token);
T::ok($r['rc'] !== 0 && str_contains($r['out'], 'rejected this token'), 'a disabled probe is told its token was rejected');
$r = $runProbe('opp_' . str_repeat('0', 48));
T::ok(str_contains($r['out'], 'rejected this token'), 'and so is an unknown token');

exit(T::summary());
