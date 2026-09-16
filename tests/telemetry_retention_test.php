<?php
/**
 * The tables that grow forever must have something that prunes them.
 *
 * Four tables were 96% of a 174 MB database after nine months of watching two
 * firewalls, growing by roughly 1,800 rows per firewall per day with no upper
 * bound. For a tool aimed at fleets that is the wrong shape: fifty firewalls
 * would add about 33 million rows a year and nothing would remove one.
 *
 * A retention setting existed and did nothing. log_retention_days sat at 90
 * with no code reading it, while the only caller of cleanup_old_logs() passes a
 * hardcoded 30 and is in no crontab - so logs were pruned when somebody
 * remembered to click something, at a retention nobody chose. That is the
 * fourth setting found this session that stored an intention and ignored it.
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
$prune = (string) @file_get_contents($root . '/cron/prune_telemetry.php');

check('cron/prune_telemetry.php exists', $prune !== '');

// ---------------------------------------------------------------------------
// 1. Every unbounded table is covered.
// ---------------------------------------------------------------------------

foreach ([
    'firewall_system_stats', 'firewall_latency',
    'firewall_traffic_stats', 'system_logs',
] as $table) {
    check("{$table} is pruned", strpos($prune, "'{$table}'") !== false);
}

// ---------------------------------------------------------------------------
// 2. It reports before it deletes.
// ---------------------------------------------------------------------------

check('deleting requires --apply', strpos($prune, "'--apply'") !== false);
check('report is the default',
    preg_match('/\$apply\s*=\s*in_array/', $prune) === 1
    && preg_match('/if \(!\$apply\)/', $prune) === 1,
    'the first run on an established installation removes hundreds of thousands of rows');

// ---------------------------------------------------------------------------
// 3. It honours the setting that used to do nothing.
// ---------------------------------------------------------------------------

check('log_retention_days is honoured', strpos($prune, "'log_retention_days'") !== false,
    'it sat at 90 while the only pruning path passed a hardcoded 30');
check('telemetry_retention_days is honoured', strpos($prune, "'telemetry_retention_days'") !== false);
check('retention values are clamped',
    preg_match('/\$min\s*=\s*\d+.*\$max\s*=\s*\d+/s', $prune) === 1,
    'a retention of 0 taken from the settings table would empty the tables');

// ---------------------------------------------------------------------------
// 4. It cannot stall the check-ins that write to the same tables.
// ---------------------------------------------------------------------------

check('deletes are chunked', preg_match('/LIMIT 5000/', $prune) === 1,
    'a single DELETE of several hundred thousand rows holds locks long enough to stall check-ins');
check('it yields between chunks', strpos($prune, 'usleep') !== false);

// ---------------------------------------------------------------------------
// 5. It reports itself like every other scheduled job.
// ---------------------------------------------------------------------------

check('the run is recorded', strpos($prune, "cron_run_begin('prune_telemetry')") !== false);
check('a failure is recorded as a failure',
    strpos($prune, "cron_run_finish('prune_telemetry', false") !== false);

// ---------------------------------------------------------------------------
// 6. The migration registers the setting and the job.
// ---------------------------------------------------------------------------

$mig = (string) @file_get_contents($root . '/database/migrations/0019_telemetry_retention.sql');
check('the migration exists', $mig !== '');
check('it adds telemetry_retention_days', strpos($mig, 'telemetry_retention_days') !== false);
check('it is idempotent', stripos($mig, 'ON DUPLICATE KEY UPDATE') !== false);
check('it does not overwrite an existing retention',
    preg_match('/log_retention_days.*?ON DUPLICATE KEY UPDATE\s*`name` = `name`/s', $mig) === 1,
    'an operator who chose a value must keep it');
check('the job is not marked overdue while unscheduled',
    preg_match('/expected_interval_minutes[^)]*\n?.*NULL/s', $mig) === 1,
    'it is in no crontab by default, so it must not be flagged late');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
