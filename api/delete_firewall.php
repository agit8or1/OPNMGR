<?php
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/csrf.php';

header('Content-Type: application/json');

// Require login and admin privileges
requireLogin();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Verify CSRF token
if (!csrf_verify($_POST['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF verification failed']);
    exit;
}

$delete_id = (int)($_POST['delete_id'] ?? 0);

if ($delete_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid firewall ID']);
    exit;
}

try {
    $DB->beginTransaction();
    
    // Delete related records first to avoid foreign key constraints
    
    // Delete firewall agents
    $stmt = $DB->prepare('DELETE FROM firewall_agents WHERE firewall_id = ?');
    $stmt->execute([$delete_id]);
    
    // Delete firewall tags
    $stmt = $DB->prepare('DELETE FROM firewall_tags WHERE firewall_id = ?');
    $stmt->execute([$delete_id]);
    
    // Finally delete the firewall
    $stmt = $DB->prepare('DELETE FROM firewalls WHERE id = ?');
    $stmt->execute([$delete_id]);
    
    if ($stmt->rowCount() > 0) {
        $DB->commit();
        echo json_encode(['success' => true, 'message' => 'Firewall deleted successfully']);
    } else {
        $DB->rollback();
        echo json_encode(['success' => false, 'message' => 'Firewall not found']);
    }
    
} catch (Exception $e) {
    $DB->rollback();
    echo json_encode(['success' => false, 'message' => 'Error deleting firewall: ' . $e->getMessage()]);
}
?>
