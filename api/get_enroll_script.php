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

// Get the panel URL from configuration, falling back to SERVER_NAME (from the
// web server config) rather than HTTP_HOST, which is user-controlled. The old
// fallback here was the maintainer's own hostname, so a misconfigured install
// silently enrolled firewalls against somebody else's manager.
require_once __DIR__ . '/../inc/server_identity.php';
$panel_url   = opnmgr_server_url();
$server_name = opnmgr_server_host();
if ($server_name === '') {
    http_response_code(500);
    echo "Error: this manager's URL is not configured.\n";
    echo "Set the server_url setting, or APP_URL in .env, to this installation's address.\n";
    exit;
}

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

// This installation's own enrollment key, generated on first use. Enrollment
// must not proceed without it: the alternative is authorising a key the
// operator does not hold the private half of.
require_once __DIR__ . '/../inc/enrollment_key.php';
$server_ssh_key = opnmgr_enrollment_public_key();
if ($server_ssh_key === '') {
    http_response_code(500);
    echo "Error: this manager has no enrollment SSH key and could not generate one.\n";
    echo "Check that the key directory is writable and that ssh-keygen is installed.\n";
    exit;
}

// Replace placeholders
$script = str_replace('__SERVER_SSH_KEY__', $server_ssh_key, $script);
$script = str_replace('__PANEL_URL__', $panel_url, $script);
$script = str_replace('__ENROLLMENT_TOKEN__', $token, $script);
$script = str_replace('__MGMT_SERVER_IP__', $mgmt_ip, $script);

echo $script;
