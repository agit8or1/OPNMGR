<?php
/**
 * Bandwidth tests that say what they measured.
 *
 * Before 3.79.2: 4 streams for 8 s to a distant public server (a ~1.6 Gbps
 * plateau on a 10 Gbps link), failed upload legs stored as 0 Mbps, latency
 * hardcoded to 0, and the results API shifted local timestamps by -5 h.
 *
 *   tests/lib/make_test_db.sh && DB_NAME=opnsense_fw_test php tests/speedtest_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/speedtest.php';
require_once TEST_ROOT . '/inc/platform/platform.php';

$tmp = sys_get_temp_dir() . '/opnmgr-speedtest-' . getmypid();
@mkdir($tmp . '/bin', 0700, true);
$made = []; $users = [];
register_shutdown_function(function () use (&$made, &$users, $tmp) {
    foreach ($made as $id) {
        db()->prepare('DELETE FROM bandwidth_tests WHERE firewall_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM firewalls WHERE id = ?')->execute([$id]);
    }
    foreach ($users as $u) { db()->prepare('DELETE FROM users WHERE id = ?')->execute([$u]); }
    exec('rm -rf ' . escapeshellarg($tmp));
});

// ===========================================================================
T::group('Agent: the iperf3 test itself (shell, stubbed binaries)');

$agent = TEST_ROOT . '/plugin/os-opnmanager-agent/src/opnsense/scripts/OPNsense/OPNManagerAgent/agent.sh';
$fns = shell_exec(sprintf("sed -n '/^run_iperf3_test()/,/^}/p;/^run_speedtest()/,/^}/p' %s", escapeshellarg($agent)));
// The agent calls /usr/local/bin/python3 (FreeBSD path); use this host's.
$fns = str_replace('/usr/local/bin/python3', 'python3', (string)$fns);
file_put_contents("$tmp/fns.sh", "log_message() { :; }\n" . $fns);

/** iperf3 stub: logs its args; -R (download) and upload results set by env. */
file_put_contents("$tmp/bin/iperf3", <<<'SH'
#!/bin/sh
[ "$1" = "--version" ] && { echo "iperf 3.22 (cJSON 1.7.15)"; exit 0; }
echo "$*" >> "$STUB_LOG"
case " $* " in *" -R "*) bps="$DL_BPS" ;; *) bps="$UL_BPS" ;; esac
[ "$bps" = "fail" ] && { echo '{"error":"unable to connect"}'; exit 1; }
printf '{"end":{"sum_received":{"bits_per_second":%s},"sum_sent":{"bits_per_second":1},"cpu_utilization_percent":{"host_total":41.5,"remote_total":12.0}}}\n' "$bps"
SH);
file_put_contents("$tmp/bin/ping", "#!/bin/sh\necho 'round-trip min/avg/max/stddev = 1.000/2.345/3.000/0.1 ms'\n");
file_put_contents("$tmp/bin/timeout", "#!/bin/sh\nshift\nexec \"\$@\"\n");
chmod("$tmp/bin/iperf3", 0700); chmod("$tmp/bin/ping", 0700); chmod("$tmp/bin/timeout", 0700);

$runAgent = function (string $args, string $dl, string $ul) use ($tmp): array {
    @unlink("$tmp/iperf.log");
    // Exactly as the agent does it: the argument text arrives in a variable
    // and is word-split unquoted (with globbing off); it is never re-parsed
    // as shell, so ; and $( ) inside it are plain characters.
    file_put_contents("$tmp/run.sh", ". $tmp/fns.sh\nset -f\nARGS=" . escapeshellarg($args) . "\nrun_speedtest \$ARGS\n");
    $out = (string)shell_exec('PATH=' . escapeshellarg("$tmp/bin") . ':/usr/bin:/bin STUB_LOG=' . escapeshellarg("$tmp/iperf.log")
        . " DL_BPS=$dl UL_BPS=$ul sh " . escapeshellarg("$tmp/run.sh") . ' 2>/dev/null');
    return [json_decode(trim($out), true), (string)@file_get_contents("$tmp/iperf.log"), $out];
};

