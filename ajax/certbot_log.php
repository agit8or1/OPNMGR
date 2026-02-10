<?php
require_once __DIR__ . '/../inc/bootstrap.php';
requireLogin();
$file = '/var/log/opnmgr/last_certbot.log';
if (!file_exists($file)) { header('HTTP/1.1 404 Not Found'); echo 'No log'; exit; }
// serve as plain text
header('Content-Type: text/plain');
readfile($file);
exit;
