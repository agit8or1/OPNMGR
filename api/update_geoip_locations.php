<?php
/**
 * Update GeoIP Locations API
 * Updates all firewall locations from their WAN IPs
 */
require_once __DIR__ . '/../inc/bootstrap.php';

require_once __DIR__ . '/../inc/geoip.php';
requireLogin();

header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// CSRF validation
$csrf_token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!csrf_verify($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

try {
    $stats = geoip_update_all_firewalls(db());

    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'message' => "Updated {$stats['success']} firewall(s), {$stats['failed']} failed, {$stats['skipped']} skipped"
    ]);

} catch (Exception $e) {
    error_log("update_geoip_locations.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
?>
