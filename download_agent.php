<?php
/**
 * Download Latest Agent Script
 * Serves the latest agent version for deployment
 */

require_once __DIR__ . '/inc/db.php';

// Verify agent key
$agent_key = $_SERVER['HTTP_X_AGENT_KEY'] ?? $_GET['key'] ?? '';
if ($agent_key !== 'opnsense_agent_2024_secure') {
    http_response_code(401);
    die('Unauthorized');
}

$version = $_GET['version'] ?? '2.5.0';
$firewall_id = (int)($_GET['firewall_id'] ?? 0);

// Map versions to files
$agent_files = [
    '2.4.0' => '/var/www/opnsense/scripts/opnsense_agent.sh',
    '2.5.0' => '/var/www/opnsense/scripts/opnsense_agent_v2.5.0.sh'
];

$agent_file = $agent_files[$version] ?? null;

if (!$agent_file || !file_exists($agent_file)) {
    http_response_code(404);
    die('Agent version not found');
}

// Log the download
if ($firewall_id) {
    $DB->prepare('INSERT INTO system_logs (category, message, details, firewall_id, created_at) VALUES (?, ?, ?, ?, NOW())')
       ->execute(['agent', "Agent v{$version} downloaded", json_encode(['version' => $version]), $firewall_id]);
}

// Serve the file
header('Content-Type: text/plain');
header('Content-Disposition: attachment; filename="opnsense_agent_v' . $version . '.sh"');
header('X-Agent-Version: ' . $version);

readfile($agent_file);
