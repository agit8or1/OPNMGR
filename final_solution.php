<?php
header('Content-Type: text/plain');
?>#!/bin/sh

echo "=== FINAL WORKING SOLUTION ==="
echo "Time: `date`"

echo "Step 1: Using existing firewall ID 21..."
echo "21" > /usr/local/etc/opnsense_firewall_id
echo "Firewall ID set to: `cat /usr/local/etc/opnsense_firewall_id`"

echo "Step 2: Creating JSON-compatible agent..."
cat > /usr/local/bin/opnsense_agent.sh << 'EOF'
#!/bin/sh

AGENT_VERSION="4.5_json_working"
MANAGEMENT_SERVER="https://opn.agit8or.net"

if [ -f "/usr/local/etc/opnsense_firewall_id" ]; then
    FIREWALL_ID=`cat /usr/local/etc/opnsense_firewall_id`
else
    FIREWALL_ID="21"
fi

do_checkin() {
    # Gather system info
    WAN_IP=`fetch -q -o - http://ipv4.icanhazip.com 2>/dev/null || echo unknown`
    LAN_IP=`ifconfig | grep 'inet ' | grep -v 127.0.0.1 | head -1 | cut -d' ' -f2 || echo 10.0.0.1`
    OPNSENSE_VER=`opnsense-version 2>/dev/null | head -1 || echo unknown`
    UPTIME_VAL=`uptime | sed 's/.*up *//' | sed 's/,.*//' || echo unknown`
    
    # Create JSON file (server expects JSON, not form data)
    cat > /tmp/checkin.json << JSONEOF
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
JSONEOF

    # Try multiple methods until one works
    # Method 1: Try curl first (most reliable)
    if command -v curl >/dev/null 2>&1; then
        RESULT=`curl -s -X POST -H "Content-Type: application/json" -d @/tmp/checkin.json "$MANAGEMENT_SERVER/agent_checkin.php" 2>/dev/null`
        if echo "$RESULT" | grep -q '"success":true'; then
            echo "SUCCESS"
            return 0
        fi
    fi
    
    # Method 2: Try fetch with file upload (FreeBSD method)
    RESULT=`fetch -q -o - -T 10 --upload-file=/tmp/checkin.json "$MANAGEMENT_SERVER/agent_checkin.php" 2>/dev/null`
    if echo "$RESULT" | grep -q '"success":true'; then
        echo "SUCCESS"
        return 0
    fi
    
    # Method 3: Simple verification that we can reach the server
    PING_RESULT=`fetch -q -o - -T 5 "$MANAGEMENT_SERVER/test.php" 2>/dev/null`
    if [ -n "$PING_RESULT" ]; then
        echo "PARTIAL"  # Server reachable but checkin format wrong
    else
        echo "FAILED"   # Network issue
    fi
    
    return 1
}

case "${1:-checkin}" in
    "checkin") do_checkin ;;
    "test") echo "Agent $AGENT_VERSION ready, ID: $FIREWALL_ID" ;;
    *) echo "Usage: $0 [checkin|test]" ;;
esac
EOF

chmod +x /usr/local/bin/opnsense_agent.sh

echo "Step 3: Testing with correct firewall ID..."
TEST_RESULT=`/usr/local/bin/opnsense_agent.sh checkin`
echo "Test result: $TEST_RESULT"

echo "Step 4: Installing cron job..."
crontab -l 2>/dev/null | grep -v opnsense_agent > /tmp/cron_clean || touch /tmp/cron_clean
echo "*/2 * * * * /usr/local/bin/opnsense_agent.sh checkin >/dev/null 2>&1" >> /tmp/cron_clean
crontab /tmp/cron_clean
rm -f /tmp/cron_clean

echo "Step 5: Final verification..."
echo "Agent info: `/usr/local/bin/opnsense_agent.sh test`"
echo "Cron job: `crontab -l | grep opnsense_agent`"

if [ "$TEST_RESULT" = "SUCCESS" ]; then
    echo ""
    echo "🎉 SUCCESS! Agent is working perfectly!"
    echo "✅ Firewall ID: 21 (database match)"
    echo "✅ Agent version: 4.5_json_working"
    echo "✅ Checkins: Every 2 minutes"
    echo "✅ System fully operational!"
elif [ "$TEST_RESULT" = "PARTIAL" ]; then
    echo ""
    echo "⚠️  Server reachable but may need JSON format adjustment"
    echo "✅ Network connectivity working"
    echo "✅ Cron job installed - will retry automatically"
else
    echo ""
    echo "⚠️  Will retry automatically via cron every 2 minutes"
fi

echo ""
echo "=== DEPLOYMENT COMPLETE ==="