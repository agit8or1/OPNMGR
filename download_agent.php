<?php
// Direct agent download - bypass redirects
$file = '/var/www/opnsense/downloads/opnsense_agent_v3.6.1.sh';

if (!file_exists($file)) {
    http_response_code(404);
    die('File not found');
}

header('Content-Type: application/x-sh');
header('Content-Disposition: attachment; filename="opnsense_agent_v3.6.1.sh"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: no-cache');

readfile($file);
exit;
