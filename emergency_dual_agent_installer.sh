#!/bin/sh
# Emergency Dual-Agent Installer for Firewall ID 21
# Copy and paste this entire script into the firewall SSH session

FIREWALL_ID=21
MANAGER_URL="https://opn.agit8or.net"

echo "=== OPNsense Dual-Agent Emergency Installer ==="
echo "Firewall ID: $FIREWALL_ID"
echo ""

# Download primary agent (v2.4.0)
echo "[1/4] Downloading primary agent..."
curl -k -o /usr/local/bin/opnsense_agent.sh "${MANAGER_URL}/download_tunnel_agent.php?firewall_id=${FIREWALL_ID}"
if [ $? -eq 0 ]; then
    chmod +x /usr/local/bin/opnsense_agent.sh
    echo "✓ Primary agent downloaded"
else
    echo "✗ Failed to download primary agent"
    exit 1
fi

# Download update agent
echo "[2/4] Downloading update agent..."
curl -k -o /usr/local/bin/opnsense_update_agent.sh "${MANAGER_URL}/download_update_agent.php?firewall_id=${FIREWALL_ID}"
if [ $? -eq 0 ]; then
    chmod +x /usr/local/bin/opnsense_update_agent.sh
    echo "✓ Update agent downloaded"
else
    echo "✗ Failed to download update agent"
    exit 1
fi

# Kill any existing agents
echo "[3/4] Stopping old agents..."
pkill -9 -f opnsense_agent.sh
pkill -9 -f opnsense_update_agent.sh
sleep 2
echo "✓ Old agents stopped"

# Start both agents
echo "[4/4] Starting both agents..."
nohup /usr/local/bin/opnsense_agent.sh > /dev/null 2>&1 &
PRIMARY_PID=$!
sleep 1
nohup /usr/local/bin/opnsense_update_agent.sh > /dev/null 2>&1 &
UPDATE_PID=$!
sleep 2

# Verify both are running
if ps -p $PRIMARY_PID > /dev/null 2>&1; then
    echo "✓ Primary agent started (PID: $PRIMARY_PID)"
else
    echo "✗ Primary agent failed to start"
fi

if ps -p $UPDATE_PID > /dev/null 2>&1; then
    echo "✓ Update agent started (PID: $UPDATE_PID)"
else
    echo "✗ Update agent failed to start"
fi

echo ""
echo "=== Installation Complete ==="
echo ""
echo "Running agents:"
ps aux | grep opnsense | grep -v grep
echo ""
echo "Both agents should check in within 5 minutes."
echo "Check the management UI to verify their status."
