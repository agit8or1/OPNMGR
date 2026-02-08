<?php
/**
 * Agent Check for Pending Updates
 * Returns pending update if available
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
$firewall_id = (int)($data['firewall_id'] ?? 0);
$current_version = $data['agent_version'] ?? '0.0.0';

if (!$firewall_id) {
    echo json_encode(['error' => 'Missing firewall_id']);
    exit;
}

// Check for pending update
$stmt = $DB->prepare('
    SELECT id, to_version, update_script, created_at
    FROM agent_updates
    WHERE firewall_id = ?
    AND status = "pending"
    ORDER BY created_at DESC
    LIMIT 1
');
$stmt->execute([$firewall_id]);
$update = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$update) {
    echo json_encode([
        'update_available' => false,
        'current_version' => $current_version
    ]);
    exit;
}

// Mark as downloading
$DB->prepare('UPDATE agent_updates SET status = ?, started_at = NOW() WHERE id = ?')
   ->execute(['downloading', $update['id']]);

// Log update initiation
$DB->prepare('INSERT INTO system_logs (category, message, details, firewall_id, created_at) VALUES (?, ?, ?, ?, NOW())')
   ->execute(['agent', 'Agent update initiated: v' . $current_version . ' → v' . $update['to_version'], 
              json_encode(['update_id' => $update['id']]), $firewall_id]);

echo json_encode([
    'update_available' => true,
    'current_version' => $current_version,
    'new_version' => $update['to_version'],
    'update_id' => $update['id'],
    'update_script' => $update['update_script'],
    'instructions' => [
        'Save update script to /tmp/agent_update.sh',
        'Execute: bash /tmp/agent_update.sh',
        'Script will download, install, and restart with new version'
    ]
]);
