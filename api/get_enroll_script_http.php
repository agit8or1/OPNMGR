<?php
/**
 * HTTP-only enrollment script endpoint
 * Used by firewalls that have SSL/HTTPS issues
 */

// Read the enrollment script
$script_path = '/var/www/opnsense/simple_enroll.sh';

if (!file_exists($script_path)) {
    http_response_code(404);
    echo "Enrollment script not found";
    exit;
}

// Get token from query string
$token = isset($_GET['token']) ? $_GET['token'] : '';

if (empty($token)) {
    http_response_code(400);
    echo "No token provided";
    exit;
}

// Read the script
$script = file_get_contents($script_path);

// Get the manager URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$manager_url = $protocol . '://' . $host;

// Replace placeholders
$script = str_replace('__PANEL_URL__', $manager_url, $script);
$script = str_replace('__ENROLLMENT_TOKEN__', $token, $script);

// Return as text/plain
header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="opnsense_enroll.sh"');
header('Content-Length: ' . strlen($script));

echo $script;
?>
