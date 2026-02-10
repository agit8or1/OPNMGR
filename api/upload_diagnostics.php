<?php
/**
 * Upload Diagnostics API
 * Receives diagnostic logs from firewalls
 */
require_once __DIR__ . '/../inc/bootstrap_agent.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$firewall_id = (int)($_POST['firewall_id'] ?? 0);
$hardware_id = trim($_POST['hardware_id'] ?? '');

if (!$firewall_id || empty($hardware_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing authentication']);
    exit;
}

$auth_stmt = db()->prepare('SELECT hardware_id FROM firewalls WHERE id = ?');
$auth_stmt->execute([$firewall_id]);
$auth_fw = $auth_stmt->fetch(PDO::FETCH_ASSOC);
if (!$auth_fw || (
    !empty($auth_fw['hardware_id']) && !hash_equals($auth_fw['hardware_id'], $hardware_id)
)) {
    http_response_code(403);
    echo json_encode(['error' => 'Authentication failed']);
    exit;
}

if (!isset($_FILES['log_file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No log file uploaded']);
    exit;
}

try {
    $uploaded_file = $_FILES['log_file'];
    $log_dir = '/var/www/opnsense/diagnostics';
    
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $timestamp = date('Y-m-d_H-i-s');
    $target_path = $log_dir . "/agent_diag_{$firewall_id}_{$timestamp}.log";
    
    if (move_uploaded_file($uploaded_file['tmp_name'], $target_path)) {
        $log_content = file_get_contents($target_path);
        $stmt = db()->prepare("INSERT INTO system_logs (firewall_id, category, message, level, timestamp) VALUES (?, 'agent_diagnostics', ?, 'INFO', NOW())");
        $stmt->execute([$firewall_id, substr($log_content, 0, 5000)]);
        
        echo json_encode(['success' => true, 'message' => 'Diagnostics uploaded', 'file' => basename($target_path)]);
    } else {
        throw new Exception('Failed to save file');
    }
} catch (Exception $e) {
    error_log("upload_diagnostics.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to upload diagnostics']);
}
?>
