<?php
// Agent v3.2.0 download endpoint
header('Content-Type: text/plain');
header('Content-Disposition: inline; filename="opnsense_agent_v3.2.sh"');

$agent_file = __DIR__ . '/opnsense_agent_v3.2.sh';

if (!file_exists($agent_file)) {
    http_response_code(404);
    echo "Agent file not found";
    exit;
}

readfile($agent_file);
