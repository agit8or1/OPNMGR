#!/bin/sh
# Bootstrap script to deploy new PID-safe agent
# Run this manually on the firewall: sh bootstrap_new_agent.sh

echo "=== OPNsense Agent v3.0 Bootstrap ==="
echo ""

# Download new agent
echo "1. Downloading new agent..."
curl -k -s -o /usr/local/bin/opnsense_agent_v3.sh 'https://opn.agit8or.net/download_new_agent.php?firewall_id=21'

if [ ! -f /usr/local/bin/opnsense_agent_v3.sh ]; then
    echo "ERROR: Download failed"
    exit 1
fi

chmod +x /usr/local/bin/opnsense_agent_v3.sh
echo "✓ Downloaded and made executable"

# Backup old agent
echo ""
echo "2. Backing up old agent..."
if [ -f /usr/local/bin/opnsense_agent.sh ]; then
    cp /usr/local/bin/opnsense_agent.sh /usr/local/bin/opnsense_agent.sh.old
    echo "✓ Old agent backed up"
fi

# Replace old agent with new one
echo ""
echo "3. Replacing agent..."
cp /usr/local/bin/opnsense_agent_v3.sh /usr/local/bin/opnsense_agent.sh
echo "✓ New agent installed"

# Clean up PID file
echo ""
echo "4. Cleaning up..."
rm -f /var/run/opnsense_agent.pid
echo "✓ Removed old PID file"

# Start new agent
echo ""
echo "5. Starting new agent..."
nohup sh /usr/local/bin/opnsense_agent.sh > /dev/null 2>&1 &
sleep 2

# Verify
AGENT_COUNT=$(ps aux | grep '[o]pnsense_agent.sh' | wc -l)
echo "✓ Agent started"
echo ""
echo "Running agents: $AGENT_COUNT"

if [ $AGENT_COUNT -eq 1 ]; then
    echo ""
    echo "🎉 SUCCESS! New agent is running."
    echo "The server block will be removed shortly."
else
    echo ""
    echo "⚠️  WARNING: $AGENT_COUNT agents detected"
fi
