<?php
/**
 * The scheduled jobs page must describe jobs that exist.
 *
 * It used to describe five that largely did not. `scheduled_tasks` was seeded
 * once by hand with two jobs named under the wrong schedule, three that have
 * never been scheduled at all, and none of the four that actually run. Every
 * row read "never run" because nothing wrote `last_run`. The toggle beside each
 * row posted to an endpoint that was fatal on every request - it gated on
 * check_authentication(), which is defined nowhere - and had that worked it
 * would have written `enabled`, a column no scheduler has ever read.
 *
 * This suite pins the two properties that were violated: every registered job
 * names a script that exists, and every cron entrypoint reports itself.
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

// ---------------------------------------------------------------------------
// 1. The endpoint's auth gate must be a function that exists.
// ---------------------------------------------------------------------------

$endpoint = $root . '/api/manage_tasks.php';
$body = is_file($endpoint) ? (string) file_get_contents($endpoint) : '';

check('api/manage_tasks.php exists', $body !== '');

// Comments are stripped first: the file explains the old bug by name, and a
// naive search for the identifier would match that prose rather than a call.
$code = preg_replace(['~//[^\n]*~', '~/\*.*?\*/~s'], '', $body);
check('the comment stripper leaves code behind',
    strpos($code, 'json_encode') !== false, 'stripping ate the file');
check('it does not call check_authentication()',
    strpos($code, 'check_authentication(') === false,
    'that function is defined nowhere and made the endpoint fatal on every request');
check('it gates on a real auth helper',
    strpos($code, 'isLoggedIn()') !== false || strpos($code, 'requireLogin()') !== false);

// Whatever it calls to authenticate must actually be defined. Read the source
// rather than including it - inc/auth.php starts a session on load.
$auth = (string) @file_get_contents($root . '/inc/auth.php');
check('isLoggedIn() is defined in inc/auth.php',
    strpos($auth, 'function isLoggedIn') !== false);

// It must not offer a control surface the scheduler does not honour.
check('it does not write scheduled_tasks.enabled',
    !preg_match('/UPDATE\s+scheduled_tasks\s+SET\s+enabled/i', $code),
    'nothing reads that column, so writing it only pretends to disable a job');

// ---------------------------------------------------------------------------
// 2. Every cron entrypoint in the crontab reports itself.
// ---------------------------------------------------------------------------

// Job name => the script that runs it. These are the jobs this application
// installs; each must call cron_run_begin() with its own name.
$jobs = [
    'automated_backup'       => 'scripts/automated_backup.php',
    'cleanup_old_reports'    => 'cron/cleanup_old_reports.php',
    'cleanup_stuck_commands' => 'cron/cleanup_stuck_commands.php',
    'evaluate_alerts'        => 'cron/evaluate_alerts.php',
    'check_backup_health'    => 'scripts/check_backup_health.php',
    'prune_backups'          => 'cron/prune_backups.php',
    // Added in 3.48.0. These eight ran with nothing recording them, and two of
    // them stopped for three days before anyone noticed.
    'nightly_backups'        => 'cron/nightly_backups.php',
    'monitor_agent_health'   => 'scripts/monitor_agent_health.php',
    'auto_reset_stale_agents'=> 'scripts/auto_reset_stale_agents.php',
    'tunnel_health_monitor'  => 'cron/tunnel_health_monitor.php',
    'ssh_access_cleanup'     => 'scripts/manage_ssh_access.php',
    'nginx_tunnel_cleanup'   => 'scripts/manage_nginx_tunnel_proxy.php',
    'schedule_speedtest'     => 'api/schedule_speedtest.php',
    'run_auto_scans'         => 'scripts/run_auto_scans.php',
];

foreach ($jobs as $job => $rel) {
    $path = $root . '/' . $rel;
    check("{$rel} exists", is_file($path));
    if (!is_file($path)) { continue; }

    $src = (string) file_get_contents($path);
    check("{$rel} records its run", strpos($src, "cron_run_begin('{$job}')") !== false,
        'without this the job shows as never having run');
}

