<?php
/**
 * Emergency Agent Update Endpoint
 * Direct agent replacement without relying on updater
 */

// Simple authentication
$auth_key = $_GET['auth'] ?? '';
if ($auth_key !== 'emergency_agent_update_2025') {
    http_response_code(403);
    die('Access denied');
}

$firewall_id = $_GET['firewall_id'] ?? '';
if (empty($firewall_id)) {
    http_response_code(400);
    die('Missing firewall_id');
}

// Return shell script for direct execution
header('Content-Type: text/plain');
header('Content-Disposition: attachment; filename="emergency_agent_update.sh"');

echo "#!/bin/sh

# Emergency Agent Update Script
echo \"Emergency agent update starting...\"

# Kill old agent processes
killall -9 opnsense_agent.sh opnsense_agent python 2>/dev/null

# Download new agent
echo \"Downloading agent v2.3...\"
if fetch -q -o /usr/local/bin/opnsense_agent_v2.sh \"https://opn.agit8or.net/download_tunnel_agent.php?firewall_id=" . $firewall_id . "\"; then
    echo \"Agent downloaded successfully\"
    
    # Replace old agent files and make executable
    chmod +x /usr/local/bin/opnsense_agent_v2.sh
    
    # Also create symlink for legacy name if it exists
    if [ -f /usr/local/bin/opnsense_agent.sh ]; then
        rm -f /usr/local/bin/opnsense_agent.sh
    fi
    ln -sf /usr/local/bin/opnsense_agent_v2.sh /usr/local/bin/opnsense_agent.sh
    
    # Start new agent (v2.3 runs main() directly)
    echo \"Starting agent...\"
    /usr/local/bin/opnsense_agent_v2.sh >/dev/null 2>&1 &
    if [ \$? -eq 0 ]; then
        echo \"Agent v2.3 started successfully\"
        echo \"Agent update completed at \$(date)\"
    else
        echo \"Agent start failed\"
        exit 1
    fi
else
    echo \"Failed to download new agent\"
    exit 1
fi

echo \"Emergency agent update completed successfully\"
";
?>