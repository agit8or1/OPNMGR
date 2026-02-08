<?php
/**
 * Download Update Agent
 * 
 * This endpoint serves the failsafe update agent to firewalls.
 * The update agent is NEVER updated via commands - only manually via SSH.
 * It's responsible for recovering/updating the primary agent.
 */

require_once __DIR__ . '/inc/db.php';

// Get firewall ID
$firewall_id = (int)($_GET['firewall_id'] ?? 0);

if (!$firewall_id) {
    http_response_code(400);
    die('Missing firewall_id parameter');
}

// Verify firewall exists
$stmt = $DB->prepare('SELECT id, hostname FROM firewalls WHERE id = ?');
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$firewall) {
    http_response_code(404);
    die('Firewall not found');
}

// Read the update agent template
$agent_template = file_get_contents(__DIR__ . '/opnsense_update_agent.sh');

if ($agent_template === false) {
    http_response_code(500);
    die('Failed to read update agent template');
}

// Replace firewall-specific placeholders
$agent_script = str_replace('__FIREWALL_ID__', $firewall_id, $agent_template);

// Log the download
error_log("Update agent downloaded for firewall {$firewall_id} ({$firewall['hostname']})");

// Send the script with appropriate headers
header('Content-Type: application/x-sh');
header('Content-Disposition: attachment; filename="opnsense_update_agent.sh"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo $agent_script;
