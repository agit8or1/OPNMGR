<?php
/**
 * Get Client IP Address
 * Returns the IP address of the connecting client
 * Used by firewalls during enrollment to detect their WAN IP
 */

header('Content-Type: text/plain');

// Get the real client IP (accounting for proxies)
$ip = '';

if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
    $ip = $_SERVER['HTTP_CLIENT_IP'];
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    // Handle multiple IPs in X-Forwarded-For
    $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    $ip = trim($ips[0]);
} elseif (!empty($_SERVER['REMOTE_ADDR'])) {
    $ip = $_SERVER['REMOTE_ADDR'];
}

echo trim($ip);
