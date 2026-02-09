<?php
require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Support both POST and GET requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $firewall_id = $_POST['firewall_id'] ?? null;
        $hardware_id = trim($_POST['hardware_id'] ?? '');
        $status_filter = 'sent'; // Original behavior for POST
        $limit = 50;
    } else {
        $firewall_id = $_GET['firewall_id'] ?? null;
        $hardware_id = trim($_GET['hardware_id'] ?? '');
        $status_filter = null; // Show all statuses for GET
        $limit = (int)($_GET['limit'] ?? 10);
    }

    $firewall_id = (int)$firewall_id;
    if (!$firewall_id || empty($hardware_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing authentication']);
        exit;
    }

    $auth_stmt = $DB->prepare('SELECT hardware_id FROM firewalls WHERE id = ?');
    $auth_stmt->execute([$firewall_id]);
    $auth_fw = $auth_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$auth_fw || (
        !empty($auth_fw['hardware_id']) && !hash_equals($auth_fw['hardware_id'], $hardware_id)
    )) {
        http_response_code(403);
        echo json_encode(['error' => 'Authentication failed']);
        exit;
    }
    
    // Get commands for this firewall
    if ($status_filter) {
        $stmt = $DB->prepare("SELECT id, command, description, status, created_at FROM firewall_commands WHERE firewall_id = ? AND status = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$firewall_id, $status_filter, $limit]);
    } else {
        $stmt = $DB->prepare("SELECT id, command, description, status, created_at FROM firewall_commands WHERE firewall_id = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$firewall_id, $limit]);
    }
    $commands = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'commands' => $commands]);
    
} catch (PDOException $e) {
    error_log("get_commands.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>