<?php
require_once __DIR__ . '/../inc/bootstrap_agent.php';

header('Content-Type: text/plain');
$firewall_id = (int)($_GET['firewall_id'] ?? 0);
$hardware_id = trim($_GET['hardware_id'] ?? '');

if (!$firewall_id || empty($hardware_id)) {
    echo "ERROR";
    exit;
}

// Validate agent identity
try {
    $auth_stmt = db()->prepare('SELECT hardware_id FROM firewalls WHERE id = ?');
    $auth_stmt->execute([$firewall_id]);
    $auth_fw = $auth_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$auth_fw || (
        !empty($auth_fw['hardware_id']) && !hash_equals($auth_fw['hardware_id'], $hardware_id)
    )) {
        echo "ERROR";
        exit;
    }
} catch (Exception $e) {
    echo "ERROR";
    exit;
}

try {
    // Clear update flag and set status back to online
    $stmt = db()->prepare("
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