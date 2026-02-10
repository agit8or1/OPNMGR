#!/usr/bin/env php
<?php
/**
 * Create OPNmgr Alias on Firewall
 * 
 * This script creates a firewall alias named "OPNmgr" containing the
 * OPNManager server's IP address for easier reference in firewall rules.
 * 
 * Usage: php create_opnmgr_alias.php <firewall_id>
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';

if ($argc < 2) {
    die("Usage: php create_opnmgr_alias.php <firewall_id>\n");
}

$firewall_id = (int)$argv[1];

// Get OPNManager server IP
$opnmgr_ip = trim(shell_exec('curl -s ifconfig.me'));
if (empty($opnmgr_ip) || !filter_var($opnmgr_ip, FILTER_VALIDATE_IP)) {
    die("Error: Could not determine OPNManager server IP address\n");
}

echo "OPNManager server IP: {$opnmgr_ip}\n";

// Get firewall details
$stmt = db()->prepare("SELECT hostname, wan_ip, api_key, api_secret FROM firewalls WHERE id = ?");
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch();

if (!$firewall) {
    die("Error: Firewall ID {$firewall_id} not found\n");
}

echo "Target firewall: {$firewall['hostname']} ({$firewall['wan_ip']})\n";

// OPNsense API endpoint for aliases
$api_url = "https://{$firewall['hostname']}/api/firewall/alias/addItem";

// Prepare alias data
$alias_data = [
    'alias' => [
        'enabled' => '1',
        'name' => 'OPNmgr',
        'type' => 'host',
        'description' => 'OPNManager Server - Auto-created for easier rule management',
        'content' => $opnmgr_ip,
        'proto' => '',
        'updatefreq' => ''
    ]
];

echo "Creating alias 'OPNmgr' with IP {$opnmgr_ip}...\n";

// Execute API call
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_USERPWD, $firewall['api_key'] . ':' . $firewall['api_secret']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($alias_data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code != 200) {
    echo "Error: API call failed with HTTP code {$http_code}\n";
    echo "Response: {$response}\n";
    exit(1);
}

$result = json_decode($response, true);
echo "Response: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";

if (isset($result['result']) && $result['result'] === 'saved') {
    echo "✅ Alias 'OPNmgr' created successfully!\n";
    
    // Apply configuration
    echo "Applying firewall configuration...\n";
    $apply_url = "https://{$firewall['hostname']}/api/firewall/alias/reconfigure";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apply_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERPWD, $firewall['api_key'] . ':' . $firewall['api_secret']);
    curl_setopt($ch, CURLOPT_POST, true);
    
    $apply_response = curl_exec($ch);
    $apply_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($apply_http_code == 200) {
        echo "✅ Configuration applied successfully!\n";
        echo "\nYou can now use 'OPNmgr' in firewall rules instead of {$opnmgr_ip}\n";
    } else {
        echo "⚠️ Warning: Failed to apply configuration (HTTP {$apply_http_code})\n";
    }
} else {
    echo "❌ Failed to create alias\n";
    exit(1);
}
