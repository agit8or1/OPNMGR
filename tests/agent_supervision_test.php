<?php
/**
 * A dead agent has to come back by itself.
 *
 * Run with: php tests/agent_supervision_test.php
 *
 * The agent's self-update path ends in "rm -f PID_FILE; exit 0 - let rc.d
 * restart us". Nothing ever restarted it. rc.d launched daemon(8) without -r,
 * so the supervisor was not supervising, and watchdog.sh - written for exactly
 * this - was copied onto every firewall and never scheduled. The only thing
 * that ever asked for its cron entry was a comment in its own header.
 *
 * So every way the agent could stop was permanent, and the only recovery was
 * console access to a firewall whose whole point was not needing any. On
 * 2026-09-16 fw51 stopped at 12:02:40 after a clean run of 200-response
 * check-ins and stayed down; fw48 had done the same two days earlier and came
 * back only when a queued reinstall finally ran, 12 hours after it was sent.
 *
 * This test reads the shipped scripts. It asserts the three links in that chain
 * exist, not that any particular firewall recovered.
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

$root      = dirname(__DIR__);
$src       = $root . '/plugin/os-opnmanager-agent/src';
$rc        = (string)@file_get_contents($src . '/etc/rc.d/opnmanager_agent');
$agent     = (string)@file_get_contents($src . '/opnsense/scripts/OPNsense/OPNManagerAgent/agent.sh');
$watchdog  = (string)@file_get_contents($src . '/opnsense/scripts/OPNsense/OPNManagerAgent/watchdog.sh');
$installer = (string)@file_get_contents($root . '/downloads/plugins/install_opnmanager_agent.sh');
$uninstall = (string)@file_get_contents($root . '/downloads/plugins/uninstall_opnmanager_agent.sh');

check('agent source tree is readable', $rc !== '' && $agent !== '' && $watchdog !== '');
check('installer is readable', $installer !== '');

// 1. daemon(8) must supervise. Without -r the agent's own "let rc.d restart us"
//    comment describes something that does not happen.
check('rc.d runs daemon with -r', (bool)preg_match('/daemon\s+(-\w+\s+)*-r\b/', $rc),
    'a self-update or a crash exits the agent; -r is what brings it back');
check('rc.d sets a restart delay', (bool)preg_match('/-R\s*\d+/', $rc),
    'without -R a failing start is a hot loop');
check('rc.d records the supervisor pid separately', str_contains($rc, '-P '),
    'stop must be able to kill the supervisor, not just the agent');

// 2. Stopping must kill the supervisor first, or it restarts what we just killed.
$stopBody = '';
if (preg_match('/opnmanager_agent_stop\(\)\s*\{(.*?)\n\}/s', $rc, $m)) {
    $stopBody = $m[1];
}
check('stop targets the supervisor', str_contains($stopBody, 'supervisor_pidfile'),
    'service stop would otherwise be undone by the supervisor within seconds');
$supPos = strpos($stopBody, 'supervisor_pidfile');
$agtPos = strpos($stopBody, '${pidfile}');
check('stop kills the supervisor before the agent',
    $supPos !== false && $agtPos !== false && $supPos < $agtPos);

// 3. The watchdog must actually be scheduled by the installer.
check('installer installs a crontab entry for the watchdog',
    str_contains($installer, 'crontab -') && str_contains($installer, 'watchdog.sh'),
    'it shipped unscheduled for its entire existence');
// The schedule and the path are assembled separately in the installer, so check
// both: a */5 entry pointing somewhere else would schedule nothing useful.
check('watchdog cron entry runs every 5 minutes',
    (bool)preg_match('#\*/5 \* \* \* \*#', $installer));
check('the scheduled command is the watchdog',
    (bool)preg_match('#WATCHDOG="[^"]*OPNManagerAgent/watchdog\.sh"#', $installer)
    && (bool)preg_match('#\*/5 \* \* \* \* \$\{WATCHDOG\}#', $installer));
