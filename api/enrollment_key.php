<?php
/**
 * Enrollment Key Generator API
 *
 * Generates enrollment keys for firewalls that can be used with the
 * OPNManager Agent plugin for easy setup.
 */
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');

// Check authentication
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input or form data
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$firewall_id = (int)($input['firewall_id'] ?? 0);

if (!$firewall_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Firewall ID is required']);
    exit;
}

// Verify firewall exists
$stmt = db()->prepare('SELECT id, hostname FROM firewalls WHERE id = ?');
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$firewall) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Firewall not found']);
    exit;
}

try {
    // Generate a unique token
    $token = bin2hex(random_bytes(32)); // 64 character hex string

    // Set expiry to 24 hours from now
    $expires_at = date('Y-m-d H:i:s', time() + 86400);

    // Insert into enrollment_tokens table
    $stmt = db()->prepare('
        INSERT INTO enrollment_tokens (token, firewall_id, expires_at, used)
        VALUES (?, ?, ?, 0)
    ');
    $stmt->execute([$token, $firewall_id, $expires_at]);

    // Get server URL from configuration or use detected URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $server_url = $protocol . '://' . $_SERVER['HTTP_HOST'];

    // Create the enrollment key (base64 encoded JSON) with all settings
    $enrollment_data = [
        'server_url' => $server_url,
        'token' => $token,
        'checkin_interval' => 120,
        'ssh_key_management' => true,
        'verify_ssl' => true
    ];
    $enrollment_key = base64_encode(json_encode($enrollment_data));

    echo json_encode([
        'success' => true,
        'enrollment_key' => $enrollment_key,
        'expires_at' => $expires_at,
        'firewall_hostname' => $firewall['hostname']
    ]);

} catch (Exception $e) {
    error_log("Error generating enrollment key: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error generating enrollment key']);
}
