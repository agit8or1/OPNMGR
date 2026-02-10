<?php
/**
 * Agent Complete Setup and Monitor
 * Properly set up agent with cron scheduling
 */

// Simple authentication
$auth_key = $_GET['auth'] ?? '';
if ($auth_key !== 'setup_agent_cron_2025') {
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
header('Content-Disposition: attachment; filename="setup_agent_cron.sh"');

echo "#!/bin/sh

# Agent Complete Setup Script
echo \"Setting up agent v2.3 with proper cron scheduling...\"

# Kill any running agents
killall -9 opnsense_agent.sh opnsense_agent_v2.sh python python3 2>/dev/null
pkill -f \"opnsense_agent\" 2>/dev/null

# Clear crontab
crontab -r 2>/dev/null || true

# Ensure we have the latest agent
echo \"Verifying agent v2.3...\"
if [ ! -f /usr/local/bin/opnsense_agent_v2.sh ]; then
    echo \"Downloading agent...\"
    fetch -q -o /usr/local/bin/opnsense_agent_v2.sh \"https://opn.agit8or.net/download_tunnel_agent.php?firewall_id=" . $firewall_id . "\"
fi

chmod +x /usr/local/bin/opnsense_agent_v2.sh

# Verify syntax
if ! sh -n /usr/local/bin/opnsense_agent_v2.sh; then
    echo \"ERROR: Agent syntax invalid\"
    exit 1
fi

# Run the agent once to initialize and set up cron
echo \"Running agent to initialize...\"
/usr/local/bin/opnsense_agent_v2.sh

# Verify cron was set up
echo \"Checking cron setup...\"
if crontab -l 2>/dev/null | grep -q opnsense_agent_v2.sh; then
    echo \"SUCCESS: Cron job set up properly\"
    crontab -l
else
    echo \"WARNING: Cron not set up, manually adding...\"
    echo \"*/5 * * * * /usr/local/bin/opnsense_agent_v2.sh\" | crontab -
    echo \"Manual cron job added\"
fi

# Force an immediate run to update database
echo \"Running immediate checkin...\"
/usr/local/bin/opnsense_agent_v2.sh &

# Wait a moment for checkin
sleep 10

echo \"Agent setup completed at \$(date)\"
echo \"Agent should now check in every 5 minutes via cron\"
echo \"Next checkin should show version 2.3.0\"
";
?>