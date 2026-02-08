<?php
/**
 * Add SSH Public Key to Proxy User
 * Receives public key from agent and adds to authorized_keys
 */

require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json');

// Verify agent key
$agent_key = $_SERVER['HTTP_X_AGENT_KEY'] ?? $_POST['agent_key'] ?? '';
if ($agent_key !== 'opnsense_agent_2024_secure') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$firewall_id = (int)($_POST['firewall_id'] ?? 0);
$public_key = trim($_POST['public_key'] ?? '');

if (!$firewall_id || !$public_key) {
    echo json_encode(['error' => 'Missing firewall_id or public_key']);
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

// Validate public key format
if (!preg_match('/^ssh-(rsa|dss|ed25519|ecdsa) [A-Za-z0-9+\/=]+ .*$/', $public_key)) {
    echo json_encode(['error' => 'Invalid public key format']);
    exit;
}

// Add comment with firewall info
$key_with_comment = trim($public_key) . ' fw' . $firewall_id . '_' . preg_replace('/[^a-z0-9]/', '_', strtolower($firewall['hostname']));

// Add to authorized_keys
$authorized_keys_file = '/home/proxy/.ssh/authorized_keys';
$current_keys = file_exists($authorized_keys_file) ? file_get_contents($authorized_keys_file) : '';

// Check if key already exists
if (strpos($current_keys, trim($public_key)) !== false) {
    echo json_encode([
        'status' => 'already_exists',
        'message' => 'Public key already in authorized_keys'
    ]);
    exit;
}

// Append new key
file_put_contents($authorized_keys_file, $current_keys . "\n" . $key_with_comment . "\n");
chmod($authorized_keys_file, 0600);
chown($authorized_keys_file, 'proxy');
chgrp($authorized_keys_file, 'proxy');

// Log the addition
$details = json_encode([
    'firewall_id' => $firewall_id,
    'hostname' => $firewall['hostname'],
    'key_type' => explode(' ', $public_key)[0]
]);

$DB->prepare('INSERT INTO system_logs (category, message, details, firewall_id, created_at) VALUES (?, ?, ?, ?, NOW())')
   ->execute(['proxy', 'SSH public key added for tunnel authentication', $details, $firewall_id]);

echo json_encode([
    'status' => 'success',
    'message' => 'Public key added to authorized_keys',
    'firewall_id' => $firewall_id,
    'hostname' => $firewall['hostname']
]);
