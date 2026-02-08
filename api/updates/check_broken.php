<?php
/**
 * Update Check API Endpoint
 * This handles update requests from customer instances
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
    $stmt = $DB->prepare("
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
        'requires_restart' => true,
        'dependencies' => ['update_2024_001'],
        'sequential_order' => 2
    ]
];

// Filter updates based on current version and dependencies
$filtered_updates = [];
foreach ($available_updates as $update) {
    if (version_compare($update['version'], $current_version, '>')) {
        $filtered_updates[] = $update;
    }
}

// Sort by sequential order to ensure proper update sequence
usort($filtered_updates, function($a, $b) {
    return $a['sequential_order'] - $b['sequential_order'];
});

// Log the request (replace with actual logging)
error_log("Update check from instance: $instance_id, current version: $current_version");

// Return response
echo json_encode([
    'success' => true,
    'instance_id' => $instance_id,
    'current_version' => $current_version,
    'updates' => $filtered_updates,
    'check_time' => date('Y-m-d H:i:s'),
    'server_version' => '2.1.0'
]);
?>