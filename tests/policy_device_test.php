<?php
/**
 * Policy apply / confirm / rollback scripts, run for real under /bin/sh with
 * stubbed binaries (curl, sha256, php, daemon, reload). No database and no
 * firewall are involved.
 *
 * Proves, once per platform:
 *   - a confirmed apply stays applied after the deadline
 *   - an unconfirmed apply is reverted by the detached timer
 *   - a reboot inside / after the window is recovered by the boot hook
 *   - fingerprint mismatch, wrong root and a tampered artifact are refused
 *     before anything is installed
 *   - an install failure reverts immediately
 *   - a second apply is refused while one is armed
 *   - the scripts contain none of the other product's tools
 *
 *   DB_NAME=opnsense_fw_test_policy php tests/policy_device_test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/policy/device.php';

function dev_suite(OpnmgrPlatformAdapter $adapter, string $own, string $other, array $installPaths): void {
    $label = $adapter->label();
    T::group("Device scripts: {$label}");
    $dir = sys_get_temp_dir() . '/opnmgr-policy-dev-' . getmypid() . '-' . $own;
    exec('rm -rf ' . escapeshellarg($dir));
    mkdir($dir . '/bin', 0700, true);
    $stub = function (string $name, string $body) use ($dir) {
        file_put_contents("{$dir}/bin/{$name}", "#!/bin/sh\n{$body}\n");
        chmod("{$dir}/bin/{$name}", 0700);
    };
    $cfg = "{$dir}/config.xml";
    $state = "{$dir}/state";
    $over = ['config' => $cfg, 'state_dir' => $state, 'hook' => "{$dir}/hook/60-recover",
             'path' => "{$dir}/bin:/usr/bin:/bin", 'hw' => "{$dir}/hw", 'key' => "{$dir}/key", 'url' => 'https://manager.invalid/x'];
    file_put_contents("{$dir}/hw", "hw\n");
    file_put_contents("{$dir}/key", "key\n");

    $stub('sha256', '[ "$1" = "-q" ] && shift; [ -f "$1" ] || exit 1; sha256sum "$1" | cut -d" " -f1');
    $stub('curl', 'while [ $# -gt 0 ]; do if [ "$1" = "-o" ]; then shift; cp ' . escapeshellarg("{$dir}/artifact.xml") . ' "$1"; fi; shift; done; exit 0');
    // The platform install step: records which file it got and installs it.
    $stub('php', 'echo "$OPNMGR_CFG" >> ' . escapeshellarg("{$dir}/installed.log")
        . '; rc=$(cat ' . escapeshellarg("{$dir}/install_rc") . ' 2>/dev/null || echo 0); [ "$rc" = 0 ] && cp "$OPNMGR_CFG" '
        . escapeshellarg($cfg) . '; exit $rc');
    $stub('reload_all', 'exit 0');
    $stub('logger', 'exit 0');
    $daemonOn = 'shift; "$@" >/dev/null 2>&1 &';
    $stub('daemon', $daemonOn);

    $fix = fn(string $s) => str_replace('{dir}', $dir, strtr($s, $installPaths));
    $baseXml = "<?xml version=\"1.0\"?>\n<{$own}><system><hostname>a</hostname></system></{$own}>\n";
    $newXml  = "<?xml version=\"1.0\"?>\n<{$own}><system><hostname>b</hostname></system></{$own}>\n";

    $run = function (string $script) use ($dir): array {
        file_put_contents("{$dir}/run.sh", $script);
        $out = [];
        $rc = 0;
        exec('sh ' . escapeshellarg("{$dir}/run.sh") . ' 2>&1', $out, $rc);
        return ['rc' => $rc, 'out' => implode("\n", $out)];
    };
    $reset = function (string $artifact, string $installRc = '0') use ($dir, $cfg, $baseXml, $state) {
        exec('rm -rf ' . escapeshellarg($state) . ' ' . escapeshellarg("{$dir}/hook"));
        @unlink("{$dir}/installed.log");
        file_put_contents($cfg, $baseXml);
        file_put_contents("{$dir}/artifact.xml", $artifact);
        file_put_contents("{$dir}/install_rc", $installRc);
    };
    $apply = function (string $artifact, ?string $base = null, ?string $art = null, int $id = 7) use ($adapter, $over, $fix, $baseXml): string {
        return $fix(policy_build_apply_script($adapter, [
            'target_id' => $id, 'firewall_id' => 3, 'token' => str_repeat('ab', 32),
            'base_sha' => $base ?? hash('sha256', $baseXml), 'artifact_sha' => $art ?? hash('sha256', $artifact),
            'window' => 60, 'window_override' => 3,
        ], $over));
    };
    $installs = fn() => is_file("{$dir}/installed.log") ? array_filter(explode("\n", trim(file_get_contents("{$dir}/installed.log")))) : [];
    $waitFor = function (callable $cond, int $secs = 12) { for ($i = 0; $i < $secs * 10; $i++) { if ($cond()) { return true; } usleep(100000); } return false; };

    $apScript = $apply($newXml);
    foreach ($adapter->foreignTools() as $tool) {
        T::ok(stripos($apScript, $tool) === false, "apply script does not use {$tool}");
    }

    // --- confirm disarms ----------------------------------------------------
    $reset($newXml);
    $r = $run($apScript);
    T::eq('ok', policy_parse_marker_line($r['out'], 'APPLY'), 'apply reports ok');
    T::eq($newXml, file_get_contents($cfg), 'the artifact is installed');
    T::ok(is_file("{$state}/policy-armed"), 'the recovery timer is armed');
    T::ok(is_file("{$state}/policy-7-pre.xml") && file_get_contents("{$state}/policy-7-pre.xml") === $baseXml, 'previous config saved');
    T::ok(is_file("{$dir}/hook/60-recover"), 'boot hook installed');
    T::ok(!is_file("{$state}/policy-7-new.xml"), 'the fetched artifact is not left behind');
    $busy = $run($apply($newXml, null, null, 8));
    T::eq('refused_busy', policy_parse_marker_line($busy['out'], 'APPLY'), 'a second apply is refused while one is armed');
    $c = $run($fix(policy_build_confirm_script($adapter, 7, $over)));
    T::eq('ok', policy_parse_marker_line($c['out'], 'CONFIRM'), 'confirm reports ok');
    sleep(5);
    T::eq($newXml, file_get_contents($cfg), 'confirmed config is still installed after the deadline');
    T::eq(1, count($installs()), 'nothing was reinstalled');
    $c2 = $run($fix(policy_build_confirm_script($adapter, 7, $over)));
    T::eq('not_armed', policy_parse_marker_line($c2['out'], 'CONFIRM'), 'a repeated confirm says not armed');

    // --- missing confirm reverts --------------------------------------------
    $reset($newXml);
    $r = $run($apScript);
    T::eq('ok', policy_parse_marker_line($r['out'], 'APPLY'), 'apply ok (no confirm will follow)');
    T::ok($waitFor(fn() => is_file("{$state}/policy-7-reverted")), 'the timer reverts when no confirm arrives');
    T::eq($baseXml, file_get_contents($cfg), 'the previous configuration is back');
    $ins = array_values($installs());
    T::eq("{$state}/policy-7-pre.xml", $ins[1] ?? null, 'the revert installed the saved file through the platform install step');
    $late = $run($fix(policy_build_confirm_script($adapter, 7, $over)));
    T::eq('already_reverted', policy_parse_marker_line($late['out'], 'CONFIRM'), 'a confirm after the revert is reported as too late');

    // --- explicit rollback --------------------------------------------------
    $reset($newXml);
    $run($apScript);
    $rb = $run($fix(policy_build_rollback_script($adapter, 7, $over)));
    T::eq('ok', policy_parse_marker_line($rb['out'], 'ROLLBACK'), 'rollback reverts immediately');
    T::eq($baseXml, file_get_contents($cfg), 'and the previous configuration is back');

    // --- reboot inside / after the window -------------------------------------
    $stub('daemon', 'exit 0');   // the timer process is lost, as in a reboot
    file_put_contents("{$dir}/bin/nohup", "#!/bin/sh\nexit 0\n");
    chmod("{$dir}/bin/nohup", 0700);
    $reset($newXml);
    $run($apScript);
    sleep(4);
    T::eq($newXml, file_get_contents($cfg), 'with the timer lost, nothing reverts by itself');
    $run('sh ' . escapeshellarg("{$dir}/hook/60-recover") . ' start');
    T::eq($baseXml, file_get_contents($cfg), 'the boot hook reverts once the deadline has passed');
    unlink("{$dir}/bin/nohup");
    $reset($newXml);
    $run($apScript);
    $stub('daemon', $daemonOn);
    $run('sh ' . escapeshellarg("{$dir}/hook/60-recover") . ' start');
    T::eq($newXml, file_get_contents($cfg), 'a boot inside the window does not revert early');
    T::ok($waitFor(fn() => is_file("{$state}/policy-7-reverted")), 'but re-arms the timer, which reverts at the deadline');

    // --- refusals ------------------------------------------------------------
    $reset($newXml);
    $r = $run($apply($newXml, str_repeat('0', 64)));
    T::eq('refused_fingerprint', policy_parse_marker_line($r['out'], 'APPLY'), 'fingerprint mismatch is refused');
    T::ok(!$installs() && !is_file("{$state}/policy-armed"), 'nothing installed or armed');

    $foreign = "<?xml version=\"1.0\"?>\n<{$other}><system/></{$other}>\n";
    $reset($foreign);
    $r = $run($apply($foreign));
    T::eq('refused_root', policy_parse_marker_line($r['out'], 'APPLY'), "a <{$other}> configuration is refused");
    T::ok(!$installs() && file_get_contents($cfg) === $baseXml, 'and never installed');

    $reset($newXml . '<!-- tampered -->');
    $r = $run($apply($newXml . '<!-- tampered -->', null, hash('sha256', $newXml)));
    T::eq('refused_artifact', policy_parse_marker_line($r['out'], 'APPLY'), 'an artifact whose sha256 differs is refused');
    T::ok(!$installs(), 'and never installed');

    $reset($newXml, '3');
    $r = $run($apScript);
    T::eq('install_failed', policy_parse_marker_line($r['out'], 'APPLY'), 'an install failure is reported');
    T::ok(is_file("{$state}/policy-7-reverted") || is_file("{$state}/policy-7-revert-failed"), 'and the revert ran at once');
    T::ok(!is_file("{$state}/policy-armed"), 'nothing is left armed');

    exec('rm -rf ' . escapeshellarg($dir));
}

dev_suite(new OpnmgrOpnsenseAdapter(), 'opnsense', 'pfsense', [
    '/usr/local/etc/rc.reload_all' => '{dir}/bin/reload_all',
    'cd /usr/local/opnsense/mvc'   => 'cd {dir}',
    '/usr/local/bin/php'           => '{dir}/bin/php',
]);
dev_suite(new OpnmgrPfsenseAdapter(), 'pfsense', 'opnsense', [
    '/etc/rc.reload_all' => '{dir}/bin/reload_all',
    '/usr/local/bin/php' => '{dir}/bin/php',
]);

T::group('Unknown platform');
T::ok(str_contains(policy_build_apply_script(new OpnmgrUnknownAdapter(), ['target_id' => 1, 'firewall_id' => 1,
    'token' => str_repeat('a', 64), 'base_sha' => str_repeat('a', 64), 'artifact_sha' => str_repeat('a', 64), 'window' => 60]),
    'OPNMGR_POLICY_APPLY=unsupported'), 'an unverified platform gets a script that refuses');

exit(T::summary());
