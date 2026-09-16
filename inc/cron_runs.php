<?php
/**
 * Record what the scheduled jobs actually did.
 *
 * The Scheduled Tasks page was fiction. `scheduled_tasks` was seeded once with
 * five invented rows: two named jobs that exist on a different schedule than
 * claimed, three that have never existed at all, and none of the four real jobs
 * that do run. `last_run` was NULL on every row because nothing ever wrote it,
 * so a job running every five minutes displayed as never having run. The toggle
 * beside each row wrote `enabled`, which no cron script, include or scheduler
 * has ever read - switching a job "off" left it running.
 *
 * Rather than wire a control surface to a table the system does not consult,
 * the table now records what happened: each job reports its own start, finish,
 * outcome and duration. That is something the application actually knows, and
 * it answers the question an operator has - did the backup run last night, and
 * did it work.
 *
 * Usage: one line near the top of a cron entrypoint, after the bootstrap.
 *
 *     require_once __DIR__ . '/../inc/cron_runs.php';
 *     cron_run_begin('evaluate_alerts');
 *
 * Completion is recorded from a shutdown handler, so a fatal or an exit() is
 * still reported rather than leaving the row stuck at "running".
 *
 * Nothing here may ever stop a job running. Every failure path is swallowed
 * with a log line: a scheduled backup must not be lost because bookkeeping
 * could not write a row.
 *
 * @since 3.32.0
 */

if (!function_exists('cron_run_begin')) {
    /**
     * Mark a scheduled job as started, and arrange for its outcome to be
     * recorded when the process ends however it ends.
     */
    function cron_run_begin(string $job): void
    {
        $job = trim($job);
        if ($job === '') {
            return;
        }

        $started = microtime(true);

        try {
            db()->prepare(
                'UPDATE scheduled_tasks
                    SET last_status = ?, last_run = NOW(), last_message = NULL, updated_at = NOW()
                  WHERE task_name = ?'
            )->execute(['running', $job]);
        } catch (Throwable $e) {
            error_log('OPNMGR: could not record the start of cron job ' . $job . ': ' . $e->getMessage());
        }

        register_shutdown_function(static function () use ($job, $started): void {
            $error = error_get_last();
            $fatal = $error !== null
                && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);

            cron_run_finish(
                $job,
                !$fatal,
                $fatal ? ($error['message'] . ' in ' . $error['file'] . ':' . $error['line']) : '',
                (int) round((microtime(true) - $started) * 1000)
            );
        });
    }
}

if (!function_exists('cron_run_finish')) {
    /** Record the outcome of a scheduled job. Never throws. */
    function cron_run_finish(string $job, bool $ok, string $message = '', ?int $durationMs = null): void
    {
        try {
            db()->prepare(
                'UPDATE scheduled_tasks
                    SET last_status = ?, last_message = ?, last_duration_ms = ?, updated_at = NOW()
                  WHERE task_name = ?'
            )->execute([
                $ok ? 'ok' : 'failed',
                $message === '' ? null : substr($message, 0, 1000),
                $durationMs,
                $job,
            ]);
        } catch (Throwable $e) {
            error_log('OPNMGR: could not record the outcome of cron job ' . $job . ': ' . $e->getMessage());
        }
    }
}

if (!function_exists('cron_jobs')) {
    /**
     * The scheduled jobs, newest run first, as the UI and the API report them.
     *
     * `stale` is the useful signal: a job whose last run is far enough in the
     * past that its schedule says it should have run again by now.
     */
    function cron_jobs(): array
    {
        $rows = db()->query(
            'SELECT task_name, description, schedule, script_path, expected_interval_minutes,
                    last_run, last_status, last_duration_ms, last_message
               FROM scheduled_tasks
              ORDER BY task_name'
        )->fetchAll(PDO::FETCH_ASSOC);

        $now = time();
        foreach ($rows as &$row) {
            $row['stale'] = false;
            $row['never_run'] = empty($row['last_run']);
            $row['age_seconds'] = null;

            $interval = (int) ($row['expected_interval_minutes'] ?? 0);
            if ($interval > 0 && !empty($row['last_run'])) {
                $age = $now - strtotime((string) $row['last_run']);
                // Two missed cycles before calling it stale, so a job that runs
                // a minute late is not reported as broken.
                $row['stale'] = $age > ($interval * 60 * 2);
                $row['age_seconds'] = $age;
            }
        }
        unset($row);

        return $rows;
    }
}

if (!function_exists('cron_jobs_overdue')) {
    /**
     * The jobs that have stopped running.
     *
     * A job is only watched once it has run at least once: this application
     * does not own the crontab, so it cannot know whether an operator chose to
     * schedule a given job. The first recorded run arms the check; from then on
     * silence past two cycles is a fault rather than a preference.
     *
     * A row left at 'running' is included the same way. A job that dies without
     * reaching its shutdown handler - killed, or its host rebooted mid-run -
     * leaves that status behind forever, and the age of `last_run` is what says
     * whether it is working or gone.
     */
    function cron_jobs_overdue(): array
    {
        $overdue = [];
        foreach (cron_jobs() as $job) {
            if (!empty($job['stale'])) {
                $overdue[] = $job;
            }
        }

        return $overdue;
    }
}
