<?php
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$firewall_id = $input['firewall_id'] ?? null;
$status = $input['status'] ?? null;
$message = $input['message'] ?? null;

if (!$firewall_id || !$status) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

try {
    // Update firewall status based on update progress
    $firewall_status = 'online';
    switch ($status) {
        case 'updating':
            $firewall_status = 'updating';
            break;
        case 'failed':
            $firewall_status = 'online'; // Return to online even if update failed
            break;
        case 'completed':
            $firewall_status = 'online';
            // Clear update request flag on completion
            $stmt = db()->prepare("UPDATE firewalls SET update_requested = 0 WHERE id = ?");
            $stmt->execute([$firewall_id]);
            break;
    }
    
    // Update firewall status
    $stmt = db()->prepare("UPDATE firewalls SET status = ? WHERE id = ?");
    $stmt->execute([$firewall_status, $firewall_id]);
    
    // Log the update status
    $stmt = db()->prepare("
        INSERT INTO system_logs (firewall_id, category, message, timestamp)
        VALUES (?, 'update', ?, NOW())
    ");
    $stmt->execute([$firewall_id, "Update status: $status - $message"]);
    
    // If update completed successfully, trigger version check
    if ($status === 'completed') {
        // This will be updated on next agent check-in with new version info
        $stmt = db()->prepare("UPDATE firewalls SET last_update_check = NULL WHERE id = ?");
        $stmt->execute([$firewall_id]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Update status recorded',
        'firewall_status' => $firewall_status
    ]);
    
} catch (Exception $e) {
    error_log("Update status error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error recording update status']);
}
?>