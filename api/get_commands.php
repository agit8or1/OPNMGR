<?php
require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Support both POST and GET requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $firewall_id = $_POST['firewall_id'] ?? null;
        $status_filter = 'sent'; // Original behavior for POST
        $limit = 50;
    } else {
        $firewall_id = $_GET['firewall_id'] ?? null;
        $status_filter = null; // Show all statuses for GET
        $limit = (int)($_GET['limit'] ?? 10);
    }
    
    if (!$firewall_id) {
        echo json_encode(['error' => 'firewall_id required']);
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
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>