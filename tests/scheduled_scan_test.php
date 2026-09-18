<?php
/**
 * A toggle that saves a setting nothing acts on is worse than no toggle.
 *
 * Run with: php tests/scheduled_scan_test.php
 *
 * firewall_ai_settings.auto_scan_enabled could be switched on in the UI and was
 * stored faithfully. No scheduled scan had ever run. Four separate things were
 * wrong at once, each of which alone would have been enough:
 *
 *   1. scripts/run_auto_scans.php called performAIScan(), which has never
 *      existed anywhere in this codebase.
 *   2. api/ai_scan.php ran its whole body on include - so reaching its functions
 *      meant running a scan with no firewall id, sending headers, and exiting.
 *   3. That file resolved one include against the working directory, so from the
 *      CLI it died on require_once(../inc/agent_version.php) before anything.
 *   4. The cron entry ran as a user that cannot read /etc/opnmgr/keys/* (owned
 *      by www-data, mode 0600), so the SSH fetch failed before the scan began.
 *
 * The visible symptom was one firewall with the toggle on, weekly frequency,
 * and next_scan_at nine months in the past.
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
$rawScan  = (string) @file_get_contents($root . '/api/ai_scan.php');
$rawSched = (string) @file_get_contents($root . '/scripts/run_auto_scans.php');

$strip = static function (string $src): string {
    return implode("\n", array_filter(explode("\n", $src), static function (string $l): bool {
        $t = ltrim($l);
        return $t !== '' && !str_starts_with($t, '//') && !str_starts_with($t, '*')
            && !str_starts_with($t, '/*') && !str_starts_with($t, '#');
    }));
};
$scan  = $strip($rawScan);
$sched = $strip($rawSched);

check('both files are readable', $scan !== '' && $sched !== '');

// --- the scan must be callable, not merely executable ------------------------

check('a scan is a function',
    str_contains($scan, 'function opnmgr_run_ai_scan(int $firewall_id'),
    'the body used to run on include, which is why nothing could reuse it');
check('the web entry point is guarded',
    str_contains($scan, "if (PHP_SAPI !== 'cli')"),
    'including the file from the CLI must not run a scan or send headers');
check('the session check is inside the guard',
    (bool) preg_match("/if \(PHP_SAPI !== 'cli'\) \{[\s\S]{0,400}isLoggedIn\(\)/", $scan),
    'a CLI caller has no session and must not be rejected for lacking one');
check('headers are only sent for a web request',
    (bool) preg_match("/if \(PHP_SAPI !== 'cli'\) \{[\s\S]{0,200}header\('Content-Type: application\/json'\)/", $scan));
check('the disabled-AI path returns rather than exits',
    (bool) preg_match("/ai_enabled\(\)\) \{[\s\S]{0,600}return \\\$response;/", $scan),
    'exit() inside a function the scheduler calls would end the whole run');
check('the http code is advisory, not printed',
    str_contains($scan, "unset(\$result['http_code'])"));

// --- and the scheduler must call it -------------------------------------------

check('the scheduler calls the real entry point',
    str_contains($sched, 'opnmgr_run_ai_scan('),
    'it called performAIScan(), which does not exist');
check('the function that never existed is gone',
    !str_contains($sched, 'performAIScan('));
check('the stub no longer aborts the run',
    !str_contains($sched, 'Scheduled AI scanning is not implemented'));
check('it reads the schedule row, not the firewall row, for scan type',
    str_contains($sched, "\$fw['scan_type']"),
    '$firewall is the firewalls row; the schedule lives on firewall_ai_settings');
check('a successful scan advances next_scan_at',
    str_contains($sched, 'next_scan_at = DATE_ADD(NOW(), INTERVAL $interval)'));
check('a failed scan retries rather than spinning',
    str_contains($sched, 'INTERVAL 1 DAY'));
check('the report id is carried through',
    str_contains($sched, "\$result['data']['report_id']"));

// --- includes must not depend on the working directory ------------------------

check('no include in the scan resolves against the CWD',
    !preg_match("/require(_once)? '\\.\\.\\//", $rawScan),
    'this is what killed every nightly run');

// --- the log must go somewhere writable ---------------------------------------

check('the scheduler logs under /var/log/opnmgr',
    str_contains($sched, "\$log_file = '/var/log/opnmgr/opnsense_auto_scans.log'"),
    '/var/log is not writable by the service account, so the write failed silently');
check('it does not log to the unwritable path',
    !str_contains($sched, "'/var/log/opnsense_auto_scans.log'"));

// --- deploying must not overwrite production's own state ----------------------

$deploy = (string) @file_get_contents($root . '/scripts/deploy.sh');
check('a deploy script exists', $deploy !== '',
    'the command was being retyped, and the exclusions with it');
foreach (['keys/', '.env', 'logs/', '.git'] as $keep) {
    check("deploy preserves {$keep}", str_contains($deploy, "--exclude='{$keep}'"));
}
check('deploy still removes files deleted from the repository',
    str_contains($deploy, '--delete'),
    'without it production keeps serving code that no longer exists');
check('deploy reports drift', str_contains($deploy, 'drift'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
