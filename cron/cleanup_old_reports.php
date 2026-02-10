<?php
/**
 * AI Report Housekeeping
 * Deletes AI scan reports older than 30 days
 * Run via cron daily
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';
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
        
        write_log('HOUSEKEEPING', "Deleted {$count} AI scan reports older than {$retention_days} days (before {$cutoff_date})");
        echo "✓ Deleted {$count} old AI scan reports\n";
    } else {
        echo "✓ No old reports to delete\n";
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
    write_log('HOUSEKEEPING_ERROR', "Report cleanup failed: {$error}");
    echo "✗ Error: {$error}\n";
    exit(1);
}
