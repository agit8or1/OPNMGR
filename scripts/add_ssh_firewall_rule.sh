#!/bin/sh
# Add firewall rule to allow SSH from OPNManager server
# This script should be run on the firewall via SSH

MGMT_SERVER_IP="184.175.206.229"
RULE_DESCRIPTION="Allow SSH from OPNManager"

echo "=== Adding SSH Firewall Rule ==="
echo "Management Server: $MGMT_SERVER_IP"
echo ""

# Check if rule already exists
EXISTING_RULE=$(pfctl -sr 2>/dev/null | grep "$MGMT_SERVER_IP" | grep "port 22")

if [ -n "$EXISTING_RULE" ]; then
    echo "✓ SSH rule already exists for $MGMT_SERVER_IP"
    exit 0
fi

# Create alias for management server if it doesn't exist
echo "Creating alias for management server..."
if ! grep -q "OPNManager" /conf/config.xml 2>/dev/null; then
    # Use OPNsense CLI to add alias
    configctl firewall alias add name=OPNManager type=host content=$MGMT_SERVER_IP description="OPNManager Server" 2>/dev/null || echo "Note: Using manual method"
fi

# Add firewall rule using OPNsense's filter rule structure
# This creates a rule to allow SSH (port 22) from the management server to WAN interface
cat >> /tmp/ssh_rule.xml << EOF
<rule>
    <type>pass</type>
    <interface>wan</interface>
    <ipprotocol>inet</ipprotocol>
    <protocol>tcp</protocol>
    <source>
        <address>$MGMT_SERVER_IP/32</address>
    </source>
    <destination>
        <address>(self)</address>
        <port>22</port>
    </destination>
    <descr>$RULE_DESCRIPTION</descr>
    <quick>1</quick>
</rule>
EOF

echo "✓ Firewall rule configuration created"
echo ""
echo "⚠️  IMPORTANT: Manual step required!"
echo ""
echo "To complete SSH access setup, add this firewall rule manually:"
echo "1. Log into OPNsense web UI"
echo "2. Go to Firewall → Rules → WAN"
echo "3. Click + Add"
echo "4. Set:"
echo "   - Action: Pass"
echo "   - Interface: WAN"
echo "   - Protocol: TCP"
echo "   - Source: Single host - $MGMT_SERVER_IP"
echo "   - Destination: WAN address"
echo "   - Destination Port: SSH (22)"
echo "   - Description: $RULE_DESCRIPTION"
echo "5. Click Save and Apply"
echo ""
echo "OR run this command on the firewall:"
echo "  configctl filter reload"
