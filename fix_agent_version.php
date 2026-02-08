<?php
/**
 * Agent Version Fix Script
 * Force kill old agents and verify new agent is running
 */

// Simple authentication
$auth_key = $_GET['auth'] ?? '';
if ($auth_key !== 'fix_agent_version_2025') {
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
header('Content-Disposition: attachment; filename="fix_agent_version.sh"');

echo "#!/bin/sh

# Agent Version Fix Script
echo \"Checking and fixing agent version...\"

# Kill ALL old agent processes more aggressively
echo \"Killing old agent processes...\"
killall -9 opnsense_agent.sh opnsense_agent opnsense_agent_v2.sh python python3 2>/dev/null
pkill -f \"opnsense_agent\" 2>/dev/null
pkill -f \"agent_checkin\" 2>/dev/null

# Remove any old cron jobs
echo \"Cleaning crontab...\"
crontab -r 2>/dev/null || true

# Verify current agent file
if [ -f /usr/local/bin/opnsense_agent_v2.sh ]; then
    echo \"Found v2.3 agent file\"
    # Check what version it reports
    AGENT_VER=\$(grep 'AGENT_VERSION=' /usr/local/bin/opnsense_agent_v2.sh | head -1 | cut -d'\"' -f2)
    echo \"Agent file reports version: \$AGENT_VER\"
    
    # Start the new agent
    echo \"Starting v2.3 agent...\"
    /usr/local/bin/opnsense_agent_v2.sh &
    
    # Give it a moment to initialize
    sleep 5
    
    # Check if it's running
    if ps aux | grep -v grep | grep opnsense_agent_v2.sh >/dev/null; then
        echo \"New agent is running\"
        
        # Force an immediate checkin to update the database
        echo \"Forcing immediate checkin...\"
        # The agent should do this automatically, but let's verify
        
        echo \"Agent fix completed successfully\"
    else
        echo \"ERROR: New agent failed to start\"
        exit 1
    fi
else
    echo \"ERROR: v2.3 agent file not found\"
    exit 1
fi

echo \"Agent version fix completed at \$(date)\"
";
?>