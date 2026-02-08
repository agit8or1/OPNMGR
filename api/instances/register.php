<?php
/**
 * Instance Registration API Endpoint
 * This would be deployed on the main server (opn.agit8or.net)
 * to register new customer instances
 */

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

if (!$input || !isset($input['instance_id']) || !isset($input['customer_name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

$instance_id = $input['instance_id'];
$customer_name = $input['customer_name'];
$version = $input['version'] ?? 'unknown';
$deployment_date = $input['deployment_date'] ?? date('Y-m-d');
$server_info = $input['server_info'] ?? [];

// Mock database connection (replace with actual database)
// $pdo = new PDO('mysql:host=localhost;dbname=opnsense_main', $username, $password);

// Mock registration logic (replace with actual database operations)
$registration_data = [
    'instance_id' => $instance_id,
    'customer_name' => $customer_name,
    'version' => $version,
    'deployment_date' => $deployment_date,
    'server_info' => $server_info,
    'registered_at' => date('Y-m-d H:i:s'),
    'status' => 'active'
];

// Log the registration
error_log("New instance registered: $instance_id for customer: $customer_name");

// In production, save to database:
/*
$stmt = $pdo->prepare("
    INSERT INTO customer_instances 
    (instance_id, customer_name, version, deployment_date, server_info, registered_at, status)
    VALUES (?, ?, ?, ?, ?, NOW(), 'active')
    ON DUPLICATE KEY UPDATE 
    customer_name = VALUES(customer_name),
    version = VALUES(version),
    server_info = VALUES(server_info),
    registered_at = NOW()
");
$stmt->execute([
    $instance_id,
    $customer_name, 
    $version,
    $deployment_date,
    json_encode($server_info)
]);
*/

// Generate API key for the instance (in production)
$api_key = 'mock_api_key_' . $instance_id;

// Return registration confirmation
echo json_encode([
    'success' => true,
    'message' => 'Instance registered successfully',
    'instance_id' => $instance_id,
    'customer_name' => $customer_name,
    'api_key' => $api_key,
    'registered_at' => date('Y-m-d H:i:s'),
    'endpoints' => [
        'updates_check' => '/api/updates/check.php',
        'updates_download' => '/api/updates/download.php',
        'support' => '/api/support/ticket.php'
    ],
    'next_steps' => [
        'Configure automatic update checks',
        'Test update functionality',
        'Review security settings',
        'Contact support if needed'
    ]
]);
?>