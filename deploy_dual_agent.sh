#!/bin/bash
###############################################################################
# Dual-Agent Deployment Script
# Deploys both primary and update agents to an OPNsense firewall
###############################################################################

if [ $# -ne 3 ]; then
    echo "Usage: $0 <firewall_id> <ssh_user> <ssh_host>"
    echo "Example: $0 21 root home.agit8or.net"
    exit 1
fi

FIREWALL_ID="$1"
SSH_USER="$2"
SSH_HOST="$3"
MANAGER_URL="https://opn.agit8or.net"

echo "=== Deploying Dual-Agent System ==="
echo "Firewall ID: $FIREWALL_ID"
echo "SSH Target: $SSH_USER@$SSH_HOST"
echo ""

# Test SSH connectivity
echo "Testing SSH connection..."
if ! ssh -o ConnectTimeout=5 "$SSH_USER@$SSH_HOST" "echo 'SSH OK'"; then
    echo "ERROR: Cannot connect via SSH"
    exit 1
fi
echo "✓ SSH connection successful"
echo ""

# Deploy primary agent
echo "Deploying primary agent..."
ssh "$SSH_USER@$SSH_HOST" "
    # Stop any existing agents
    pkill -f opnsense_agent.sh 2>/dev/null
    pkill -f opnsense_update_agent.sh 2>/dev/null
    
    # Download primary agent
    curl -k -s -o /tmp/opnsense_agent.sh '$MANAGER_URL/download_tunnel_agent.php?firewall_id=$FIREWALL_ID'
    
    if [ ! -s /tmp/opnsense_agent.sh ]; then
        echo 'ERROR: Failed to download primary agent'
        exit 1
    fi
    
    # Install primary agent
    mv /tmp/opnsense_agent.sh /usr/local/bin/opnsense_agent.sh
    chmod +x /usr/local/bin/opnsense_agent.sh
    echo '✓ Primary agent installed'
"

if [ $? -ne 0 ]; then
    echo "ERROR: Failed to deploy primary agent"
    exit 1
fi

# Deploy update agent
echo "Deploying update agent..."
ssh "$SSH_USER@$SSH_HOST" "
    # Download update agent
    curl -k -s -o /tmp/opnsense_update_agent.sh '$MANAGER_URL/download_update_agent.php?firewall_id=$FIREWALL_ID'
    
    if [ ! -s /tmp/opnsense_update_agent.sh ]; then
        echo 'ERROR: Failed to download update agent'
        exit 1
    fi
    
    # Install update agent
    mv /tmp/opnsense_update_agent.sh /usr/local/bin/opnsense_update_agent.sh
    chmod +x /usr/local/bin/opnsense_update_agent.sh
    echo '✓ Update agent installed'
"

if [ $? -ne 0 ]; then
    echo "ERROR: Failed to deploy update agent"
    exit 1
fi

# Start both agents
echo "Starting agents..."
ssh "$SSH_USER@$SSH_HOST" "
    # Start primary agent
    nohup /usr/local/bin/opnsense_agent.sh > /dev/null 2>&1 &
    PRIMARY_PID=\$!
    echo \"✓ Primary agent started (PID: \$PRIMARY_PID)\"
    
    # Wait a moment
    sleep 2
    
    # Start update agent
    nohup /usr/local/bin/opnsense_update_agent.sh > /dev/null 2>&1 &
    UPDATE_PID=\$!
    echo \"✓ Update agent started (PID: \$UPDATE_PID)\"
    
    # Verify both are running
    sleep 2
    if pgrep -f 'opnsense_agent.sh' > /dev/null && pgrep -f 'opnsense_update_agent.sh' > /dev/null; then
        echo \"✓ Both agents confirmed running\"
    else
        echo \"WARNING: One or both agents may not be running\"
        ps aux | grep opnsense_.*agent.sh | grep -v grep
    fi
"

echo ""
echo "=== Deployment Complete ==="
echo ""
echo "Verify in management interface:"
echo "  - Check firewall status shows 'online'"
echo "  - View recent check-ins in agent logs"
echo ""
echo "On firewall, verify with:"
echo "  ssh $SSH_USER@$SSH_HOST 'ps aux | grep opnsense_.*agent.sh | grep -v grep'"
echo ""
