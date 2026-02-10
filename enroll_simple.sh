#!/bin/sh
# OPNsense Simple Enrollment Script
# Minimal enrollment without pkg install or extra downloads

set -e

PANEL_URL="https://opn.agit8or.net"
ENROLLMENT_TOKEN="__TOKEN__"

echo "=== OPNsense Enrollment ==="

# Get firewall details
HOSTNAME=$(hostname)
WAN_IP=$(curl -s -4 ifconfig.me 2>/dev/null || echo "unknown")

# Generate hardware ID from MAC address
HARDWARE_ID=$(ifconfig | grep ether | head -1 | awk '{print $2}' | tr '[:upper:]' '[:lower:]')
if [ -z "$HARDWARE_ID" ]; then
    HARDWARE_ID="$(hostname)-$(date +%s)"
fi

echo "Hostname: $HOSTNAME"
echo "WAN IP: $WAN_IP"
echo "Hardware ID: $HARDWARE_ID"

# Create JSON payload
JSON=$(cat <<EOF
{"hostname":"$HOSTNAME","hardware_id":"$HARDWARE_ID","ip_address":"$WAN_IP","wan_ip":"$WAN_IP","token":"$ENROLLMENT_TOKEN"}
EOF
)

# Send enrollment request
echo "Enrolling with panel..."
RESPONSE=$(curl -s -X POST "$PANEL_URL/enroll_firewall.php" \
    -H "Content-Type: application/json" \
    -d "$JSON")

# Check response
if echo "$RESPONSE" | grep -q success; then
    echo "✓ Enrollment successful!"
    exit 0
else
    echo "✗ Enrollment failed:"
    echo "$RESPONSE"
    exit 1
fi
