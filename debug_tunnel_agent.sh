#!/bin/sh

# Debug version of tunnel agent to test connectivity
MANAGEMENT_SERVER="opn.agit8or.net"
FIREWALL_ID="21"
AUTH_TOKEN="debug_token_$(date +%s)"
LOCAL_WEB_PORT="443"

echo "=== Debug Tunnel Agent ==="
echo "Management Server: $MANAGEMENT_SERVER"
echo "Firewall ID: $FIREWALL_ID"
echo "Auth Token: $AUTH_TOKEN"

# Create JSON payload
TMP_DATA="/tmp/debug_tunnel_data"
cat > "$TMP_DATA" << EOF
{"firewall_id": $FIREWALL_ID, "auth_token": "$AUTH_TOKEN", "tunnel_port": $LOCAL_WEB_PORT}
EOF

echo "JSON Payload:"
cat "$TMP_DATA"
echo ""

echo "Testing with fetch..."
RESPONSE=$(fetch -q -o - -T 10 --method=POST \
    --header="Content-Type: application/json" \
    "https://$MANAGEMENT_SERVER/api/tunnel_connect.php" < "$TMP_DATA" 2>&1)

echo "Full fetch response: [$RESPONSE]"
echo "Response length: $(echo -n "$RESPONSE" | wc -c)"

# Clean up
rm -f "$TMP_DATA"

echo "=== End Debug ==="