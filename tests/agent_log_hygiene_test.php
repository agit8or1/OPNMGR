<?php
/**
 * One log entry is one line.
 *
 * Run with: php tests/agent_log_hygiene_test.php
 *
 * The agent logged the full body of every queued command, raw. A scripted
 * command - the nightly backup, an install, any of the probes in scripts/ -
 * therefore wrote dozens of unprefixed lines into the log that read like entries
 * and were not, and the installer's own output was appended into the same file.
 * `tail` on the log returned script text instead of what the agent had been
 * doing, which is exactly what it was consulted for.
 *
 * That was not only cosmetic. The watchdog decides whether the agent is healthy
 * by grepping this file for a recent successful check-in, so a command body
 * could push real entries out of its window or contribute a matching line of its
 * own. Command bodies can also carry credentials, and the result is reported to
 * the manager regardless, which is where it belongs.
 *
 * This test extracts the agent's own logging functions and runs them.
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

$root  = dirname(__DIR__);
$agent = $root . '/plugin/os-opnmanager-agent/src/opnsense/scripts/OPNsense/OPNManagerAgent/agent.sh';
$src   = (string)@file_get_contents($agent);

check('agent.sh is readable', $src !== '');
if ($src === '') { echo "\n{$passed} passed, {$failed} failed\n"; exit(1); }

// --- static assertions: the raw body must not reach the log ------------------

check('queued command bodies are summarised, not logged whole',
    !str_contains($src, 'log_message "Executing command $cmd_id: $cmd_data"'),
    'this single call is what produced most of the noise');
check('a command_summary helper exists', str_contains($src, 'command_summary()'));
check('the command path uses it',
    (bool)preg_match('/log_message "Executing command \$cmd_id \(\$\(command_summary "\$cmd_data"\)\)"/', $src));
check('update commands are summarised too',
    (bool)preg_match('/command_summary "\$update_cmd"/', $src));

// The installer transcript belongs in its own file.
check('an update transcript file is declared', str_contains($src, 'UPDATE_LOG_FILE='));
check('installer output no longer appends to the agent log',
    !preg_match('/\$update_cmd >> \$LOG_FILE/', $src),
    'a hundred lines of install chatter buried the entries that mattered');
check('installer output goes to the transcript file',
    substr_count($src, '$update_cmd >> $UPDATE_LOG_FILE') === 2,
    'both the generic update path and the agent self-update path');
check('the self-update path no longer discards its output',
    !preg_match('/nohup sh -c "\$update_cmd" > \/dev\/null/', $src),
    'the 1.6.6 upgrade left no transcript anywhere');
check('the transcript file is rotated', str_contains($src, 'rotate_one_log "$UPDATE_LOG_FILE"'),
    'a second unbounded log is not an improvement');
check('the agent log is still rotated', str_contains($src, 'rotate_one_log "$LOG_FILE"'));

// --- behavioural: extract the functions and run them -------------------------

if (!function_exists('shell_exec')) {
    echo "\nshell_exec unavailable, skipping behavioural checks\n";
    echo "\n{$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}

$tmp     = sys_get_temp_dir() . '/opnmgr-loghyg-' . getmypid();
@mkdir($tmp);
$fns     = $tmp . '/fns.sh';
$logFile = $tmp . '/agent.log';

shell_exec(sprintf(
    "sed -n '/^rotate_one_log() {/,/^}/p;/^rotate_log() {/,/^}/p;/^log_message() {/,/^}/p;/^command_summary() {/,/^}/p' %s > %s",
    escapeshellarg($agent), escapeshellarg($fns)
));
check('the logging functions were extracted', (int)@filesize($fns) > 300);

// A realistic multi-line command, of the shape the manager actually queues.
$body = "#!/bin/sh\nHW_FILE=/usr/local/etc/opnmanager_hardware_id\n"
      . "API_KEY=\$(cat /usr/local/etc/opnmanager_api_key)\n"
      . "curl -sS -F \"api_key=\$API_KEY\" https://example.invalid/upload\nexit \$?";

$runner = $tmp . '/run.sh';
file_put_contents($runner, <<<SH
AGENT_VERSION="9.9.9"
LOG_FILE="{$logFile}"
UPDATE_LOG_FILE="{$tmp}/update.log"
MAX_LOG_SIZE=10485760
. "{$fns}"
BODY=\$(cat "{$tmp}/body.txt")
log_message "Check-in successful"
log_message "Executing command 9999 (\$(command_summary "\$BODY"))"
log_message "Check-in successful"
SH);
file_put_contents($tmp . '/body.txt', $body);
shell_exec('sh ' . escapeshellarg($runner) . ' 2>/dev/null');

$log   = (string)@file_get_contents($logFile);
$lines = array_values(array_filter(explode("\n", $log), fn($l) => $l !== ''));

check('three log calls produce exactly three lines', count($lines) === 3,
    'got ' . count($lines) . ' - a 5-line command body used to add 5 more');

$unprefixed = 0;
foreach ($lines as $l) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \[/', $l)) { $unprefixed++; }
}
check('every line carries a timestamp prefix', $unprefixed === 0,
    "{$unprefixed} line(s) without one would read as entries while being script text");

check('a watchdog-style grep still finds both check-ins',
    substr_count($log, 'Check-in successful') === 2,
    'the health check the watchdog depends on must survive a logged command');

$expectLines = substr_count($body, "\n") + 1;
$expectBytes = strlen($body);
check('the summary reports the real size of the body',
    str_contains($log, "{$expectLines} line(s)") && str_contains($log, "{$expectBytes} byte(s)"),
    "expected {$expectLines} line(s) / {$expectBytes} byte(s) in: {$lines[1]}");
check('the summary is truncated', str_contains($log, '...'));
check('the excerpt is bounded to roughly 100 characters',
    strlen($lines[1]) < 200, 'entry was ' . strlen($lines[1]) . ' chars');

check('the body does not appear in the log verbatim',
    !str_contains($log, "exit \$?") && !str_contains($log, 'curl -sS'),
    'the tail of a long command body must not reach the log at all');

// A pathological single-line body must not become a giant entry either.
file_put_contents($tmp . '/body.txt', str_repeat('A', 50000));
@unlink($logFile);
shell_exec('sh ' . escapeshellarg($runner) . ' 2>/dev/null');
$log2   = (string)@file_get_contents($logFile);
$lines2 = array_values(array_filter(explode("\n", $log2), fn($l) => $l !== ''));
check('a 50KB single-line body is still one bounded line',
    count($lines2) === 3 && strlen($lines2[1]) < 300,
    'got ' . count($lines2) . ' lines, entry ' . (isset($lines2[1]) ? strlen($lines2[1]) : 0) . ' chars');

shell_exec('rm -rf ' . escapeshellarg($tmp));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
