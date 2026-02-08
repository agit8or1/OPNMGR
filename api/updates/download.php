<?php
/**
 * Update Download API Endpoint  
 * This provides update packages to customer instances
 */

// Include database connection
require_once __DIR__ . '/../../inc/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['instance_id']) || !isset($input['update_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

$instance_id = $input['instance_id'];
$update_id = $input['update_id'];

try {
    // Log the download request
    error_log("Update download for instance: $instance_id, update: $update_id");
    
    // Extract version from update_id (format: update_X_Y_Z)
    $version_parts = explode('_', $update_id);
    if (count($version_parts) >= 2) {
        array_shift($version_parts); // Remove 'update'
        $version = implode('.', $version_parts);
    } else {
        throw new Exception('Invalid update ID format');
    }
    
    // Get update information from database
    $stmt = $DB->prepare("
        SELECT version, description, changelog 
        FROM platform_versions 
        WHERE version = ? AND status = 'released'
    ");
    $stmt->execute([$version]);
    $update_info = $stmt->fetch();
    
    if (!$update_info) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Update not found']);
        exit;
    }
    
    // For now, provide a basic update package
    // In a real system, this would contain actual files and database changes
    $update_package = [
        'id' => $update_id,
        'version' => $update_info['version'],
        'description' => $update_info['description'],
        'files' => [
            // Example: Add a simple update marker file
            [
                'path' => 'updates/applied/' . $update_id . '.txt',
                'content' => base64_encode("Update {$update_info['version']} applied on " . date('Y-m-d H:i:s')),
                'permissions' => '644'
            ]
        ],
        'sql' => [
            // Mark this update as applied in the database
            "INSERT INTO change_log (version, change_type, component, title, description, author, created_at) VALUES ('{$update_info['version']}', 'update_applied', 'system', 'Platform Update', '{$update_info['description']}', 'system', NOW()) ON DUPLICATE KEY UPDATE description = VALUES(description)"
        ],
        'requires_restart' => false
    ];
    
    echo json_encode([
        'success' => true,
        'update' => $update_package
    ]);
    
} catch (Exception $e) {
    error_log("Update download error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error: ' . $e->getMessage()]);
}
?>