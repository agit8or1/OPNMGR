<?php
// Simple proxy redirect for firewall access
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';
requireLogin();

$firewall_id = (int)($_GET['id'] ?? 0);

if (!$firewall_id) {
    http_response_code(404);
    echo "Firewall not found";
    exit;
}

// Get firewall details
$stmt = $DB->prepare("SELECT hostname, ip_address FROM firewalls WHERE id = ?");
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch();

if (!$firewall) {
    http_response_code(404);
    echo "Firewall not found";
    exit;
}

// Simple redirect to firewall IP
header("Location: https://" . $firewall['ip_address']);
exit;
?>