[$j, $log] = $runAgent('servers=iperf.example.net:5202 streams=32 duration=15', '9400000000', '9100000000');
T::ok(is_array($j), 'the agent emits JSON');
T::eq(9400, $j['download_mbps'] ?? null, 'download is the receiver-side rate');
T::eq(9100, $j['upload_mbps'] ?? null, 'upload is the receiver-side rate too (not sum_sent)');
T::ok(str_contains($log, '-p 5202') && str_contains($log, '-P 32') && str_contains($log, '-t 15') && str_contains($log, '-O 2'),
      'server port, streams, duration and the omitted ramp-up reach iperf3: ' . trim($log));
T::eq(2.345, $j['ping_ms'] ?? null, 'latency is measured, not hardcoded');
T::eq(32, $j['streams'] ?? null, 'streams are reported');
T::eq('3.22', $j['iperf_version'] ?? null, 'iperf3 version is reported');
T::eq(41, $j['local_cpu_percent'] ?? null, 'firewall CPU is reported');

[$j] = $runAgent('', '1600000000', 'fail');
T::eq(1600, $j['download_mbps'] ?? null, 'defaults still run with no arguments (pre-1.8.1 manager)');
T::ok(array_key_exists('upload_mbps', $j) && $j['upload_mbps'] === null, 'a failed upload leg is null, not 0');
T::ok(!empty($j['upload_error']), 'and says why');

[, $log] = $runAgent('streams=500 duration=999', '1000000', '1000000');
T::ok(str_contains($log, '-P 128') && str_contains($log, '-t 60'), 'streams and duration are clamped');

[$j, $log, $raw] = $runAgent('servers=$(touch_' . 'x),;id,good.example.net streams=4', '5000000', '5000000');
T::ok(!str_contains($log, 'touch') && !str_contains($log, ';id') && str_contains($log, 'good.example.net'),
      'invalid server entries never reach iperf3: ' . trim($log));

[$j] = $runAgent('servers=a.example.net', 'fail', 'fail');
T::ok(isset($j['error']) && !isset($j['download_mbps']), 'all servers failing is an error, not a 0 Mbps result');

// ===========================================================================
T::group('Agent: Ookla engine (stub binary, synthetic package)');

$ofns = shell_exec(sprintf("sed -n '/^OOKLA_PKG_URL=/,/^OOKLA_BIN=/p;/^sha256_of()/p;/^ensure_ookla()/,/^}/p;/^run_ookla_test()/,/^}/p' %s", escapeshellarg($agent)));
$ofns = str_replace('/usr/local/bin/python3', 'python3', (string)$ofns);
$ofns = str_replace('home=/var/db/opnmgr-ookla', 'home=' . $tmp . '/ookla-home', $ofns);
file_put_contents("$tmp/ookla.sh", "log_message() { :; }\n" . $fns . "\n" . $ofns);

// A stand-in for Ookla's binary: prints a result in Ookla's JSON shape.
$okBin = "#!/bin/sh\necho \"\$*\" > $tmp/ookla.args\n"
    . "printf '%s\\n' '{\"type\":\"result\",\"ping\":{\"jitter\":0.4,\"latency\":1.9},\"download\":{\"bandwidth\":1162500000},"
    . "\"upload\":{\"bandwidth\":1150000000},\"packetLoss\":0,\"server\":{\"id\":12345,\"name\":\"Example DC\",\"location\":\"Somewhere\"},"
    . "\"result\":{\"url\":\"https://www.speedtest.net/result/c/0000-test\"}}'\n";
@mkdir("$tmp/pkgroot/usr/local/bin", 0700, true);
file_put_contents("$tmp/pkgroot/usr/local/bin/speedtest", $okBin);
chmod("$tmp/pkgroot/usr/local/bin/speedtest", 0755);
exec('tar -C ' . escapeshellarg("$tmp/pkgroot") . ' -cJf ' . escapeshellarg("$tmp/fake.pkg") . ' usr/local/bin/speedtest');
$binSha = hash_file('sha256', "$tmp/pkgroot/usr/local/bin/speedtest");
$pkgSha = hash_file('sha256', "$tmp/fake.pkg");
// fetch stub: "downloads" the synthetic package to wherever -o says.
file_put_contents("$tmp/bin/fetch", "#!/bin/sh\nwhile [ \$# -gt 0 ]; do [ \"\$1\" = \"-o\" ] && { shift; cp " . escapeshellarg("$tmp/fake.pkg") . " \"\$1\"; }; shift; done\n");
chmod("$tmp/bin/fetch", 0700);

