<?php
/**
 * Get recent AI scan reports for a specific firewall
 */

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';

requireLogin();
header('Content-Type: application/json');

$firewall_id = (int)($_GET['firewall_id'] ?? 0);
$limit = min((int)($_GET['limit'] ?? 10), 50); // Max 50 reports

if (!$firewall_id) {
    echo json_encode(['success' => false, 'error' => 'Firewall ID required']);
    exit;
}

try {
    $stmt = $DB->prepare('
        SELECT 
            r.*,
            COUNT(f.id) as finding_count
        FROM ai_scan_reports r
        LEFT JOIN ai_scan_findings f ON r.id = f.report_id
        WHERE r.firewall_id = ?
        GROUP BY r.id
        ORDER BY r.created_at DESC
        LIMIT ?
    ');
    $stmt->execute([$firewall_id, $limit]);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'reports' => $reports
    ]);
    
} catch (Exception $e) {
    error_log('Error getting AI reports: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
