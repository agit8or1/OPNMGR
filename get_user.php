<?php
require_once 'inc/auth.php';
requireAdmin();

if (!isset($_GET['id'])) {
    http_response_code(400);
    exit;
}

$userId = $_GET['id'];
$user = getUserById($userId);

if (!$user) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/json');
echo json_encode($user);
?>