$runOokla = function (string $args, string $pkgShaEnv, string $binShaEnv) use ($tmp): array {
    @unlink("$tmp/opt/speedtest");
    file_put_contents("$tmp/runo.sh", ". $tmp/ookla.sh\nset -f\nARGS=" . escapeshellarg($args) . "\nrun_speedtest \$ARGS\n");
    $out = (string)shell_exec('PATH=' . escapeshellarg("$tmp/bin") . ':/usr/bin:/bin OPNMGR_OOKLA_BIN=' . escapeshellarg("$tmp/opt/speedtest")
        . ' OPNMGR_OOKLA_PKG_SHA256=' . $pkgShaEnv . ' OPNMGR_OOKLA_BIN_SHA256=' . $binShaEnv
        . ' sh ' . escapeshellarg("$tmp/runo.sh") . ' 2>/dev/null');
    return [json_decode(trim((string)preg_replace('/^.*\n(?=\{)/s', '', trim($out))), true), $out];
};

[$j, $raw] = $runOokla('engine=ookla ookla_server=12345', $pkgSha, $binSha);
T::ok(is_array($j) && ($j['engine'] ?? '') === 'ookla', 'the Ookla engine runs: ' . substr($raw, 0, 160));
T::eq(9300, $j['download_mbps'] ?? null, 'download converted from bytes/s (1,162,500,000 B/s = 9,300 Mbps)');
T::eq(9200, $j['upload_mbps'] ?? null, 'upload converted from bytes/s');
T::eq(1.9, $j['ping_ms'] ?? null, 'latency from Ookla');
T::ok(str_contains((string)($j['server'] ?? ''), '#12345'), 'the Ookla server is identified');
T::ok(str_contains((string)@file_get_contents("$tmp/ookla.args"), '--accept-license')
      && str_contains((string)@file_get_contents("$tmp/ookla.args"), '-s 12345'), 'licence flags and server id reach the CLI');
T::ok(is_file("$tmp/opt/speedtest"), 'the binary is installed from the package after both checksums match');

[$j] = $runOokla('engine=ookla', str_repeat('0', 64), $binSha);
T::ok(isset($j['error']) && str_contains($j['error'], 'package checksum'), 'a package with the wrong checksum is refused');
T::ok(!is_file("$tmp/opt/speedtest"), 'and nothing is installed');
[$j] = $runOokla('engine=ookla', $pkgSha, str_repeat('0', 64));
T::ok(isset($j['error']) && str_contains($j['error'], 'binary checksum'), 'a binary with the wrong checksum is refused');

// ===========================================================================
T::group('Manager: command text, gated by agent version');

$mk = function (array $cols) use (&$made): array {
    db()->prepare('INSERT INTO firewalls (hostname, ip_address, hardware_id, status) VALUES (?,?,?,"online")')
        ->execute(['__st_' . bin2hex(random_bytes(3)), '10.0.0.1', md5(random_bytes(8))]);
    $id = (int)db()->lastInsertId(); $made[] = $id;
    if ($cols) {
        $set = implode(',', array_map(fn($k) => "$k = ?", array_keys($cols)));
        db()->prepare("UPDATE firewalls SET $set WHERE id = ?")->execute([...array_values($cols), $id]);
    }
    return platform_firewall($id);
};
T::eq('run_speedtest', speedtest_command_for($mk(['agent_version' => '1.8.0'])), 'agents before 1.8.1 get the bare command');
T::eq('run_speedtest', speedtest_command_for($mk(['agent_version' => '1.0.0', 'agent_flavor' => 'pfsense-agent'])), 'the pfSense agent gets the bare command');
$dc = $mk(['agent_version' => '1.8.1', 'speedtest_server' => 'iperf.dc.example.net:5201,bad;host', 'speedtest_streams' => 24,
           'wan_link_down_mbps' => 10000, 'wan_link_up_mbps' => 10000]);
