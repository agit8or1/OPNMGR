<?php
/**
 * Serve the enrollment script with token substituted
 */

header('Content-Type: text/plain');
header('Content-Disposition: attachment; filename="opnsense_enroll.sh"');

$token = $_GET['token'] ?? '';

if (!$token) {
    http_response_code(400);
    echo "Error: Missing enrollment token";
    exit;
}

// Get the panel URL - use SERVER_NAME (from Apache config) instead of HTTP_HOST (user-controlled)
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$server_name = $_SERVER['SERVER_NAME'] ?? 'opn.agit8or.net';
$panel_url = $protocol . '://' . $server_name;

// Read the simple enrollment script
$script = file_get_contents(__DIR__ . '/../simple_enroll.sh');

// The enrollment script adds a firewall rule permitting SSH from this manager,
// so it needs this manager's address. Resolve the name the firewall just
// fetched from; fall back to the address it connected to.
$mgmt_ip = filter_var($server_name, FILTER_VALIDATE_IP) ? $server_name : gethostbyname($server_name);
if (!filter_var($mgmt_ip, FILTER_VALIDATE_IP)) {
    $mgmt_ip = $_SERVER['SERVER_ADDR'] ?? '';
}
if (!filter_var($mgmt_ip, FILTER_VALIDATE_IP)) {
    http_response_code(500);
    echo "Error: could not determine this server's address for the SSH rule.\n";
    echo "Set SERVER_NAME to a resolvable host in the web server configuration.\n";
    exit;
}

// Replace placeholders
$script = str_replace('__PANEL_URL__', $panel_url, $script);
$script = str_replace('__ENROLLMENT_TOKEN__', $token, $script);
$script = str_replace('__MGMT_SERVER_IP__', $mgmt_ip, $script);

echo $script;
