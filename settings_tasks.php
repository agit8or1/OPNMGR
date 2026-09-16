<?php
/**
 * Settings > Scheduled jobs.
 *
 * This page used to show five jobs with a toggle beside each. Two named a real
 * job on the wrong schedule, three had never existed as scheduled jobs at all,
 * and the four jobs that do run were absent. Every row read "never run",
 * because nothing wrote last_run. The toggles called an endpoint that was fatal
 * on every request, and had it worked it would have written a column that no
 * scheduler reads - so a job switched "off" kept running.
 *
 * It reports now. Jobs are scheduled by the system crontab, which this
 * application does not own; each one records its own start, outcome and
 * duration, and this shows that.
 */
require_once __DIR__ . '/inc/bootstrap.php';
requireLogin();
requireAdmin();
require_once __DIR__ . '/inc/cron_runs.php';

$page_title = 'Scheduled Jobs';

$jobs  = [];
$error = '';
try {
    $jobs = cron_jobs();
} catch (Throwable $e) {
    error_log('settings_tasks.php: ' . $e->getMessage());
    $error = 'Could not read scheduled job status.';
}

require_once __DIR__ . '/inc/header.php';

function job_state(array $job): array
{
    if (!empty($job['never_run'])) {
        return ['secondary', 'Never run'];
    }
    // Overdue outranks the recorded status. A job that was killed mid-run, or
    // whose host rebooted, leaves 'running' behind permanently; reading that
    // first showed a job dead for days as busy.
    if (!empty($job['stale'])) {
        return ['warning', 'Overdue'];
    }
    if (($job['last_status'] ?? '') === 'failed') {
        return ['danger', 'Failed'];
    }
    if (($job['last_status'] ?? '') === 'running') {
        return ['info', 'Running'];
    }
    return ['success', 'OK'];
}

function job_age(?int $seconds): string
{
    if ($seconds === null) {
        return '—';
    }
    if ($seconds < 90) {
        return $seconds . 's ago';
    }
    if ($seconds < 5400) {
        return round($seconds / 60) . 'm ago';
    }
    if ($seconds < 172800) {
        return round($seconds / 3600) . 'h ago';
    }
    return round($seconds / 86400) . 'd ago';
}
?>

<div class="container-fluid mt-4" style="max-width:1100px;">
    <h2 class="mb-1"><i class="fas fa-clock me-2"></i>Scheduled Jobs</h2>
    <p class="text-muted">
        These run from the system crontab. Each job records its own outcome, so this
        page reports what happened rather than what was configured. Changing the
        schedule means editing the crontab on the server.
    </p>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php elseif (!$jobs): ?>
        <div class="alert alert-warning">
            No scheduled jobs are registered. Run <code>php scripts/migrate.php</code> to
            populate them.
        </div>
    <?php else: ?>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Job</th>
                            <th>Schedule</th>
                            <th>Last run</th>
                            <th>Took</th>
                            <th>State</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($jobs as $job): ?>
                        <?php [$colour, $label] = job_state($job); ?>
                        <tr>
                            <td>
                                <div><strong><?php echo htmlspecialchars($job['task_name']); ?></strong></div>
                                <div class="small text-muted"><?php echo htmlspecialchars($job['description'] ?? ''); ?></div>
                                <?php if (!empty($job['script_path'])): ?>
                                    <div class="small"><code><?php echo htmlspecialchars($job['script_path']); ?></code></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap"><?php echo htmlspecialchars($job['schedule'] ?? '—'); ?></td>
                            <td class="text-nowrap">
                                <?php if (empty($job['last_run'])): ?>
                                    <span class="text-muted">—</span>
                                <?php else: ?>
                                    <?php echo htmlspecialchars(job_age($job['age_seconds'] ?? null)); ?>
                                    <div class="small text-muted"><?php echo htmlspecialchars($job['last_run']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <?php echo $job['last_duration_ms'] === null
                                    ? '—'
                                    : htmlspecialchars(number_format((int) $job['last_duration_ms'] / 1000, 1) . 's'); ?>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $colour; ?>"><?php echo $label; ?></span>
                                <?php if (!empty($job['last_message'])): ?>
                                    <div class="small text-danger mt-1">
                                        <?php echo htmlspecialchars($job['last_message']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-muted small mt-3">
            <strong>Overdue</strong> means a job has not reported in for more than twice its
            expected interval. <strong>Never run</strong> means it has not reported since this
            page started recording — a daily job will show that until its next scheduled run.
        </p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
