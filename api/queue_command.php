<?php
/**
 * Queue a custom command for a firewall to execute on next checkin
 * Requires admin authentication and valid CSRF token.
 */

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');

// Authentication: require logged-in admin user
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
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

// Validate CSRF token (from JSON body or header)
$input_raw = file_get_contents('php://input');
$input_check = json_decode($input_raw, true);
$csrf = $input_check['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!csrf_verify($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$input = json_decode($input_raw, true);
$firewall_id = (int)($input['firewall_id'] ?? 0);
$command = trim($input['command'] ?? '');
$description = trim($input['description'] ?? '');

if (!$firewall_id || !$command) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing firewall_id or command']);
    exit;
}

try {
    // Verify firewall exists
    $stmt = db()->prepare('SELECT hostname FROM firewalls WHERE id = ?');
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch();
    
    if (!$firewall) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Firewall not found']);
        exit;
    }
    
    // Create firewall_commands table if it doesn't exist
    db()->exec("CREATE TABLE IF NOT EXISTS firewall_commands (
        id INT AUTO_INCREMENT PRIMARY KEY,
        firewall_id INT NOT NULL,
        command TEXT NOT NULL,
        description VARCHAR(255),
        status ENUM('pending', 'sent', 'completed', 'failed') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        sent_at TIMESTAMP NULL,
        completed_at TIMESTAMP NULL,
        result TEXT,
        INDEX(firewall_id),
        INDEX(status)
    )");
    
    // Insert the command
    $stmt = db()->prepare('INSERT INTO firewall_commands (firewall_id, command, description) VALUES (?, ?, ?)');
    $stmt->execute([$firewall_id, $command, $description]);
    
    $command_id = db()->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => "Command queued for firewall {$firewall['hostname']}",
        'command_id' => $command_id
    ]);
    
} catch (Exception $e) {
    error_log("queue_command.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
?>