// ---------------------------------------------------------------------------
// 3. The migration registers exactly those jobs, and no invented ones.
// ---------------------------------------------------------------------------

$migration = $root . '/database/migrations/0018_real_scheduled_tasks.sql';
check('the migration exists', is_file($migration));

// Registration is spread over two migrations: 0018 carried the six jobs known
// then, 0020 the eight that were running unregistered. Every job must appear in
// one of them - an entrypoint that reports a name no migration registers writes
// its status to zero rows and shows up nowhere.
$registrations = '';
foreach (['0018_real_scheduled_tasks.sql', '0020_register_remaining_jobs.sql'] as $file) {
    $path = $root . '/database/migrations/' . $file;
    check("database/migrations/{$file} exists", is_file($path));
    $registrations .= (string) @file_get_contents($path);
}

foreach (array_keys($jobs) as $job) {
    check("a migration registers {$job}", strpos($registrations, "'{$job}'") !== false,
        'the entrypoint reports this name, so a row must carry it');
}

if (is_file($migration)) {
    $sql = (string) file_get_contents($migration);


    // The rows that described jobs which do not exist.
    foreach (['Firewall Health Check', 'SSH Tunnel Cleanup', 'Proxy Session Cleanup'] as $invented) {
        check("the migration removes the invented row \"{$invented}\"",
            strpos($sql, $invented) !== false);
    }

    check('the migration is idempotent on insert',
        stripos($sql, 'ON DUPLICATE KEY UPDATE') !== false);
}

// ---------------------------------------------------------------------------
// 4. The recorder never lets bookkeeping break a job.
// ---------------------------------------------------------------------------

$recorder = $root . '/inc/cron_runs.php';
check('inc/cron_runs.php exists', is_file($recorder));

if (is_file($recorder)) {
    $src = (string) file_get_contents($recorder);
    check('cron_run_begin is defined', strpos($src, 'function cron_run_begin') !== false);
    check('completion is recorded from a shutdown handler',
        strpos($src, 'register_shutdown_function') !== false,
        'otherwise a fatal leaves the row stuck at "running"');
    check('a fatal is recorded as a failure', strpos($src, 'error_get_last') !== false);

    // Every database touch must be wrapped: a backup must never be lost
    // because a status row could not be written.
    $writes = preg_match_all('/db\(\)->prepare/', $src);
    $catches = preg_match_all('/catch \(Throwable/', $src);
    check('every write is guarded', $writes > 0 && $catches >= $writes,
        "{$writes} write(s), {$catches} catch(es)");
}


// ---------------------------------------------------------------------------
// 5. A job that stops running has to be reported.
// ---------------------------------------------------------------------------

// This is the property the September 2026 outage violated. Two jobs stopped for
// three days: their crontab lines redirect into a file under /var/log, a
// rotation left their user unable to create it, and the shell failed on the
// redirect before the PHP started. cron logged the command every cycle. Nothing
// read a status, because neither job wrote one.

$alerting = (string) @file_get_contents($root . '/inc/alerting.php');
check("'job.stale' is a known alert type",
    strpos($alerting, "'job.stale'") !== false,
    'alert_raise() rejects a type that is not in ALERT_TYPES and only writes a log line');

$evaluator = (string) @file_get_contents($root . '/cron/evaluate_alerts.php');
check('the evaluator raises job.stale', strpos($evaluator, "raise('job.stale'") !== false);
check('the evaluator resolves job.stale', strpos($evaluator, "resolve('job.stale'") !== false,
    'a job that starts running again must close its own incident');

// The evaluator is itself a scheduled job, so it cannot report its own death.
// Nothing here can fix that; it is noted so the limit is not mistaken for
// coverage.
check('the evaluator watches jobs by their recorded runs',
    strpos($evaluator, 'cron_jobs()') !== false);

