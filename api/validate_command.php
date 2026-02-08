<?php
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/logging.php';
requireLogin();
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$command = $input['command'] ?? '';
$firewall_id = $input['firewall_id'] ?? 0;

if (empty($command) || !$firewall_id) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

/**
 * Validate command against approved patterns
 * Uses SQL LIKE with % wildcards for pattern matching
 */
function validateCommand($command, $DB) {
    // Clean the command
    $command = trim($command);
    
    // Check against approved patterns
    $stmt = $DB->prepare('SELECT * FROM approved_commands WHERE ? LIKE command_pattern ORDER BY risk_level');
    $stmt->execute([$command]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($matches)) {
        return [
            'valid' => false, 
            'error' => 'Command not in approved list',
            'risk_level' => 'UNKNOWN'
        ];
    }
    
    // Return the best match (lowest risk level)
    $match = $matches[0];
    
    return [
        'valid' => true,
        'match' => $match,
        'risk_level' => $match['risk_level'],
        'requires_confirmation' => (bool)$match['requires_confirmation'],
        'timeout_seconds' => (int)$match['timeout_seconds'],
        'category' => $match['category'],
        'description' => $match['description']
    ];
}

try {
    $validation = validateCommand($command, $DB);
    
    if (!$validation['valid']) {
        log_error('security', "Rejected command for firewall $firewall_id: $command", null, $firewall_id);
        echo json_encode([
            'success' => false,
            'valid' => false,
            'error' => $validation['error'],
            'risk_level' => $validation['risk_level']
        ]);
        exit;
    }
    
    // Log approved command
    log_info('security', "Approved command for firewall $firewall_id: $command (Risk: {$validation['risk_level']})", null, $firewall_id);
    
    echo json_encode([
        'success' => true,
        'valid' => true,
        'command' => $command,
        'risk_level' => $validation['risk_level'],
        'requires_confirmation' => $validation['requires_confirmation'],
        'timeout_seconds' => $validation['timeout_seconds'],
        'category' => $validation['category'],
        'description' => $validation['description']
    ]);
    
} catch (Exception $e) {
    log_error('system', "Command validation error: " . $e->getMessage(), null, $firewall_id);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Validation error']);
}