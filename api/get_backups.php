<?php
require_once __DIR__ . '/../inc/bootstrap.php';

requireLogin();

/**
 * Get Backups API
 * Returns backup list for specified firewall
 */
header('Content-Type: application/json');

$firewall_id = (int)($_GET['firewall_id'] ?? 0);

if (!$firewall_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing firewall ID']);
    exit;
}

try {
    // Get backups for this firewall (last 60 days)
    $stmt = db()->prepare("
        SELECT id, backup_file, backup_type, created_at, file_size,
               CONCAT('Configuration backup created on ', DATE_FORMAT(created_at, '%M %d, %Y at %H:%i')) as description
        FROM backups 
        WHERE firewall_id = ? 
        AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
        ORDER BY created_at DESC
    ");
    $stmt->execute([$firewall_id]);
    $backups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'backups' => $backups
    ]);
    
} catch (Exception $e) {
    error_log("get_backups.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to get backups']);
}
?>