<?php
/**
 * Save AI scanning settings for a specific firewall
 */
require_once __DIR__ . '/../inc/bootstrap.php';

requireLogin();
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

// CSRF verification
if (!csrf_verify($input['csrf'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'CSRF verification failed']);
    exit;
}

$firewall_id = (int)($input['firewall_id'] ?? 0);
$auto_scan_enabled = !empty($input['auto_scan_enabled']) ? 1 : 0;
$include_logs = !empty($input['include_logs']) ? 1 : 0;
$scan_frequency = $input['scan_frequency'] ?? 'weekly';
$preferred_provider = $input['preferred_provider'] ?? null;

if (!$firewall_id) {
    echo json_encode(['success' => false, 'error' => 'Firewall ID required']);
    exit;
}

// Validate scan_frequency
if (!in_array($scan_frequency, ['daily', 'weekly', 'monthly'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid scan frequency']);
    exit;
}

// Determine scan type based on include_logs
$scan_type = $include_logs ? 'config_with_logs' : 'config_only';

try {
    // Calculate next scan time if auto_scan is enabled
    $next_scan_at = null;
    if ($auto_scan_enabled) {
        $interval = match($scan_frequency) {
            'daily' => '+1 day',
            'weekly' => '+1 week',
            'monthly' => '+1 month',
            default => '+1 week'
        };
        $next_scan_at = date('Y-m-d H:i:s', strtotime($interval));
    }
    
    // Update or insert settings
    $stmt = db()->prepare('
        INSERT INTO firewall_ai_settings 
        (firewall_id, auto_scan_enabled, scan_frequency, scan_type, include_logs, preferred_provider, next_scan_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            auto_scan_enabled = VALUES(auto_scan_enabled),
            scan_frequency = VALUES(scan_frequency),
            scan_type = VALUES(scan_type),
            include_logs = VALUES(include_logs),
            preferred_provider = VALUES(preferred_provider),
            next_scan_at = VALUES(next_scan_at)
    ');
    
    $stmt->execute([
        $firewall_id,
        $auto_scan_enabled,
        $scan_frequency,
        $scan_type,
        $include_logs,
        $preferred_provider ?: null,
        $next_scan_at
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'AI settings saved successfully',
        'next_scan_at' => $next_scan_at
    ]);
    
} catch (Exception $e) {
    error_log('Error saving AI settings: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
