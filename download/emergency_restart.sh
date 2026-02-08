#!/bin/sh

# Emergency Agent Restart Script
# This script can be run from the OPNsense console to restart the tunnel agent

echo "Emergency Tunnel Agent Restart"
echo "==============================="

# Kill any existing agents
echo "Stopping existing agents..."
pkill -f tunnel_agent 2>/dev/null
pkill -f opnsense_agent 2>/dev/null
sleep 3
pkill -9 -f tunnel_agent 2>/dev/null
pkill -9 -f opnsense_agent 2>/dev/null

# Download and install the latest agent
echo "Downloading latest agent..."
fetch -o /tmp/tunnel_agent.sh https://opn.agit8or.net/download/tunnel_agent.sh

if [ $? -eq 0 ] && [ -s /tmp/tunnel_agent.sh ]; then
    echo "Agent downloaded successfully"
    
    # Install the agent
    chmod +x /tmp/tunnel_agent.sh
    mkdir -p /usr/local/bin
    cp /tmp/tunnel_agent.sh /usr/local/bin/tunnel_agent.sh
    
    # Start the agent
    echo "Starting tunnel agent..."
    nohup /usr/local/bin/tunnel_agent.sh > /tmp/agent.log 2>&1 &
    
    if [ $? -eq 0 ]; then
        echo "✓ Tunnel agent started successfully"
        echo "  Check /tmp/agent.log for agent output"
        echo "  The agent should check in with the management server within 2 minutes"
        
        # Show the process
        sleep 2
        ps aux | grep tunnel_agent | grep -v grep
    else
        echo "✗ Failed to start tunnel agent"
        exit 1
    fi
else
    echo "✗ Failed to download agent"
    exit 1
fi

echo ""
echo "Emergency restart complete"
echo "The firewall should now appear online in the management interface"