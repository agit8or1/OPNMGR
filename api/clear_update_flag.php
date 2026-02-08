<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/../inc/db.php';

$firewall_id = $_GET['firewall_id'] ?? null;

if (!$firewall_id) {
    echo "ERROR";
    exit;
}

try {
    // Clear update flag and set status back to online
    $stmt = $DB->prepare("
        UPDATE firewalls 
        SET update_requested = 0, status = 'online' 
        WHERE id = ?
    ");
    $stmt->execute([$firewall_id]);
    
    echo "OK";
    
} catch (Exception $e) {
    echo "ERROR";
}
?>