#!/bin/sh
# OPNsense Agent v3.7.1 - Simple Installation Script
# Usage: fetch -o - https://opn.agit8or.net/scripts/install_agent_v3.7.1.sh | sh

echo "=== OPNsense Agent v3.7.1 Installation ==="
echo ""

# Kill any running agents
echo "1. Killing old agents..."
killall -9 tunnel_agent opnsense_agent 2>/dev/null
ps aux | grep -iE "agent.*sh" | grep -v grep | awk '{print $2}' | xargs kill -9 2>/dev/null
sleep 2

# Clean old files
echo "2. Removing old agent files..."
rm -rf /tmp/*agent* /usr/local/bin/*agent* /usr/local/opnsense_agent 2>/dev/null

# Download new agent
echo "3. Downloading agent v3.7.3..."
fetch -q -T 30 -o /tmp/opnsense_agent_v3.7.3.sh https://opn.agit8or.net/downloads/opnsense_agent_v3.7.3.sh

if [ ! -f /tmp/opnsense_agent_v3.7.3.sh ]; then
    echo "ERROR: Failed to download agent"
    exit 1
fi

# Verify download (check file size is reasonable)
echo "4. Verifying download..."
FILE_SIZE=$(wc -c < /tmp/opnsense_agent_v3.7.3.sh)
if [ "$FILE_SIZE" -lt 10000 ]; then
    echo "ERROR: Downloaded file is too small (corrupt or incomplete)"
    exit 1
fi

# Install
echo "5. Installing agent..."
chmod +x /tmp/opnsense_agent_v3.7.3.sh
cp /tmp/opnsense_agent_v3.7.3.sh /usr/local/bin/tunnel_agent.sh
chmod +x /usr/local/bin/tunnel_agent.sh

# Set up cron
echo "6. Configuring cron..."
crontab -l 2>/dev/null | grep -v "tunnel_agent" | grep -v "opnsense_agent" > /tmp/clean_cron
echo "*/2 * * * * /usr/local/bin/tunnel_agent.sh" >> /tmp/clean_cron
crontab /tmp/clean_cron
rm /tmp/clean_cron

# Start agent
echo "7. Starting agent..."
/bin/sh /usr/local/bin/tunnel_agent.sh > /tmp/agent_start.log 2>&1 &

echo ""
echo "=== Installation Complete ==="
echo ""
echo "Waiting 5 seconds for first checkin..."
sleep 5

echo ""
echo "=== Verification ==="
echo ""
echo "Cron entry:"
crontab -l | grep tunnel_agent
echo ""
echo "Running process:"
ps aux | grep tunnel_agent | grep -v grep
echo ""
echo "Recent logs:"
tail -5 /var/log/opnsense_agent.log
echo ""
echo "Done! Agent v3.7.3 should check in within 2 minutes."
