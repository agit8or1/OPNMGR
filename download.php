<?php
// Public download page - no authentication required
$file = '/var/www/opnsense/downloads_public.zip';
if (file_exists($file)) {
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="opnsense_screenshots_' . date('Ymd') . '.zip"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
} else {
    http_response_code(404);
    echo "File not found";
}
