#!/usr/bin/php
<?php
/**
 * AI Report Housekeeping Script
 * Deletes AI scan reports older than 30 days
 * Runs daily via cron
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/../inc/logging.php';

$logfile = '/var/log/opnmgr_housekeeping.log';

function hk_log($message) {
    global $logfile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logfile, "[$timestamp] REPORT_CLEANUP: $message\n", FILE_APPEND);
    echo "[$timestamp] $message\n";
}

try {
    hk_log("=== Starting AI Report Cleanup ===");
    
    // Delete reports older than 30 days
    $cutoff_date = date('Y-m-d H:i:s', strtotime('-30 days'));
    
    $stmt = db()->prepare("
        SELECT COUNT(*) as count, SUM(SIZE(full_report)) as total_size
        FROM ai_scan_reports
        WHERE created_at < ?
    ");
    $stmt->execute([$cutoff_date]);
    $before = $stmt->fetch(PDO::FETCH_ASSOC);

    // Delete related data first (findings and log analysis results)
    $stmt = db()->prepare("
        DELETE f, l
        FROM ai_scan_reports r
        LEFT JOIN ai_scan_findings f ON r.id = f.report_id
        LEFT JOIN log_analysis_results l ON r.id = l.report_id
        WHERE r.created_at < ?
    ");
    $stmt->execute([$cutoff_date]);

    // Delete the reports
    $stmt = db()->prepare("DELETE FROM ai_scan_reports WHERE created_at < ?");
    $stmt->execute([$cutoff_date]);
    $deleted = $stmt->rowCount();
    
    hk_log("Deleted $deleted reports older than $cutoff_date");
    
    if ($deleted > 0) {
        write_log('HOUSEKEEPING', "Deleted $deleted AI reports older than 30 days");
    }
    
    hk_log("=== Report Cleanup Complete ===");
    
} catch (Exception $e) {
    hk_log("ERROR: " . $e->getMessage());
}
?>
