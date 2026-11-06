<?php
/**
 * The restore script, run for real against a stub.
 *
 * Restore has never been performed on this installation - audit_log holds no
 * restore entries at all - so the script that would run on a customer's
 * firewall had never been executed by anything. Reading it was not enough:
 *
 *   set -e
 *   configctl firmware restore "$TMP"
 *   RC=$?
 *   rm -f "$TMP"
 *   if [ $RC -ne 0 ]; then ... fi
 *
 * Under `set -e` a non-zero exit ends the script at the configctl line, so the
 * assignment, the message and the cleanup below it never ran. A failed restore
 * therefore printed nothing explaining itself and left the fetched
 * configuration - password hashes, pre-shared keys, RADIUS secrets - sitting in
 * /tmp on the firewall.
 *
 * This suite runs the generated script with curl and the platform's install
 * step stubbed, so both outcomes are exercised here rather than discovered on a
 * customer's box. Since 3.79 the install step is platform specific - the
 * configctl action the script used never existed in OPNsense core - so the
 * suite runs once per platform, and also proves a configuration of the other
 * product is refused on the firewall itself.
 */

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) { $passed++; return; }
    $failed++;
    echo "FAIL: {$what}\n";
    if ($detail !== '') { echo "      {$detail}\n"; }
}

$root = dirname(__DIR__);
require_once $root . '/inc/server_identity.php';
require_once $root . '/inc/config_restore.php';
require_once $root . '/inc/platform/platform.php';

check('build_restore_command() exists', function_exists('build_restore_command'));

/**
 * @param OpnmgrPlatformAdapter $adapter
 * @param string $ownRoot   root element of this platform's config
 * @param string $otherRoot root of the other product
 * @param array  $paths     absolute paths in the script to redirect to stubs
 */
function restore_suite(OpnmgrPlatformAdapter $adapter, string $ownRoot, string $otherRoot, array $paths): void
{
    $label = $adapter->label();
    $script = build_restore_command(42, 51, 'tok123', $adapter);
    check("[$label] a script was produced", strlen($script) > 200);
    check("[$label] it never uses the non-existent configctl restore action",
        strpos($script, 'configctl firmware restore') === false);

    $dir = sys_get_temp_dir() . '/opnmgr-restore-test-' . getmypid() . '-' . $ownRoot;
    @mkdir($dir . '/bin', 0700, true);
    $stub = function (string $name, string $body) use ($dir): void {
        file_put_contents($dir . '/bin/' . $name, "#!/bin/sh\n" . $body . "\n");
        chmod($dir . '/bin/' . $name, 0700);
    };

    $tmpPath = $dir . '/restore.xml';
    $runnable = str_replace('/tmp/opnmgr-restore-42.xml', $tmpPath, $script);
    foreach ($paths as $from => $to) {
        $runnable = str_replace($from, str_replace('{dir}', $dir, $to), $runnable);
    }
    file_put_contents($dir . '/hw', "hw-test\n");
    file_put_contents($dir . '/key', "key-test\n");
    $runnable = str_replace('/usr/local/etc/opnmanager_hardware_id', $dir . '/hw', $runnable);
    $runnable = str_replace('/usr/local/etc/opnmanager_api_key', $dir . '/key', $runnable);
    file_put_contents($dir . '/script.sh', $runnable);
    chmod($dir . '/script.sh', 0700);

    $run = function (string $fetched, string $installExit) use ($dir, $tmpPath, $stub): array {
        @unlink($tmpPath);
        @unlink($dir . '/installed');
        $stub('curl', 'while [ $# -gt 0 ]; do if [ "$1" = "-o" ]; then shift; echo ' . escapeshellarg($fetched)
            . ' > "$1"; fi; shift; done; exit 0');
        // The install step: records that it ran and which file it was given.
        $stub('php', 'echo "$OPNMGR_CFG" > ' . escapeshellarg($dir . '/installed') . '; exit ' . $installExit);
        $stub('reload_all', 'exit 0');
        $out = []; $rc = 0;
        exec('PATH=' . escapeshellarg($dir . '/bin') . ':/usr/bin:/bin sh ' . escapeshellarg($dir . '/script.sh') . ' 2>&1', $out, $rc);
        return ['rc' => $rc, 'out' => implode("\n", $out), 'leftover' => is_file($tmpPath),
                'installed' => is_file($dir . '/installed') ? trim(file_get_contents($dir . '/installed')) : null];
    };

    $own = "<{$ownRoot}><system/></{$ownRoot}>";

    $ok = $run($own, '0');
    check("[$label] a successful restore exits 0", $ok['rc'] === 0, 'exit ' . $ok['rc'] . ': ' . $ok['out']);
    check("[$label] it reports success", stripos($ok['out'], 'Restore applied') !== false, $ok['out']);
    check("[$label] the platform install step received the fetched file", $ok['installed'] === $tmpPath,
        var_export($ok['installed'], true));
    check("[$label] it leaves no configuration behind on success", !$ok['leftover']);

    $bad = $run($own, '3');
    check("[$label] a failed restore propagates the exit code", $bad['rc'] === 3, 'exit ' . $bad['rc'] . ': ' . $bad['out']);
    check("[$label] a failed restore says why", stripos($bad['out'], 'restore failed') !== false, $bad['out']);
    check("[$label] a failed restore leaves no configuration behind", !$bad['leftover']);
    check("[$label] it does not claim success after failing", stripos($bad['out'], 'Restore applied') === false);

    $junk = $run('not a config', '0');
    check("[$label] a non-configuration is refused", $junk['rc'] !== 0, 'exit ' . $junk['rc']);
    check("[$label] the install step is never reached for a bad file", $junk['installed'] === null);
    check("[$label] a refused file is not left behind", !$junk['leftover']);

    $cross = $run("<{$otherRoot}><system/></{$otherRoot}>", '0');
    check("[$label] the other product's configuration is refused on the firewall", $cross['rc'] !== 0, $cross['out']);
    check("[$label] and never reaches the install step", $cross['installed'] === null);

    check("[$label] the script traps every exit path", preg_match('/trap .*rm -f .*EXIT/', $script) === 1);

    foreach (glob($dir . '/bin/*') ?: [] as $f) { @unlink($f); }
    @rmdir($dir . '/bin');
    foreach (glob($dir . '/*') ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
    @rmdir($dir);
}

restore_suite(new OpnmgrOpnsenseAdapter(), 'opnsense', 'pfsense', [
    '/usr/local/etc/rc.reload_all' => '{dir}/bin/reload_all',
    'cd /usr/local/opnsense/mvc'   => 'cd {dir}',
    '/usr/local/bin/php'           => '{dir}/bin/php',
]);
restore_suite(new OpnmgrPfsenseAdapter(), 'pfsense', 'opnsense', [
    '/etc/rc.reload_all' => '{dir}/bin/reload_all',
    '/usr/local/bin/php' => '{dir}/bin/php',
]);

$unknown = build_restore_command(42, 51, 'tok123', new OpnmgrUnknownAdapter());
check('an unknown platform gets a script that refuses rather than guesses',
    strpos($unknown, 'not implemented for this platform') !== false);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
