<?php
/**
 * Delete AI Report API
 * Allows deletion of AI scan reports and related findings
 */
require_once __DIR__ . '/../inc/bootstrap.php';

requireLogin();
requireAdmin();

header('Content-Type: application/json');

// Verify CSRF token
if (!csrf_verify($_POST['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF verification failed']);
    exit;
}

$report_id = (int)($_POST['report_id'] ?? 0);

if (!$report_id) {
    echo json_encode(['success' => false, 'error' => 'Missing report ID']);
    exit;
}

try {
    // Verify report exists
    $stmt = db()->prepare("SELECT id FROM ai_scan_reports WHERE id = ?");
    $stmt->execute([$report_id]);
    $report = $stmt->fetch();
    
    if (!$report) {
        echo json_encode(['success' => false, 'error' => 'Report not found']);
        exit;
    }
    
    // Delete related findings first
    $stmt = db()->prepare("DELETE FROM ai_scan_findings WHERE report_id = ?");
    $stmt->execute([$report_id]);

    // Delete related log analysis results
    $stmt = db()->prepare("DELETE FROM log_analysis_results WHERE report_id = ?");
    $stmt->execute([$report_id]);

    // Delete the report
    $stmt = db()->prepare("DELETE FROM ai_scan_reports WHERE id = ?");
    $stmt->execute([$report_id]);

    echo json_encode(['success' => true, 'message' => 'Report deleted successfully']);
} catch (Exception $e) {
    error_log("[AI_REPORTS] Error deleting report: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
?>
