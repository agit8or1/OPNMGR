<?php
require_once __DIR__ . '/../inc/bootstrap_agent.php';

header('Content-Type: text/plain');
$firewall_id = (int)($_GET['firewall_id'] ?? 0);
$hardware_id = trim($_GET['hardware_id'] ?? '');

if (!$firewall_id || empty($hardware_id)) {
    echo "0";
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
        echo "0";
        exit;
    }
} catch (Exception $e) {
    echo "0";
    exit;
}

try {
    $stmt = db()->prepare("SELECT update_requested FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    echo $result ? ($result['update_requested'] ? '1' : '0') : '0';

} catch (Exception $e) {
    echo "0";
}
?>