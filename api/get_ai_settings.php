<?php
/**
 * Get AI scanning settings for a specific firewall
 */
require_once __DIR__ . '/../inc/bootstrap.php';

requireLogin();
header('Content-Type: application/json');

$firewall_id = (int)($_GET['firewall_id'] ?? 0);

if (!$firewall_id) {
    echo json_encode(['success' => false, 'error' => 'Firewall ID required']);
    exit;
}

try {
    // Get or create AI settings for this firewall
    $stmt = db()->prepare('
        SELECT * FROM firewall_ai_settings 
        WHERE firewall_id = ?
    ');
    $stmt->execute([$firewall_id]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$settings) {
        // Create default settings
        $stmt = db()->prepare('
            INSERT INTO firewall_ai_settings
            (firewall_id, auto_scan_enabled, scan_frequency, scan_type, include_logs)
            VALUES (?, 0, "weekly", "config_with_logs", 1)
        ');
        $stmt->execute([$firewall_id]);
        
        // Fetch the newly created settings
        $stmt = db()->prepare('
            SELECT * FROM firewall_ai_settings 
            WHERE firewall_id = ?
        ');
        $stmt->execute([$firewall_id]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Convert boolean fields
    $settings['auto_scan_enabled'] = (bool)$settings['auto_scan_enabled'];
    $settings['include_logs'] = (bool)$settings['include_logs'];
    
    echo json_encode([
        'success' => true,
        'settings' => $settings
    ]);
    
} catch (Exception $e) {
    error_log('Error getting AI settings: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
