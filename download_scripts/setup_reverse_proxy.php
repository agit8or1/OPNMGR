<?php
// Download endpoint for reverse proxy setup script
$script_file = __DIR__ . '/../setup_reverse_proxy.sh';

if (!file_exists($script_file)) {
    http_response_code(404);
    echo "Setup script not found";
    exit;
}

// Set appropriate headers for shell script download
header('Content-Type: application/x-sh');
header('Content-Disposition: attachment; filename="setup_reverse_proxy.sh"');
header('Content-Length: ' . filesize($script_file));

// Output the file
readfile($script_file);
?>