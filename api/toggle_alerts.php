<?php
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Verify CSRF token
if (!isset($input['csrf']) || !csrf_verify($input['csrf'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Validate input
if (!isset($input['firewall_id']) || !isset($input['alerts_enabled'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

$firewall_id = (int)$input['firewall_id'];
$alerts_enabled = (int)$input['alerts_enabled'];

// Validate alerts_enabled is 0 or 1
if ($alerts_enabled !== 0 && $alerts_enabled !== 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid alerts_enabled value']);
    exit;
}

try {
    // Update the firewall's alerts_enabled setting
    $stmt = db()->prepare('UPDATE firewalls SET alerts_enabled = ? WHERE id = ?');
    $result = $stmt->execute([$alerts_enabled, $firewall_id]);
    
    if ($result) {
        // Log the change
        error_log("User {$_SESSION['username']} toggled alerts for firewall ID {$firewall_id} to " . ($alerts_enabled ? 'enabled' : 'disabled'));
        
        echo json_encode([
            'success' => true,
            'message' => 'Alert setting updated successfully',
            'alerts_enabled' => $alerts_enabled
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update database']);
    }
} catch (Exception $e) {
    error_log("Error toggling alerts: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