check('installer does not write /etc/crontab',
    !preg_match('#>>?\s*/etc/crontab#', $installer),
    'OPNsense regenerates it from config.xml and would drop the entry');
check('installing twice does not duplicate the entry',
    (bool)preg_match('/crontab -l[^\n]*\|[^\n]*grep -v[^\n]*watchdog\.sh/', $installer));
check('uninstaller removes the cron entry',
    (bool)preg_match('/crontab -l[^\n]*grep -v[^\n]*watchdog\.sh[^\n]*crontab -/', $uninstall),
    'cron would keep restarting a service whose files are gone');

// 4. The installer must recover a dead agent, not only refresh a live one.
check('installer restarts the agent unconditionally',
    !preg_match('/if\s+service opnmanager_agent status/', $installer),
    'it restarted only an agent that was already running - the one case needing no help');
check('the restart outlives the agent that dispatched it',
    str_contains($installer, 'nohup') && (bool)preg_match('/sleep \d+;\s*service opnmanager_agent restart/', $installer),
    'restarting synchronously kills the process that reports the command result');

// 5. A supervised agent must not exit on a condition that will not change by
//    itself, or -r turns "disabled" into a restart every few seconds.
check('agent waits for configuration instead of exiting',
    (bool)preg_match('/while \[ "\$ENABLED" != "1" \] \|\| \[ -z "\$SERVER_URL" \]/', $agent),
    'exit 0 under daemon -r is a restart loop');
check('agent no longer exits when disabled',
    !preg_match('/log_message "Agent is disabled in configuration"\s*\n\s*exit 0/', $agent));
check('agent still exits on self-update so the supervisor reloads it',
    str_contains($agent, 'Restarting to use new version'),
    'this is the path that made silence permanent, and is now the intended one');

// 6. The watchdog runs every five minutes now, so a false "stuck" verdict would
//    restart a healthy agent forever. It must judge by check-in age.
check('watchdog measures the age of the last successful check-in',
    str_contains($watchdog, 'Check-in successful') && str_contains($watchdog, "date -j"),
    'the old test was "is a success in the last 20 log lines", which a busy agent fails while healthy');
check('watchdog threshold allows for error backoff',
    (bool)preg_match('/threshold\D+900/', $watchdog),
    'the agent backs off to 300s per check-in; a lost uplink must not trigger restarts');
check('watchdog leaves a deliberately disabled agent alone',
    str_contains($watchdog, '<enabled>'),
    'a disabled agent logs no check-ins on purpose');
check('watchdog checks the supervisor too',
    str_contains($watchdog, 'SUPERVISOR_PIDFILE'),
    'an agent alive without its supervisor is one crash from permanent silence');
check('watchdog tolerates an agent that has not checked in yet',
    str_contains($watchdog, 'if [ -z "$last_ok" ]'),
    'a freshly started agent has no timestamp and must not be restarted for it');

// 7. The package must contain the watchdog, or scheduling it points at nothing.
$version = null;
if (preg_match("/define\('AGENT_VERSION',\s*'([^']+)'\)/", (string)@file_get_contents($root . '/inc/version.php'), $m)) {
    $version = $m[1];
}
check('AGENT_VERSION is readable', $version !== null);
if ($version !== null) {
    $tarball = $root . "/downloads/plugins/os-opnmanager-agent-{$version}.tar.gz";
    check("released package {$version} exists", file_exists($tarball));
    if (file_exists($tarball)) {
        $listing = (string)shell_exec('tar tzf ' . escapeshellarg($tarball) . ' 2>/dev/null');
        check('package ships watchdog.sh', str_contains($listing, 'watchdog.sh'),
            'the cron entry the installer writes would point at a missing file');
        check('package ships the supervising rc.d script', str_contains($listing, 'etc/rc.d/opnmanager_agent'));
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
