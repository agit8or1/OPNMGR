<?php
header('Content-Type: text/plain');

// Check if firewall_id is provided
$firewall_id = $_GET['firewall_id'] ?? null;

if (!$firewall_id) {
    echo "ERROR: Missing firewall_id parameter\n";
    exit(1);
}

// Generate the agent script content
$tunnel_agent_content = file_get_contents('/var/www/opnsense/download/tunnel_agent.sh');

if (!$tunnel_agent_content) {
    echo "ERROR: Could not read tunnel agent script\n";
    exit(1);
}

// Replace the firewall ID in the script
$tunnel_agent_content = preg_replace('/FIREWALL_ID="[^"]*"/', 'FIREWALL_ID="' . $firewall_id . '"', $tunnel_agent_content);

// Return a complete reinstall script
echo '#!/bin/sh
# Complete Agent Reinstall Script
echo "$(date): Starting complete agent reinstall..."

# Kill all existing agents
echo "$(date): Stopping all existing agents..."
pkill -f tunnel_agent
pkill -f opnsense_agent  
sleep 3

# Force kill if needed
pkill -9 -f tunnel_agent
pkill -9 -f opnsense_agent
sleep 2

# Create installation directory
mkdir -p /usr/local/bin

# Install new agent
echo "$(date): Installing new agent..."
cat > /usr/local/bin/tunnel_agent.sh << \'EOF\'
' . $tunnel_agent_content . '
EOF

# Make executable
chmod +x /usr/local/bin/tunnel_agent.sh

# Start the agent
echo "$(date): Starting new agent..."
nohup /usr/local/bin/tunnel_agent.sh > /tmp/agent.log 2>&1 &

# Check if it started
sleep 2
if pgrep -f tunnel_agent > /dev/null; then
    echo "$(date): Agent started successfully"
else
    echo "$(date): ERROR: Agent failed to start"
    exit 1
fi

echo "$(date): Complete reinstall finished"
';
?>