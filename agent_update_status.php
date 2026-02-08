<?php
/**
 * Agent Update Status Reporter
 * Allows agent to report update progress
 */

require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json');

// Verify agent key
$agent_key = $_SERVER['HTTP_X_AGENT_KEY'] ?? '';
if ($agent_key !== 'opnsense_agent_2024_secure') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$update_id = (int)($data['update_id'] ?? 0);
$status = $data['status'] ?? '';
$error_message = $data['error_message'] ?? null;

if (!$update_id || !$status) {
    echo json_encode(['error' => 'Missing update_id or status']);
    exit;
}

// Valid statuses
$valid_statuses = ['pending', 'downloading', 'installing', 'completed', 'failed'];
if (!in_array($status, $valid_statuses)) {
    echo json_encode(['error' => 'Invalid status']);
    exit;
}

// Update status
if ($status === 'completed') {
    $DB->prepare('UPDATE agent_updates SET status = ?, completed_at = NOW(), error_message = ? WHERE id = ?')
       ->execute([$status, $error_message, $update_id]);
} elseif ($status === 'failed') {
    $DB->prepare('UPDATE agent_updates SET status = ?, completed_at = NOW(), error_message = ? WHERE id = ?')
       ->execute([$status, $error_message, $update_id]);
} else {
    $DB->prepare('UPDATE agent_updates SET status = ?, error_message = ? WHERE id = ?')
       ->execute([$status, $error_message, $update_id]);
}

// Get update details for logging
$stmt = $DB->prepare('SELECT firewall_id, from_version, to_version FROM agent_updates WHERE id = ?');
$stmt->execute([$update_id]);
$update = $stmt->fetch(PDO::FETCH_ASSOC);

if ($update) {
    $message = "Agent update $status: v{$update['from_version']} → v{$update['to_version']}";
    if ($error_message) {
        $message .= " - Error: $error_message";
    }
    
    $DB->prepare('INSERT INTO system_logs (category, message, details, firewall_id, created_at) VALUES (?, ?, ?, ?, NOW())')
       ->execute(['agent', $message, json_encode(['update_id' => $update_id, 'status' => $status]), $update['firewall_id']]);
}

echo json_encode([
    'success' => true,
    'update_id' => $update_id,
    'status' => $status
]);
