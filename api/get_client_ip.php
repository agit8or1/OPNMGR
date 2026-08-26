<?php
/**
 * Get Client IP Address
 * Returns the IP address of the connecting client
 * Used by firewalls during enrollment to detect their WAN IP
 */

header('Content-Type: text/plain');

// Get the real client IP (accounting for proxies)
// Firewalls call this during enrollment to learn their own WAN address, so it
// must report what we actually see. Client-Ip and X-Forwarded-For are set by
// the caller, and were previously preferred over REMOTE_ADDR - meaning the
// answer could be anything the caller wanted. X-Forwarded-For is now honoured
// only when the immediate peer is the local reverse proxy.
$ip     = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocal = in_array($ip, ['127.0.0.1', '::1'], true);

if ($isLocal && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    if (filter_var($first, FILTER_VALIDATE_IP)) {
        $ip = $first;
    }
}

echo filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
