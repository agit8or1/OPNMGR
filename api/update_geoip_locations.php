<?php
/**
 * Update GeoIP Locations API
 * Updates all firewall locations from their WAN IPs
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/geoip.php';
require_once __DIR__ . '/../inc/auth.php';

requireLogin();

header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $stats = geoip_update_all_firewalls($DB);

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
