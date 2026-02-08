#!/bin/sh

echo "=== Fixing OPNsense Agent Configuration ==="

# Create proper config file
echo "Creating agent configuration..."
cat > /usr/local/etc/opnsense_agent.conf << 'CONF'
FIREWALL_ID="21"
MANAGEMENT_SERVER="https://opn.agit8or.net"
API_KEY=""
CHECKIN_INTERVAL=300
CONF

echo "Configuration created:"
cat /usr/local/etc/opnsense_agent.conf
echo ""

# Make sure cron job exists
echo "Checking cron job..."
if ! crontab -l 2>/dev/null | grep -q opnsense_agent_v2.sh; then
    echo "Adding cron job..."
    (crontab -l 2>/dev/null; echo "*/5 * * * * /usr/local/bin/opnsense_agent_v2.sh >/dev/null 2>&1") | crontab -
    echo "Cron job added"
else
    echo "Cron job already exists"
fi

# Test agent manually
echo ""
echo "Testing agent manually..."
if [ -f "/usr/local/bin/opnsense_agent_v2.sh" ]; then
    /usr/local/bin/opnsense_agent_v2.sh
else
    echo "Agent script not found!"
fi

echo ""
echo "=== Fix Complete ==="
