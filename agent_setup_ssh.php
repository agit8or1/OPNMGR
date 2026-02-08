<?php
/**
 * Agent SSH Setup API
 * Generates SSH key on firewall and returns public key for authorized_keys
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

// Get firewall_id from POST
$data = json_decode(file_get_contents('php://input'), true);
$firewall_id = (int)($data['firewall_id'] ?? 0);

if (!$firewall_id) {
    echo json_encode(['error' => 'Missing firewall_id']);
    exit;
}

// Verify firewall exists
$stmt = $DB->prepare('SELECT id, hostname FROM firewalls WHERE id = ?');
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$firewall) {
    echo json_encode(['error' => 'Firewall not found']);
    exit;
}

// Return success with instructions
echo json_encode([
    'status' => 'ready',
    'message' => 'Generate SSH key on firewall and send public key to add_ssh_key.php',
    'commands' => [
        'Generate key: ssh-keygen -t ed25519 -f /root/.ssh/opnsense_proxy_key -N ""',
        'Send public key: curl -X POST -H "X-Agent-Key: opnsense_agent_2024_secure" -d "firewall_id=' . $firewall_id . '&public_key=$(cat /root/.ssh/opnsense_proxy_key.pub)" https://opn.agit8or.net/add_ssh_key.php'
    ]
]);
