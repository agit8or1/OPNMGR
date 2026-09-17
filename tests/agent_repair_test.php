<?php
/**
 * The Repair Agent button, and the three ways it was broken.
 *
 * Run with: php tests/agent_repair_test.php
 *
 * Reported as a 403, then - once the CSRF token was sent - as a 500. Behind
 * those were three separate defects, in increasing order of seriousness:
 *
 * 1. It wrote to `activity_log`, a table that does not exist in this schema and
 *    appears in no migration. Every successful request ended in a fatal, after
 *    the repair had already been launched.
 *
 * 2. The script ran `sudo -u www-data` while already running as www-data, and
 *    no sudoers rule permitted it. Worse, the connectivity test folded stderr
 *    into the stream it matched:
 *
 *        if sudo -u www-data ssh ... 'echo "Connected"' 2>&1 | grep -q "Connected"
 *
 *    sudo's denial message quotes the command it refused, and that command
 *    contains echo "Connected" - so the check matched the text of its own
 *    failure and logged "[SUCCESS] SSH connection successful" while nothing had
 *    connected. Any diagnosis resting on that line was worthless.
 *
 * 3. The payload installed downloads/tunnel_agent.sh - the legacy standalone
 *    agent, last modified October 2025 - and crontabbed it every two minutes.
 *    On a fleet running the 1.6.x plugin agent that is not a repair but a
 *    second, obsolete agent checking in alongside the real one. The only reason
 *    it never happened is that sudo blocked the transfer.
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

$root   = dirname(__DIR__);
$repair = (string) @file_get_contents($root . '/api/repair_agent_ssh.php');
$instal = (string) @file_get_contents($root . '/api/ssh_install_agent.php');

/**
 * Strip comment lines.
 *
 * The file explains at length what it used to do wrong, naming tunnel_agent.sh
 * and quoting the old grep. A "must not contain" assertion against the raw text
 * matches those explanations and fails on the fix's own documentation, so the
 * assertions below run against code only.
 */
$code = function (string $src): string {
    $out = [];
    foreach (explode("\n", $src) as $line) {
        $t = ltrim($line);
        if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '#')
            || str_starts_with($t, '*') || str_starts_with($t, '/*')) {
            continue;
        }
        $out[] = $line;
    }
    return implode("\n", $out);
};
$repairCode = $code($repair);

check('api/repair_agent_ssh.php is readable', $repair !== '');

// 1. The table that never existed.
foreach (['api/repair_agent_ssh.php' => $repair, 'api/ssh_install_agent.php' => $instal] as $name => $src) {
    check("{$name} does not write to activity_log",
        !preg_match('/INTO\s+activity_log/i', $src),
        'the table is in no migration and no schema; the insert is a guaranteed fatal');
    check("{$name} records to audit_log instead",
        (bool) preg_match('/audit_log\(\'agent\.(repair|install)\.ssh\'/', $src));
}

// The schema must genuinely lack it, or the fix above is solving nothing.
$schema = (string) @file_get_contents($root . '/database/schema.sql');
check('activity_log really is absent from the schema',
    !preg_match('/CREATE TABLE.*?`?activity_log`?/i', $schema),
    'if it exists, the original insert was fine and this was the wrong fix');

// 2. sudo, and the check that passed on its own failure.
check('the repair script does not sudo to the user it already runs as',
    !str_contains($repairCode, 'sudo -u www-data'),
    'PHP runs it as www-data; no sudoers rule permits this and none should need to');

check('the connectivity test does not fold stderr into what it matches',
    !preg_match('/ssh[^\n]*2>&1\s*\|\s*grep/', $repairCode),
    "sudo's denial quotes the command, so the marker appears in the failure text");

check('the connectivity test sends stderr to the log',
    str_contains($repair, '2>>"$LOG_FILE"'));

check('the success marker is matched as a whole line',
    str_contains($repair, "grep -qx 'OPNMGR_SSH_OK'"),
    'a substring match is what let the denial message through');

check('the marker is not the word the old test used',
    !preg_match('/grep -q "Connected"/', $repairCode));

// 3. The payload. This is the one that would have done damage.
check('the repair no longer installs the legacy standalone agent',
    !str_contains($repairCode, 'tunnel_agent.sh'),
    'that agent was last touched in October 2025 and is not what the fleet runs');

check('it does not crontab a second agent',
    !preg_match('/crontab[^\n]*tunnel_agent/', $repairCode),
    'two agents checking in is worse than one that is down');

check('it installs the published plugin agent',
    str_contains($repair, 'install_opnmanager_agent.sh')
    && str_contains($repair, 'OPNMGR_BASE_URL'),
    'repair should mean the firewall ends up on the published version');

check('the installer URL comes from configuration',
    str_contains($repair, 'escapeshellarg($agent_url)'),
    'never a hardcoded host');

check('no scp step remains',
    !str_contains($repairCode, 'scp'),
    'a single ssh command needs nothing transferred, and nothing can go stale in transit');

// The endpoint must still refuse an unauthenticated or untokened caller.
check('it still requires a session', str_contains($repair, "isset(\$_SESSION['user_id'])"));
check('it still verifies CSRF', str_contains($repair, 'csrf_verify'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
