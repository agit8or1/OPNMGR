<?php
require_once 'inc/auth.php';
require_once 'inc/db.php';
requireAdmin();

if (!isset($_GET['id'])) {
    http_response_code(400);
    exit;
}

$customerId = (int)$_GET['id'];

try {
    $stmt = $DB->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $customer = null;
}

if (!$customer) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/json');
echo json_encode($customer);
?>