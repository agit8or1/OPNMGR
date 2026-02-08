#!/bin/bash
# Deploy SSH reverse tunnel to firewall remotely via agent command queue

FIREWALL_ID=${1:-21}
MANAGEMENT_SERVER="https://opn.agit8or.net"

echo "=== Deploying Reverse SSH Tunnel to Firewall $FIREWALL_ID ==="
echo ""

# Create the tunnel setup command that will be executed on the firewall
TUNNEL_SETUP_COMMAND=$(cat << 'TUNNEL_CMD'
# Download and execute tunnel setup
fetch -o /tmp/setup_tunnel.sh https://opn.agit8or.net/setup_reverse_proxy.sh || curl -k -o /tmp/setup_tunnel.sh https://opn.agit8or.net/setup_reverse_proxy.sh
chmod +x /tmp/setup_tunnel.sh
/tmp/setup_tunnel.sh FIREWALL_ID_PLACEHOLDER > /tmp/tunnel_setup.log 2>&1
cat /tmp/tunnel_setup.log
# Display the SSH public key for management server
echo "=== SSH PUBLIC KEY ==="
cat /home/tunnel/.ssh/id_rsa.pub || echo "Key generation failed"
TUNNEL_CMD
)

# Replace the firewall ID placeholder
TUNNEL_SETUP_COMMAND=${TUNNEL_SETUP_COMMAND//FIREWALL_ID_PLACEHOLDER/$FIREWALL_ID}

echo "Command to be queued:"
echo "---"
echo "$TUNNEL_SETUP_COMMAND"
echo "---"
echo ""

# Queue the command via API
RESPONSE=$(curl -s -k -X POST "$MANAGEMENT_SERVER/api/queue_command.php" \
    -H "Content-Type: application/json" \
    -d "{
        \"firewall_id\": $FIREWALL_ID,
        \"command\": $(echo "$TUNNEL_SETUP_COMMAND" | jq -Rs .),
        \"description\": \"Setup reverse SSH tunnel\"
    }")

echo "API Response: $RESPONSE"
echo ""

if echo "$RESPONSE" | grep -q '"success":true'; then
    echo "✅ Command queued successfully!"
    echo ""
    echo "Next steps:"
    echo "1. Wait for firewall agent to check in (every 5 minutes)"
    echo "2. Agent will execute the tunnel setup script"
    echo "3. Check firewall_commands table for results"
    echo "4. Get the SSH public key from the command result"
    echo "5. Add the key to tunnel user: su - tunnel && vi ~/.ssh/authorized_keys"
    echo ""
    echo "Monitor with:"
    echo "  mysql -u root -e \"USE opnsense_fw; SELECT id, description, status, result FROM firewall_commands WHERE firewall_id=$FIREWALL_ID ORDER BY created_at DESC LIMIT 5;\""
else
    echo "❌ Failed to queue command"
    echo "Response: $RESPONSE"
fi
