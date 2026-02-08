<?php
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit;
}

$hardware_id = trim($input['hardware_id'] ?? '');
$hostname = trim($input['hostname'] ?? '');

// Validate inputs
if (empty($hardware_id) && empty($hostname)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Hardware ID or hostname required']);
    exit;
}

try {
    // Try to find firewall by hardware_id first, then by hostname
    if (!empty($hardware_id)) {
        $stmt = $DB->prepare('SELECT id FROM firewalls WHERE hardware_id = ?');
        $stmt->execute([$hardware_id]);
        $firewall = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (empty($firewall) && !empty($hostname)) {
        $stmt = $DB->prepare('SELECT id FROM firewalls WHERE hostname = ?');
        $stmt->execute([$hostname]);
        $firewall = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if ($firewall) {
        echo json_encode([
            'success' => true,
            'firewall_id' => (int)$firewall['id']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Firewall not found'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>