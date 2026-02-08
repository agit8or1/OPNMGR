<?php
header('Content-Type: application/octet-stream');
header('Cache-Control: no-cache');

// Get firewall ID from query parameter
$firewall_id = intval($_GET['firewall_id'] ?? 0);

if ($firewall_id <= 0) {
    http_response_code(400);
    die('Invalid firewall ID');
}

// Path to SSH private key
$key_path = "/opt/opnsense-tunnels/keys/firewall_${firewall_id}_key";

if (!file_exists($key_path)) {
    http_response_code(404);
    die('SSH key not found for this firewall');
}

// Read and output the private key
$private_key = file_get_contents($key_path);
if ($private_key === false) {
    http_response_code(500);
    die('Failed to read SSH key');
}

// Set appropriate headers for key download
header('Content-Disposition: attachment; filename="firewall_' . $firewall_id . '_key"');
header('Content-Length: ' . strlen($private_key));

echo $private_key;
?>