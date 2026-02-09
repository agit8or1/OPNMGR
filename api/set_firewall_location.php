<?php
/**
 * Set Firewall Location Manually
 * Override GeoIP location with manual coordinates
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';

requireLogin();

header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!csrf_verify($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$firewall_id = (int)($_POST['firewall_id'] ?? 0);
$latitude = (float)($_POST['latitude'] ?? 0);
$longitude = (float)($_POST['longitude'] ?? 0);

if ($firewall_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid firewall_id']);
    exit;
}

if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid coordinates']);
    exit;
}

try {
    $stmt = $DB->prepare("UPDATE firewalls SET latitude = ?, longitude = ? WHERE id = ?");
    $stmt->execute([$latitude, $longitude, $firewall_id]);

    echo json_encode([
        'success' => true,
        'message' => "Location updated for firewall {$firewall_id}",
        'latitude' => $latitude,
        'longitude' => $longitude
    ]);

} catch (Exception $e) {
    error_log("set_firewall_location error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
?>
