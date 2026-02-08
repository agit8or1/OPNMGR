<?php
/**
 * Agent Syntax Fix and Restart
 * Download fixed agent and restart
 */

// Simple authentication
$auth_key = $_GET['auth'] ?? '';
if ($auth_key !== 'fix_agent_syntax_2025') {
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
header('Content-Disposition: attachment; filename="fix_agent_syntax.sh"');

echo "#!/bin/sh

# Agent Syntax Fix Script
echo \"Fixing agent syntax error and restarting...\"

# Kill any running agents
killall -9 opnsense_agent.sh opnsense_agent_v2.sh python python3 2>/dev/null
pkill -f \"opnsense_agent\" 2>/dev/null

# Download the fixed agent
echo \"Downloading fixed agent v2.3...\"
if fetch -q -o /usr/local/bin/opnsense_agent_v2.sh \"https://opn.agit8or.net/download_tunnel_agent.php?firewall_id=" . $firewall_id . "\"; then
    echo \"Fixed agent downloaded successfully\"
    
    # Make it executable
    chmod +x /usr/local/bin/opnsense_agent_v2.sh
    
    # Verify the syntax
    if sh -n /usr/local/bin/opnsense_agent_v2.sh; then
        echo \"Agent syntax is valid\"
        
        # Start the fixed agent
        echo \"Starting fixed agent...\"
        /usr/local/bin/opnsense_agent_v2.sh &
        
        # Wait a moment
        sleep 3
        
        # Check if it's running
        if ps aux | grep -v grep | grep opnsense_agent_v2.sh >/dev/null; then
            echo \"Fixed agent is running successfully\"
            echo \"Agent should report version 2.3.0 on next checkin\"
        else
            echo \"WARNING: Agent may not be running properly\"
        fi
    else
        echo \"ERROR: Agent syntax still invalid\"
        exit 1
    fi
else
    echo \"ERROR: Failed to download fixed agent\"
    exit 1
fi

echo \"Agent syntax fix completed at \$(date)\"
";
?>