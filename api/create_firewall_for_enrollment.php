<?php
/**
 * Create Firewall for Enrollment API
 *
 * Creates a pending firewall record that can be enrolled via the
 * OPNManager Agent plugin using an enrollment key.
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

header('Content-Type: application/json');

// Check authentication before any output
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$hostname = trim($input['hostname'] ?? '');
$customer_group = trim($input['customer_group'] ?? '');

if (empty($hostname)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Firewall name (hostname) is required']);
    exit;
}

try {
    // Check if a pending firewall with this hostname already exists
    $stmt = $DB->prepare('SELECT id FROM firewalls WHERE hostname = ? AND status = "pending"');
    $stmt->execute([$hostname]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Reuse existing pending firewall - just generate new enrollment key for it
        $firewall_id = $existing['id'];

        // Update customer_group if provided
        if ($customer_group) {
            $stmt = $DB->prepare('UPDATE firewalls SET customer_group = ? WHERE id = ?');
            $stmt->execute([$customer_group, $firewall_id]);
        }

        // Invalidate any old unused tokens for this firewall
        $stmt = $DB->prepare('UPDATE enrollment_tokens SET used = 1 WHERE firewall_id = ? AND used = 0');
        $stmt->execute([$firewall_id]);
    } else {
        // Create new firewall with pending status
        $stmt = $DB->prepare('
            INSERT INTO firewalls (hostname, customer_group, ip_address, status)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            $hostname,
            $customer_group ?: null,
            '0.0.0.0',  // Will be updated on enrollment
            'pending'
        ]);

        $firewall_id = $DB->lastInsertId();
    }

    echo json_encode([
        'success' => true,
        'firewall_id' => $firewall_id,
        'hostname' => $hostname,
        'message' => 'Firewall created successfully'
    ]);

} catch (Exception $e) {
    error_log("Error creating firewall for enrollment: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
