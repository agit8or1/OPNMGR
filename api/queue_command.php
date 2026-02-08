<?php
require_once __DIR__ . '/../inc/db.php';

/**
 * Queue a custom command for a firewall to execute on next checkin
 */

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
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
    $stmt = $DB->prepare('SELECT hostname FROM firewalls WHERE id = ?');
    $stmt->execute([$firewall_id]);
    $firewall = $stmt->fetch();
    
    if (!$firewall) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Firewall not found']);
        exit;
    }
    
    // Create firewall_commands table if it doesn't exist
    $DB->exec("CREATE TABLE IF NOT EXISTS firewall_commands (
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
    $stmt = $DB->prepare('INSERT INTO firewall_commands (firewall_id, command, description) VALUES (?, ?, ?)');
    $stmt->execute([$firewall_id, $command, $description]);
    
    $command_id = $DB->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => "Command queued for firewall {$firewall['hostname']}",
        'command_id' => $command_id
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>