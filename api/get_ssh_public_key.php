<?php
/**
 * Get SSH Public Key API
 * Returns the OPNManager server's SSH public key for a specific firewall
 * Used by the OPNManager Agent plugin during enrollment to enable SSH management
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../scripts/manage_ssh_keys.php';

header('Content-Type: application/json');

// Get firewall by hardware_id (from query param or POST)
$hardware_id = trim($_GET['hardware_id'] ?? $_POST['hardware_id'] ?? '');

// If no hardware_id, try to get it from the referring enrollment
// This endpoint is called right after enrollment, so we can look up by recent enrollment
if (empty($hardware_id)) {
    // Return error - need to know which firewall
    echo json_encode([
        'success' => false,
        'message' => 'Missing hardware_id parameter'
    ]);
    exit;
}

try {
    // Look up firewall by hardware_id
    $stmt = $DB->prepare('SELECT id, hostname FROM firewalls WHERE hardware_id = ?');
    $stmt->execute([$hardware_id]);
    $firewall = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$firewall) {
        echo json_encode([
            'success' => false,
            'message' => 'Firewall not found for this hardware_id'
        ]);
        exit;
    }

    $firewall_id = (int)$firewall['id'];
    $key_dir = '/var/www/opnsense/keys';
    $private_key_file = "{$key_dir}/id_firewall_{$firewall_id}";
    $public_key_file = "{$private_key_file}.pub";

    // Check if key already exists
    if (file_exists($public_key_file)) {
        $public_key = trim(file_get_contents($public_key_file));
        echo json_encode([
            'success' => true,
            'public_key' => $public_key,
            'firewall_id' => $firewall_id,
            'message' => 'Existing key retrieved'
        ]);
        exit;
    }

    // Generate new key pair using the manage_ssh_keys function
    $key_result = generate_ssh_keypair($firewall_id);

    if (!$key_result['success']) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to generate SSH key: ' . ($key_result['error'] ?? 'Unknown error')
        ]);
        exit;
    }

    // Store in database for future use
    update_firewall_ssh_key($firewall_id, $key_result['private_key_base64'], $key_result['public_key']);

    echo json_encode([
        'success' => true,
        'public_key' => $key_result['public_key'],
        'firewall_id' => $firewall_id,
        'message' => 'New key generated'
    ]);

} catch (Exception $e) {
    error_log('get_ssh_public_key.php error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}
