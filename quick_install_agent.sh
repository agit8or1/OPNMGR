#!/bin/bash

# OPNsense Agent Quick Installer
# Run this on your OPNsense firewall

echo "=== OPNsense Agent Installer ==="
echo "Downloading and installing agent..."

# Download the agent
fetch -o /tmp/opnsense_agent.sh https://opn.agit8or.net/opnsense_agent_v2.sh.txt

if [ ! -f /tmp/opnsense_agent.sh ]; then
    echo "ERROR: Failed to download agent"
    exit 1
fi

# Make executable
chmod +x /tmp/opnsense_agent.sh

# Run installer
echo "Installing agent..."
/tmp/opnsense_agent.sh install

# Check if installation succeeded
if crontab -l 2>/dev/null | grep -q opnsense_agent; then
    echo "SUCCESS: Agent installed and cron job created"
    echo "Agent will check in every 5 minutes"
    
    # Show cron job
    echo ""
    echo "Cron job:"
    crontab -l | grep opnsense_agent
    
    # Test immediate check-in
    echo ""
    echo "Testing immediate check-in..."
    /usr/local/bin/opnsense_agent_v2.sh checkin
    
    echo ""
    echo "Installation complete!"
    echo "Check logs at: /var/log/opnsense_agent.log"
else
    echo "ERROR: Installation failed"
    exit 1
fi