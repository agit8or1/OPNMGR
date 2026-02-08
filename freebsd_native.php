<?php
header('Content-Type: text/plain');
?>#!/bin/sh

echo "=== FREEBSD COMPATIBLE AGENT ==="
echo "Time: `date`"

echo "Creating FreeBSD-native agent..."
cat > /usr/local/bin/opnsense_agent.sh << 'EOF'
#!/bin/sh

AGENT_VERSION="4.4_freebsd_native"
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
    
    # Create URL-encoded data (FreeBSD compatible method)
    DATA="firewall_id=${FIREWALL_ID}&agent_version=${AGENT_VERSION}&api_key=placeholder&wan_ip=${WAN_IP}&lan_ip=${LAN_IP}&ipv6_address=&opnsense_version=${OPNSENSE_VER}&uptime=${UPTIME_VAL}"
    
    # Use simple form POST (no custom headers needed)
    RESULT=`printf "%s" "$DATA" | fetch -q -o - -T 10 --post-data="-" "$MANAGEMENT_SERVER/agent_checkin.php" 2>/dev/null`
    
    if [ $? -eq 0 ] && echo "$RESULT" | grep -q 'success.*true\|SUCCESS'; then
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

echo "Testing new FreeBSD-native agent..."
TEST_RESULT=`/usr/local/bin/opnsense_agent.sh checkin`
echo "Test result: $TEST_RESULT"

if [ "$TEST_RESULT" = "SUCCESS" ]; then
    echo "✅ SUCCESS! Agent working perfectly!"
else
    echo "⚠️  Testing alternative method..."
    
    # Alternative: Use curl if available, or create a simpler method
    if command -v curl >/dev/null 2>&1; then
        echo "Using curl as backup..."
        cat > /usr/local/bin/opnsense_agent.sh << 'EOF2'
#!/bin/sh
AGENT_VERSION="4.4_curl_backup"
MANAGEMENT_SERVER="https://opn.agit8or.net"
FIREWALL_ID=`cat /usr/local/etc/opnsense_firewall_id 2>/dev/null || echo 21`

do_checkin() {
    WAN_IP=`fetch -q -o - http://ipv4.icanhazip.com 2>/dev/null || echo unknown`
    LAN_IP=`ifconfig | grep 'inet ' | grep -v 127.0.0.1 | head -1 | cut -d' ' -f2 || echo 10.0.0.1`
    OPNSENSE_VER=`opnsense-version 2>/dev/null | head -1 || echo unknown`
    UPTIME_VAL=`uptime | sed 's/.*up *//' | sed 's/,.*//' || echo unknown`
    
    RESULT=`curl -s -X POST -d "firewall_id=${FIREWALL_ID}&agent_version=${AGENT_VERSION}&api_key=placeholder&wan_ip=${WAN_IP}&lan_ip=${LAN_IP}&ipv6_address=&opnsense_version=${OPNSENSE_VER}&uptime=${UPTIME_VAL}" "$MANAGEMENT_SERVER/agent_checkin.php" 2>/dev/null`
    
    if echo "$RESULT" | grep -q 'success.*true\|SUCCESS'; then
        echo "SUCCESS"
    else
        echo "FAILED"
    fi
}

case "${1:-checkin}" in
    "checkin") do_checkin ;;
    "test") echo "Agent $AGENT_VERSION ready, ID: $FIREWALL_ID" ;;
    *) echo "Usage: $0 [checkin|test]" ;;
esac
EOF2
        chmod +x /usr/local/bin/opnsense_agent.sh
        TEST_RESULT2=`/usr/local/bin/opnsense_agent.sh checkin`
        echo "Curl test result: $TEST_RESULT2"
    fi
fi

echo ""
echo "Installing fresh cron job..."
crontab -l 2>/dev/null | grep -v opnsense_agent > /tmp/cron_new || touch /tmp/cron_new
echo "*/2 * * * * /usr/local/bin/opnsense_agent.sh checkin >/dev/null 2>&1" >> /tmp/cron_new
crontab /tmp/cron_new
rm -f /tmp/cron_new

echo ""
echo "Final test..."
/usr/local/bin/opnsense_agent.sh test
/usr/local/bin/opnsense_agent.sh checkin

echo ""
echo "=== COMPLETE ==="
echo "🎯 FreeBSD-native agent installed!"
echo "🔄 Cron: Every 2 minutes"
echo "📡 Next checkin: Within 2 minutes"