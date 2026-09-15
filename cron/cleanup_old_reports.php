<?php

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);
/**
 * AI Report Housekeeping
 * Deletes AI scan reports older than 30 days
 * Run via cron daily
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/cron_runs.php';

// Report this run - start, outcome and duration - so the scheduled jobs
// page shows what happened instead of the invented rows it used to carry.
// Recording never blocks the job: every failure inside is logged and swallowed.
cron_run_begin('cleanup_old_reports');
require_once __DIR__ . '/../inc/logging.php';

$retention_days = 30;
$cutoff_date = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));

try {
    // Count reports to be deleted
    $stmt = db()->prepare("
        SELECT COUNT(*) as count 
        FROM ai_scan_reports 
        WHERE created_at < ?
    ");
    $stmt->execute([$cutoff_date]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $count = $result['count'] ?? 0;
    
    if ($count > 0) {
        // Delete old reports
        $stmt = db()->prepare("
            DELETE FROM ai_scan_reports 
            WHERE created_at < ?
        ");
        $stmt->execute([$cutoff_date]);
        
        log_event('info', 'HOUSEKEEPING', "Deleted {$count} AI scan reports older than {$retention_days} days (before {$cutoff_date})");
        echo "✓ Deleted {$count} old AI scan reports\n";
    } else {
        echo "✓ No old reports to delete\n";
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
    log_event('error', 'HOUSEKEEPING', "Report cleanup failed: {$error}");
    echo "✗ Error: {$error}\n";
    exit(1);
}
