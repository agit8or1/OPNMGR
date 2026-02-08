<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/../inc/db.php';

$firewall_id = $_GET['firewall_id'] ?? null;

if (!$firewall_id) {
    echo "0";
    exit;
}

try {
    $stmt = $DB->prepare("SELECT update_requested FROM firewalls WHERE id = ?");
    $stmt->execute([$firewall_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo $result ? ($result['update_requested'] ? '1' : '0') : '0';
    
} catch (Exception $e) {
    echo "0";
}
?>