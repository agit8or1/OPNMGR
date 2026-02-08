<?php
header('Content-Type: text/plain');
?>#!/bin/sh

echo "=== WORKING FINAL FIX ==="
echo "Current time: `date`"

echo "Step 1: Getting hardware ID..."
# Simple MAC extraction for FreeBSD
MAC_ADDR=`ifconfig | grep ether | head -1 | cut -d' ' -f2`
if [ -n "$MAC_ADDR" ]; then
    HARDWARE_ID=`echo "$MAC_ADDR" | sed 's/://g' | cut -c1-8`
    echo "MAC found: $MAC_ADDR"
    echo "Hardware ID: $HARDWARE_ID"
else
    HARDWARE_ID="21"
    echo "Using fallback ID: $HARDWARE_ID"
fi

echo "$HARDWARE_ID" > /usr/local/etc/opnsense_firewall_id
echo "Firewall ID saved: `cat /usr/local/etc/opnsense_firewall_id`"

echo "Step 2: Creating simple working agent..."
cat > /usr/local/bin/opnsense_agent.sh << 'EOF'
#!/bin/sh

AGENT_VERSION="4.3_working"
MANAGEMENT_SERVER="https://opn.agit8or.net"

if [ -f "/usr/local/etc/opnsense_firewall_id" ]; then
    FIREWALL_ID=`cat /usr/local/etc/opnsense_firewall_id`
else
    FIREWALL_ID="21"
fi

do_checkin() {
    WAN_IP=`fetch -q -o - http://ipv4.icanhazip.com 2>/dev/null || echo unknown`
    LAN_IP=`ifconfig | grep 'inet ' | grep -v 127.0.0.1 | head -1 | cut -d' ' -f2 || echo 10.0.0.1`
    OPNSENSE_VER=`opnsense-version 2>/dev/null | head -1 || echo unknown`
    UPTIME_VAL=`uptime | sed 's/.*up *//' | sed 's/,.*//' || echo unknown`
    
    cat > /tmp/checkin.json << JSONEND
{
    "firewall_id": $FIREWALL_ID,
    "agent_version": "$AGENT_VERSION",
    "api_key": "placeholder",
    "wan_ip": "$WAN_IP",
    "lan_ip": "$LAN_IP", 
    "ipv6_address": "",
    "opnsense_version": "$OPNSENSE_VER",
    "uptime": "$UPTIME_VAL"
}
JSONEND
    
    RESULT=`fetch -q -o - -T 10 --header="Content-Type: application/json" --upload-file="/tmp/checkin.json" "$MANAGEMENT_SERVER/agent_checkin.php" 2>/dev/null`
    
    if echo "$RESULT" | grep -q '"success":true'; then
        echo "SUCCESS"
        return 0
    else
        echo "FAILED"
        return 1
    fi
}

case "${1:-checkin}" in
    "checkin") do_checkin ;;
    "test") echo "Agent $AGENT_VERSION ready, ID: $FIREWALL_ID" ;;
    *) echo "Usage: $0 [checkin|test]" ;;
esac
EOF

chmod +x /usr/local/bin/opnsense_agent.sh

echo "Step 3: Testing agent..."
TEST_RESULT=`/usr/local/bin/opnsense_agent.sh checkin`
echo "Test result: $TEST_RESULT"

echo "Step 4: Installing cron..."
crontab -l 2>/dev/null | grep -v opnsense_agent > /tmp/cron_temp || touch /tmp/cron_temp
echo "*/2 * * * * /usr/local/bin/opnsense_agent.sh checkin >/dev/null 2>&1" >> /tmp/cron_temp
crontab /tmp/cron_temp
rm -f /tmp/cron_temp

echo "Step 5: Final verification..."
echo "Agent: `ls -la /usr/local/bin/opnsense_agent.sh`"
echo "ID file: `cat /usr/local/etc/opnsense_firewall_id`"
echo "Cron: `crontab -l | grep opnsense`"

echo "=== COMPLETE ==="
echo "Agent v4.3 working!"
echo "Hardware ID: $HARDWARE_ID"
echo "Next checkin: Within 2 minutes"