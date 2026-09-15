<?php
/**
 * Scheduled jobs status.
 *
 * This endpoint was fatal on every request, authenticated or not: it gated on
 * check_authentication(), a function that is defined nowhere in the codebase.
 * So the Scheduled Tasks page could never list a job, and its toggles could
 * never save. PHP stops at the fatal, so nothing ran unauthenticated - it was a
 * feature that had never worked, not a way past the login.
 *
 * Behind the toggle was a second problem. It wrote `scheduled_tasks.enabled`,
 * which no cron script, include or scheduler has ever read. Switching a job
 * "off" changed a column and left the job running on its normal schedule. The
 * jobs are started by the system crontab, which this application does not own
 * and should not silently override.
 *
 * So this reports rather than pretends to control. Each job records its own
 * start, outcome and duration (inc/cron_runs.php), and this returns that -
 * which answers the question an operator actually has: did the backup run last
 * night, and did it work.
 *
 * @since 3.32.0
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/cron_runs.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

// Job status names the scripts and schedules of the installation itself, so it
// is an administrator's view rather than a technician's.
if (function_exists('requireAdmin')) {
    requireAdmin();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Scheduled jobs are run by the system '
                   . 'crontab; this endpoint reports their status and does not change it.',
    ]);
    exit;
}

try {
    $jobs = cron_jobs();

    echo json_encode([
        'success' => true,
        'jobs'    => $jobs,
        'note'    => 'Jobs are scheduled by the system crontab. This is a status view.',
    ]);
} catch (Throwable $e) {
    error_log('manage_tasks.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not read scheduled job status']);
}