$cmd = speedtest_command_for($dc);
T::eq('run_speedtest servers=iperf.dc.example.net:5201 streams=24 duration=10', $cmd, 'per-firewall server and streams; invalid entries dropped');
T::ok(speedtest_is_command($cmd) && speedtest_is_command('run_speedtest') && !speedtest_is_command('run_speedtestx'), 'speedtest commands are recognised');
$cfg = speedtest_config($dc);
T::eq(10000, $cfg['link_down'], 'provisioned link speed is part of the config');
T::ok(platform_delivery_verdict(array_merge($dc, ['platform_family' => 'opnsense', 'platform_verified_at' => date('Y-m-d H:i:s')]),
      ['command' => $cmd, 'action' => null, 'is_raw' => 1])['deliver'], 'the command passes the platform delivery gate on OPNsense');

db()->exec("UPDATE settings SET `value` = '' WHERE `name` IN ('speedtest_ookla_terms_accepted_at','speedtest_ookla_terms_accepted_by')");
$ok182 = $mk(['agent_version' => '1.8.2', 'speedtest_engine' => 'ookla', 'speedtest_ookla_server_id' => 777]);
T::ok(str_contains(speedtest_command_for($ok182), 'streams='), 'Ookla is not used before the terms are accepted (iperf3 instead)');
speedtest_ookla_accept_terms(true);
T::eq('run_speedtest engine=ookla ookla_server=777', speedtest_command_for($ok182), 'after acceptance, an enabled 1.8.2 agent runs Ookla');
$ok181 = $mk(['agent_version' => '1.8.1', 'speedtest_engine' => 'ookla']);
T::ok(!str_contains(speedtest_command_for($ok181), 'engine=ookla'), 'an agent without the engine keeps iperf3');
T::ok(!str_contains(speedtest_command_for($mk(['agent_version' => '1.8.2'])), 'engine=ookla'), 'a firewall not enabled for Ookla keeps iperf3');
speedtest_ookla_accept_terms(false);
T::ok(!str_contains(speedtest_command_for($ok182), 'engine=ookla'), 'withdrawing acceptance stops Ookla tests');

// ===========================================================================
T::group('Manager: storing results honestly');

$fw = $mk([]);
$oid = speedtest_ingest((int)$fw['id'], ['download_mbps' => 9300, 'upload_mbps' => 9200, 'engine' => 'ookla', 'jitter_ms' => 0.4,
    'packet_loss' => 0, 'result_url' => 'https://www.speedtest.net/result/c/0000-test', 'server' => 'OOKLA-x'], ['id' => 9, 'description' => 'On-demand']);
$orow = db()->query("SELECT * FROM bandwidth_tests WHERE id = $oid")->fetch(PDO::FETCH_ASSOC);
T::ok($orow['engine'] === 'ookla' && $orow['result_url'] === 'https://www.speedtest.net/result/c/0000-test', 'Ookla engine and result link stored');
$oid2 = speedtest_ingest((int)$fw['id'], ['download_mbps' => 1, 'engine' => 'ookla', 'result_url' => 'https://evil.example/x'], ['id' => 10]);
T::eq(null, db()->query("SELECT result_url FROM bandwidth_tests WHERE id = $oid2")->fetchColumn(), 'only speedtest.net result links are kept');
db()->exec("DELETE FROM bandwidth_tests WHERE id IN ($oid, $oid2)");

$nullId = $id = speedtest_ingest((int)$fw['id'], ['download_mbps' => 9400, 'upload_mbps' => null, 'upload_error' => 'upload leg did not complete',
    'ping_ms' => 2.3, 'server' => 'WAN-iperf.dc.example.net', 'streams' => 32, 'duration_s' => 15, 'iperf_version' => '3.22',
    'local_cpu_percent' => 41], ['id' => 1, 'description' => 'Automatic scheduled speedtest (every 4h)']);
