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

if (is_file($migration)) {
    $sql = (string) file_get_contents($migration);

    foreach (array_keys($jobs) as $job) {
        check("the migration registers {$job}", strpos($sql, "'{$job}'") !== false);
    }

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

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
