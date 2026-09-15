<?php
/**
 * Update Check API Endpoint
 * This handles update requests from customer instances
 */

// Include database connection
require_once __DIR__ . '/../../inc/bootstrap.php';
require_once __DIR__ . '/../../inc/permissions.php';

header('Content-Type: application/json');

// Took an instance_id and a version, validated neither, and answered anyone.
// Part of the multi-instance update distribution built alongside the licensing
// subsystem removed in 3.29.0; nothing in this codebase calls it. A
// machine-to-machine caller would need a credential of its own, which has never
// existed here, so until one does this is administrator-only rather than open.
require_permission('system.maintenance');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['instance_id']) || !isset($input['current_version'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

$instance_id = $input['instance_id'];
$current_version = $input['current_version'];

try {
    // Log the update check
    error_log("Update check from instance: $instance_id, current version: $current_version");
    
    // Get available updates from database
    $stmt = db()->prepare("
        SELECT version, description, created_at as release_date 
        FROM platform_versions 
        WHERE status = 'released' 
        AND version > ? 
        ORDER BY created_at ASC
    ");
    $stmt->execute([$current_version]);
    $updates = $stmt->fetchAll();
    
    // Format updates for response
    $available_updates = [];
    $sequential_order = 1;
    
    foreach ($updates as $update) {
        $available_updates[] = [
            'id' => 'update_' . str_replace('.', '_', $update['version']),
            'version' => $update['version'],
            'description' => $update['description'],
            'release_date' => date('M j, Y', strtotime($update['release_date'])),
            'size' => '2.5 MB', // Mock size for now
            'requires_restart' => false,
            'dependencies' => [],
            'sequential_order' => $sequential_order++
        ];
    }
    
    echo json_encode([
        'success' => true,
        'updates' => $available_updates,
        'current_version' => $current_version,
        'latest_version' => !empty($available_updates) ? end($available_updates)['version'] : $current_version
    ]);
    
} catch (Exception $e) {
    error_log("Update check error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
?>