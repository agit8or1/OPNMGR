<?php
// require_once __DIR__ . '/../inc/logging.php';  // Temporarily disabled due to syntax error
require_once __DIR__ . '/../inc/bootstrap.php';

requireLogin();
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Handle both JSON and form data
if (!$input) {
    $input = $_POST;
}

if (!csrf_verify($input['csrf'] ?? '')) {
    if (isset($_POST['action'])) {
        header('Location: /firewalls.php?error=' . urlencode('Invalid CSRF token'));
        exit;
    } else {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF verification failed']);
        exit;
    }
}

try {
    // Get all online firewalls
    $stmt = db()->prepare("SELECT id, hostname FROM firewalls WHERE status = 'online'");
    $stmt->execute();
    $firewalls = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $queued_count = 0;
    
    foreach ($firewalls as $firewall) {
        // Check if there's already a pending firmware update command
        $stmt = db()->prepare("SELECT COUNT(*) FROM agent_commands WHERE firewall_id = ? AND command_type = 'firmware_update' AND status IN ('pending', 'executing') AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
        $stmt->execute([$firewall['id']]);
        $pending_updates = $stmt->fetchColumn();
        
        if (!$pending_updates) {
            // Queue firmware update command
            $command_id = 'firmware_update_' . uniqid();
            $command_data = json_encode([
                'initiated_by' => 'admin',
                'timestamp' => date('c'),
                'mass_update' => true
            ]);
            
            $stmt = db()->prepare("INSERT INTO agent_commands (firewall_id, command_id, command_type, command_data, status, created_at) VALUES (?, ?, 'firmware_update', ?, 'pending', NOW())");
            $stmt->execute([$firewall['id'], $command_id, $command_data]);
            
            $queued_count++;
            
            // log_action('Mass Update', 'INFO', "Firmware update queued for {$firewall['hostname']}", $firewall['hostname'], $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        }
    }
    
    // log_action('Mass Update', 'INFO', "Mass update initiated - {$queued_count} firewalls queued for update", 'system', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    
    if (isset($_POST['action'])) {
        header('Location: /firewalls.php?success=' . urlencode("Update commands queued for {$queued_count} firewalls"));
        exit;
    } else {
        echo json_encode([
            'success' => true,
            'message' => "Update commands queued for {$queued_count} firewalls",
            'queued_count' => $queued_count,
            'total_firewalls' => count($firewalls)
        ]);
    }
    
} catch (Exception $e) {
    // log_action('Mass Update', 'ERROR', 'Failed to queue mass update: ' . $e->getMessage(), 'system', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    
    if (isset($_POST['action'])) {
        header('Location: /firewalls.php?error=' . urlencode('Database error occurred'));
        exit;
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}
?>
}
}