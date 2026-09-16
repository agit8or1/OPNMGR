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
 * This suite runs the generated script with curl and configctl stubbed, so both
 * outcomes are exercised here rather than discovered on a customer's box.
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

check('build_restore_command() exists', function_exists('build_restore_command'));

$script = build_restore_command(42, 51, 'tok123');
check('a script was produced', strlen($script) > 200);

// ---------------------------------------------------------------------------
// Run it, with the firewall's tools replaced by stubs.
// ---------------------------------------------------------------------------

$dir = sys_get_temp_dir() . '/opnmgr-restore-test-' . getmypid();
@mkdir($dir . '/bin', 0700, true);

/** Write an executable stub. */
$stub = function (string $name, string $body) use ($dir): void {
    file_put_contents($dir . '/bin/' . $name, "#!/bin/sh\n" . $body . "\n");
    chmod($dir . '/bin/' . $name, 0700);
};

// The temp path the script uses, redirected into our sandbox.
$tmpPath = $dir . '/restore.xml';
$runnable = str_replace('/tmp/opnmgr-restore-42.xml', $tmpPath, $script);
// configctl is referenced by absolute path; point it at the stub.
$runnable = str_replace('/usr/local/sbin/configctl', $dir . '/bin/configctl', $runnable);
// The agent credential files live at absolute paths on a firewall; supply them.
file_put_contents($dir . '/hw', "hw-test\n");
file_put_contents($dir . '/key', "key-test\n");
$runnable = str_replace('/usr/local/etc/opnmanager_hardware_id', $dir . '/hw', $runnable);
$runnable = str_replace('/usr/local/etc/opnmanager_api_key', $dir . '/key', $runnable);

$run = function (string $configctlExit) use ($dir, $runnable, $tmpPath, $stub): array {
    @unlink($tmpPath);
    // curl stub writes a plausible config to the -o target.
    $stub('curl', 'while [ $# -gt 0 ]; do if [ "$1" = "-o" ]; then shift; echo "<opnsense><system/></opnsense>" > "$1"; fi; shift; done; exit 0');
    $stub('configctl', 'exit ' . $configctlExit);

    $path = $dir . '/script.sh';
    file_put_contents($path, $runnable);
    chmod($path, 0700);

    $out = [];
    $rc = 0;
    exec('PATH=' . escapeshellarg($dir . '/bin') . ':/usr/bin:/bin sh ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
    return ['rc' => $rc, 'out' => implode("\n", $out), 'leftover' => is_file($tmpPath)];
};

// --- the restore succeeds ---------------------------------------------------
$ok = $run('0');
check('a successful restore exits 0', $ok['rc'] === 0, 'exit ' . $ok['rc'] . ': ' . $ok['out']);
check('it reports success', stripos($ok['out'], 'Restore applied') !== false, $ok['out']);
check('it leaves no configuration behind on success', !$ok['leftover'],
    'the fetched file contains password hashes and pre-shared keys');

// --- the restore fails ------------------------------------------------------
$bad = $run('3');
check('a failed restore propagates the exit code', $bad['rc'] === 3, 'exit ' . $bad['rc']);
check('a failed restore says why', stripos($bad['out'], 'restore failed') !== false,
    'under set -e the script died before its own error message, so the operator saw nothing');
check('a failed restore leaves no configuration behind', !$bad['leftover'],
    'this is the one that mattered: a full config left in /tmp on the firewall');
check('it does not claim success after failing', stripos($bad['out'], 'Restore applied') === false);

// --- the fetched file is not a configuration --------------------------------
@unlink($tmpPath);
$stub('curl', 'while [ $# -gt 0 ]; do if [ "$1" = "-o" ]; then shift; echo "not a config" > "$1"; fi; shift; done; exit 0');
$stub('configctl', 'echo "configctl should not have been reached"; exit 0');
file_put_contents($dir . '/script.sh', $runnable);
chmod($dir . '/script.sh', 0700);
$out = []; $rc = 0;
exec('PATH=' . escapeshellarg($dir . '/bin') . ':/usr/bin:/bin sh ' . escapeshellarg($dir . '/script.sh') . ' 2>&1', $out, $rc);
$text = implode("\n", $out);

check('a non-configuration is refused', $rc !== 0, 'exit ' . $rc);
check('configctl is never reached for a bad file',
    strpos($text, 'should not have been reached') === false, $text);
check('a refused file is not left behind', !is_file($tmpPath));

// --- the script always installs the cleanup trap ----------------------------
check('the script traps every exit path',
    preg_match('/trap .*rm -f .*EXIT/', $script) === 1,
    'cleanup placed after a command cannot run when set -e ends the script there');

// tidy up
foreach (glob($dir . '/bin/*') ?: [] as $f) { @unlink($f); }
@rmdir($dir . '/bin');
foreach (glob($dir . '/*') ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
@rmdir($dir);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
