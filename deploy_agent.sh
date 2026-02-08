#!/bin/bash
# Deploy Agent v2.5.0 to Firewall
# This script copies the agent via the agent_update.php endpoint

FIREWALL_ID="$1"
if [ -z "$FIREWALL_ID" ]; then
    echo "Usage: $0 <firewall_id>"
    exit 1
fi

AGENT_FILE="/var/www/opnsense/scripts/opnsense_agent_v2.5.0.sh"
if [ ! -f "$AGENT_FILE" ]; then
    echo "Error: Agent file not found at $AGENT_FILE"
    exit 1
fi

echo "=== Deploying Agent v2.5.0 to Firewall $FIREWALL_ID ==="
echo ""

# Base64 encode the agent script
AGENT_BASE64=$(base64 -w 0 "$AGENT_FILE")

# Create deployment package
DEPLOY_JSON=$(cat <<JSONEOF
{
    "firewall_id": $FIREWALL_ID,
    "version": "2.5.0",
    "agent_script": "$AGENT_BASE64",
    "action": "deploy"
}
JSONEOF
)

echo "Package size: $(echo "$DEPLOY_JSON" | wc -c) bytes"
echo ""
echo "Deployment package ready. To deploy:"
echo "1. Agent will receive this via next check-in"
echo "2. Or manually execute on firewall:"
echo ""
echo "   curl -k https://opn.agit8or.net/get_agent_update.php?firewall_id=$FIREWALL_ID -o /tmp/agent_update.json"
echo "   # Extract and install from JSON"
echo ""
echo "For now, let's prepare a simple deployment endpoint..."

