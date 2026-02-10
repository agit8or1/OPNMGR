<?php
// Download OPNsense Agent v3.4.0 with WAN interface auto-detection
require_once __DIR__ . '/inc/bootstrap_agent.php';

// Get firewall ID from query parameter
$firewall_id = $_GET['firewall_id'] ?? '';
$version = $_GET['version'] ?? '3.4.1'; // Allow version override

if (empty($firewall_id)) {
    http_response_code(400);
    die('Error: Firewall ID required');
}

// Verify firewall exists
$stmt = db()->prepare("SELECT id, hostname FROM firewalls WHERE id = ?");
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$firewall) {
    http_response_code(404);
    die('Error: Firewall not found');
}

// Determine which agent version to serve
$agent_versions = [
    '3.4.1' => __DIR__ . '/download/opnsense_agent_v3.4.1.sh',
    '3.4.0' => __DIR__ . '/download/opnsense_agent_v3.4.0.sh',
    '3.3.0' => __DIR__ . '/download/opnsense_agent_v3.3.0.sh',
    '3.2.1' => __DIR__ . '/download/opnsense_agent_v3.2.1.sh',
    '3.2.0' => __DIR__ . '/download/opnsense_agent_v3.2.sh',
    '2.5.0' => __DIR__ . '/scripts/opnsense_agent_v2.5.0.sh',
    '2.4.0' => __DIR__ . '/opnsense_agent_v2.4.sh'
];

// Default to latest version
$agent_file = $agent_versions[$version] ?? $agent_versions['3.4.1'];

if (!file_exists($agent_file)) {
    http_response_code(404);
    die('Error: Agent file not found for version ' . htmlspecialchars($version));
}

$agent_script = file_get_contents($agent_file);

// Replace firewall ID in the script if placeholder exists
$agent_script = str_replace('FIREWALL_ID="21"', 'FIREWALL_ID="' . $firewall_id . '"', $agent_script);

// For newer agents that use FIREWALL_ID_FILE, we don't need to replace
// They auto-generate or use the file

// Set headers for file download
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="opnsense_agent_v' . $version . '.sh"');
header('Content-Length: ' . strlen($agent_script));

// Log the download
error_log("Agent download: firewall_id=$firewall_id, version=$version, file=" . basename($agent_file));

// Output the script
echo $agent_script;
?>