require_once __DIR__ . '/bootstrap.php';
require_once TEST_ROOT . '/inc/bootstrap_agent.php';
require_once TEST_ROOT . '/inc/cron_runs.php';

$testJob = '__test_stale_job__';

register_shutdown_function(static function () use ($testJob) {
    try {
        db()->prepare('DELETE FROM scheduled_tasks WHERE task_name = ?')->execute([$testJob]);
    } catch (Throwable $e) {
        // Nothing to clean up if the database was never reachable.
    }
});

/** Put the fixture row in a given state and read back how cron_jobs() sees it. */
$stateOf = static function (?int $interval, ?string $lastRun, ?string $status) use ($testJob): ?array {
    db()->prepare('DELETE FROM scheduled_tasks WHERE task_name = ?')->execute([$testJob]);
    db()->prepare(
        'INSERT INTO scheduled_tasks (task_name, description, schedule, script_path,
                                      expected_interval_minutes, last_run, last_status, enabled)
         VALUES (?,?,?,?,?,?,?,1)'
    )->execute([$testJob, 'fixture', 'fixture', 'tests/scheduled_jobs_test.php',
                $interval, $lastRun, $status]);

    foreach (cron_jobs() as $row) {
        if ($row['task_name'] === $testJob) {
            return $row;
        }
    }
    return null;
};

try {
    db()->query('SELECT 1');
    $dbUp = true;
} catch (Throwable $e) {
    $dbUp = false;
    echo "SKIP: no database, cron_jobs() behaviour not exercised\n";
}

if ($dbUp) {
    $ago = static fn(int $minutes): string => date('Y-m-d H:i:s', time() - $minutes * 60);

    // A job that has never run is not overdue. The crontab belongs to the
    // operator, and an unscheduled job must not raise anything.
    $row = $stateOf(5, null, null);
    check('a job that has never run is not overdue', $row !== null && $row['stale'] === false);
    check('a job that has never run says so', $row !== null && $row['never_run'] === true);

    // One recorded run arms the check.
    $row = $stateOf(5, $ago(3), 'ok');
    check('a job inside its interval is not overdue', $row !== null && $row['stale'] === false);

    $row = $stateOf(5, $ago(9), 'ok');
    check('one missed cycle is not yet overdue', $row !== null && $row['stale'] === false,
        'a job a few minutes late must not be called broken');

    $row = $stateOf(5, $ago(20), 'ok');
    check('two missed cycles is overdue', $row !== null && $row['stale'] === true);

    // The exact shape of the outage: a five-minute job silent for three days.
    $row = $stateOf(5, $ago(3 * 24 * 60), 'ok');
    check('a five-minute job silent for three days is overdue',
        $row !== null && $row['stale'] === true);
    $overdue = array_column(cron_jobs_overdue(), 'task_name');
    check('cron_jobs_overdue() returns it', in_array($testJob, $overdue, true));

    // A job killed mid-run leaves 'running' behind forever. Age decides, not
    // the recorded status.
    $row = $stateOf(5, $ago(3 * 24 * 60), 'running');
    check('a row stuck at "running" is still overdue', $row !== null && $row['stale'] === true,
        'otherwise a job killed mid-run reads as busy for as long as it stays dead');

    // No declared interval means the operator has not scheduled it.
    $row = $stateOf(null, $ago(3 * 24 * 60), 'ok');
    check('a job with no expected interval is never overdue',
        $row !== null && $row['stale'] === false);
    $overdue = array_column(cron_jobs_overdue(), 'task_name');
    check('cron_jobs_overdue() leaves it out', !in_array($testJob, $overdue, true));
}

// The page must not read a stale 'running' status as healthy.
$page = (string) @file_get_contents($root . '/settings_tasks.php');
$stalePos   = strpos($page, "'stale'");
$runningPos = strpos($page, "'Running'");
check('the page tests overdue before the recorded status',
    $stalePos !== false && $runningPos !== false && $stalePos < $runningPos,
    'a job dead for days showed as "Running"');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