$row = db()->query("SELECT * FROM bandwidth_tests WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
T::eq('completed', $row['test_status'], 'stored as completed');
T::eq(null, $row['upload_speed'], 'a missing upload is NULL, not 0');
T::eq('scheduled', $row['test_type'], 'a scheduled run is labelled scheduled');
T::eq('32', (string)$row['streams'], 'streams stored');
T::ok(str_contains((string)$row['error_message'], 'upload leg'), 'the partial failure is recorded');

$id = speedtest_ingest((int)$fw['id'], ['error' => 'iperf3 test failed - all servers busy or unreachable'], ['id' => 2, 'description' => 'On-demand bandwidth test via iperf3', 'queued_by_user_id' => 5]);
$row = db()->query("SELECT * FROM bandwidth_tests WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
T::ok($row['test_status'] === 'failed' && $row['download_speed'] === null, 'a failed test is stored as failed, with no speeds');
T::eq('manual', $row['test_type'], 'an operator run is labelled manual');

db()->prepare("INSERT INTO bandwidth_tests (firewall_id, test_type, test_status) VALUES (?, 'manual', 'running')")->execute([$fw['id']]);
$runId = (int)db()->lastInsertId();
$id = speedtest_ingest((int)$fw['id'], ['download_mbps' => 8000, 'upload_mbps' => 7900], ['id' => 3, 'description' => "Bandwidth test via iperf3 (ID: $runId)"]);
T::eq($runId, $id, "the 'running' row is completed instead of a second row being added");
T::eq('completed', db()->query("SELECT test_status FROM bandwidth_tests WHERE id = $runId")->fetchColumn(), 'and it is no longer running');

$id = speedtest_ingest((int)$fw['id'], ['download_mbps' => 4980, 'upload_mbps' => null, 'streams' => 32, 'duration_s' => 10,
    'local_cpu_percent' => 625, 'remote_cpu_percent' => 30], ['id' => 4, 'description' => 'On-demand bandwidth test via iperf3']);
T::eq('625', (string)db()->query("SELECT local_cpu_percent FROM bandwidth_tests WHERE id = " . (int)$id)->fetchColumn(),
      'a multi-thread CPU figure above 255 is stored (it overflowed TINYINT and lost the result)');
db()->exec('DELETE FROM bandwidth_tests WHERE id = ' . (int)$id);

// ===========================================================================
T::group('Results API');

db()->prepare('UPDATE firewalls SET wan_link_down_mbps = 10000, wan_link_up_mbps = 10000 WHERE id = ?')->execute([$fw['id']]);
// Its own minute, so it is not averaged together with the later result.
db()->exec("UPDATE bandwidth_tests SET tested_at = NOW() - INTERVAL 2 HOUR WHERE id = $nullId");
db()->prepare('INSERT INTO users (username, password, role, is_active) VALUES (?,?,?,1)')->execute(['__st_ro' . bin2hex(random_bytes(3)), 'x', 'readonly']);
$uid = (int)db()->lastInsertId(); $users[] = $uid;
$r = call_endpoint('api/get_speedtest_results.php', ['method' => 'GET', 'get' => ['firewall_id' => $fw['id'], 'hours' => 24],
    'session' => ['user_id' => $uid, 'username' => 'ro', 'role' => 'readonly', 'session_version' => 0,
                  'login_time' => time(), 'last_activity' => time(), 'last_regenerated' => time()]]);
$j = $r['json'];
T::ok(is_array($j) && $j['success'], 'the API answers (' . $r['status'] . ')');
T::ok(in_array(null, $j['upload'], true), 'an unmeasured upload is null in the series (a gap, not 0)');
T::ok($j['stats']['upload']['low'] !== 0.0 && $j['stats']['upload']['low'] !== 0, 'and does not drag the upload low to 0');
T::eq(10000, $j['link']['down_mbps'], 'the provisioned link speed is returned');
T::eq(1, $j['failed_tests'], 'failed tests are counted, not hidden');
$now = db()->query('SELECT DATE_FORMAT(NOW(), "%Y-%m-%d %H")')->fetchColumn();
T::ok(str_starts_with((string)end($j['labels']), (string)$now), 'labels are local time, not shifted 5 h (' . end($j['labels']) . ')');

exit(T::summary());
