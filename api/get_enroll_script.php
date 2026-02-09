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

// Replace placeholders
$script = str_replace('__PANEL_URL__', $panel_url, $script);
$script = str_replace('__ENROLLMENT_TOKEN__', $token, $script);

echo $script;
