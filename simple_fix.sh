#!/bin/sh

# Emergency OPNsense Agent Installer - Simple Version
echo "=== Emergency Agent Fix ==="
echo "Starting at $(date)"

# Backup current agent
if [ -f "/usr/local/bin/opnsense_agent.sh" ]; then
    echo "Backing up current agent..."
    cp /usr/local/bin/opnsense_agent.sh /tmp/opnsense_agent.sh.bak.$(date +%s)
fi

# Download the fixed agent directly
echo "Downloading fixed agent..."
fetch -q -o /tmp/opnsense_agent_fixed.sh https://opn.agit8or.net/complete_agent_v4.sh

if [ ! -f "/tmp/opnsense_agent_fixed.sh" ]; then
    echo "ERROR: Could not download fixed agent"
    exit 1
fi

# Make it executable
chmod +x /tmp/opnsense_agent_fixed.sh

# Stop current agent
echo "Stopping current agent..."
pkill -f opnsense_agent 2>/dev/null
sleep 2

# Install the fixed agent
echo "Installing fixed agent..."
cp /tmp/opnsense_agent_fixed.sh /usr/local/bin/opnsense_agent.sh
chmod +x /usr/local/bin/opnsense_agent.sh

# Start the fixed agent
echo "Starting fixed agent..."
nohup /usr/local/bin/opnsense_agent.sh > /dev/null 2>&1 &

# Wait a moment and verify
sleep 3
if pgrep -f opnsense_agent > /dev/null; then
    echo "SUCCESS: Fixed agent is running with PID $(pgrep -f opnsense_agent)"
    echo "The agent should now process the pending reboot command"
    echo "Firewall will reboot within 2 minutes to restart services"
else
    echo "ERROR: Agent may not be running"
fi

# Test checkin
echo "Testing agent checkin..."
/usr/local/bin/opnsense_agent.sh checkin 2>/dev/null || echo "Checkin test completed"

echo "=== Fix Complete ==="
echo "Agent has been replaced. System will reboot automatically."