#!/bin/bash
# Direct SSH deployment of agent v3.6.1 to OPNsense firewall

FIREWALL_IP="10.0.0.1"
FIREWALL_SSH_KEY="/root/.ssh/id_ed25519"
MANAGER_URL="https://opn.agit8or.net"

# Check if SSH key exists
if [ ! -f "$FIREWALL_SSH_KEY" ]; then
    echo "SSH key not found: $FIREWALL_SSH_KEY"
    exit 1
fi

echo "Deploying agent v3.6.1 to $FIREWALL_IP..."

# Deploy agent script
ssh -i "$FIREWALL_SSH_KEY" -o StrictHostKeyChecking=no -o ConnectTimeout=5 root@$FIREWALL_IP << 'EOFSCRIPT'

# Download the latest agent
echo "Downloading agent v3.6.1..."
fetch -o /tmp/opnsense_agent_v3.6.1.sh https://opn.agit8or.net/download_agent.php?firewall_id=21

if [ ! -f /tmp/opnsense_agent_v3.6.1.sh ]; then
    echo "Failed to download agent"
    exit 1
fi

# Make executable
chmod +x /tmp/opnsense_agent_v3.6.1.sh

# Backup old agent
if [ -f /usr/local/bin/opnsense_agent_v2.sh ]; then
    cp /usr/local/bin/opnsense_agent_v2.sh /usr/local/bin/opnsense_agent_v2.sh.backup
    echo "Backed up old agent to /usr/local/bin/opnsense_agent_v2.sh.backup"
fi

# Install new agent
cp /tmp/opnsense_agent_v3.6.1.sh /usr/local/bin/opnsense_agent_v2.sh
chmod +x /usr/local/bin/opnsense_agent_v2.sh

# Kill old agent process
pkill -f "opnsense_agent_v2.sh"

# Start new agent immediately
/usr/local/bin/opnsense_agent_v2.sh &

echo "Agent v3.6.1 deployed successfully"
ps aux | grep opnsense_agent | grep -v grep

EOFSCRIPT

if [ $? -eq 0 ]; then
    echo "✅ Agent deployment completed successfully"
else
    echo "❌ Agent deployment failed"
    exit 1
fi
