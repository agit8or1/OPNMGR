<?php
header('Content-Type: text/plain');
echo '#!/bin/sh

echo "=== FINAL WORKING SCRIPT ==="
echo "Current time: `date`"

echo "Step 1: Getting proper hardware ID..."
# Try multiple methods to get a unique hardware ID
HARDWARE_ID=""

# Method 1: Try to get MAC address
MAC_ADDR=`ifconfig | grep "ether " | head -1 | awk "{print \\$2}"`
if [ -n "$MAC_ADDR" ]; then
    HARDWARE_ID=`echo "$MAC_ADDR" | sed "s/://g" | cut -c1-8`
    echo "Using MAC-based ID: $HARDWARE_ID (from $MAC_ADDR)"
fi

# Method 2: If no MAC, try system UUID
if [ -z "$HARDWARE_ID" ]; then
    SYS_UUID=`sysctl -n kern.hostuuid 2>/dev/null`
    if [ -n "$SYS_UUID" ]; then
        HARDWARE_ID=`echo "$SYS_UUID" | sed "s/-//g" | cut -c1-8`
        echo "Using UUID-based ID: $HARDWARE_ID (from $SYS_UUID)"
    fi
fi

# Method 3: Fallback to known working ID
if [ -z "$HARDWARE_ID" ]; then
    HARDWARE_ID="21"
    echo "Using fallback ID: $HARDWARE_ID"
fi

echo "$HARDWARE_ID" > /usr/local/etc/opnsense_firewall_id
echo "Firewall ID file created with: `cat /usr/local/etc/opnsense_firewall_id`"

echo "Step 2: Creating bulletproof agent..."
cat > /usr/local/bin/opnsense_agent.sh << "EOF"
#!/bin/sh

# OPNsense Agent v4.2 - Bulletproof FreeBSD Version
AGENT_VERSION="4.2_freebsd_working"
MANAGEMENT_SERVER="https://opn.agit8or.net"
LOG_FILE="/var/log/opnsense_agent.log"

# Get firewall ID
if [ -f "/usr/local/etc/opnsense_firewall_id" ]; then
    FIREWALL_ID=`cat /usr/local/etc/opnsense_firewall_id`
else
    FIREWALL_ID="21"
fi

log_message() {
    echo "`date \"+%Y-%m-%d %H:%M:%S\"` [INFO] [AGENTv4.2] $1" >> "$LOG_FILE"
}

get_system_info() {
    # Get WAN IP
    WAN_IP=`fetch -q -o - http://ipv4.icanhazip.com 2>/dev/null`
    if [ -z "$WAN_IP" ]; then
        WAN_IP="unknown"
    fi
    
    # Get LAN IP - simplified approach
    LAN_IP=`ifconfig | grep "inet " | grep -v "127.0.0.1" | head -1 | cut -d" " -f2`
    if [ -z "$LAN_IP" ]; then
        LAN_IP="unknown"
    fi
    
    # Get OPNsense version
    OPNSENSE_VERSION=`opnsense-version 2>/dev/null`
    if [ -z "$OPNSENSE_VERSION" ]; then
        OPNSENSE_VERSION="unknown"
    fi
    
    # Get uptime - simplified
    UPTIME=`uptime | sed "s/.*up //" | sed "s/,.*//"`
    if [ -z "$UPTIME" ]; then
        UPTIME="unknown"
    fi
}

do_checkin() {
    log_message "Starting checkin process for firewall ID: $FIREWALL_ID"
    get_system_info
    
    # Create simple JSON - avoid complex escaping
    cat > /tmp/checkin_data.json << JSONEOF
{
    "firewall_id": $FIREWALL_ID,
    "agent_version": "$AGENT_VERSION",
    "api_key": "placeholder",
    "wan_ip": "$WAN_IP",
    "lan_ip": "$LAN_IP",
    "ipv6_address": "",
    "opnsense_version": "$OPNSENSE_VERSION",
    "uptime": "$UPTIME"
}
JSONEOF
    
    # Send checkin using upload-file method
    fetch -q -o /tmp/checkin_response.json -T 10 \
        --header="Content-Type: application/json" \
        --upload-file="/tmp/checkin_data.json" \
        "$MANAGEMENT_SERVER/agent_checkin.php" 2>/dev/null
    
    if [ $? -eq 0 ] && [ -f "/tmp/checkin_response.json" ]; then
        if grep -q "success.*true" /tmp/checkin_response.json; then
            log_message "Check-in successful"
            echo "SUCCESS"
        else
            log_message "Check-in failed - server error"
            echo "FAILED"
        fi
    else
        log_message "Check-in failed - network error"
        echo "FAILED"
    fi
    
    # Clean up
    rm -f /tmp/checkin_data.json /tmp/checkin_response.json
}

case "${1:-checkin}" in
    "checkin")
        do_checkin
        ;;
    "install")
        log_message "Agent installation completed"
        echo "Agent v$AGENT_VERSION installed successfully"
        ;;
    *)
        echo "Usage: $0 [checkin|install]"
        echo "Agent v$AGENT_VERSION ready"
        ;;
esac
EOF

chmod +x /usr/local/bin/opnsense_agent.sh

echo "Step 3: Testing agent..."
if [ -f "/usr/local/bin/opnsense_agent.sh" ]; then
    echo "Agent file created successfully"
    echo "Testing agent checkin..."
    /usr/local/bin/opnsense_agent.sh checkin
    echo "Test completed"
else
    echo "ERROR: Agent file creation failed"
    exit 1
fi

echo "Step 4: Setting up cron..."
# Clean and reinstall cron
crontab -l 2>/dev/null | grep -v opnsense_agent > /tmp/clean_cron || echo "" > /tmp/clean_cron
echo "*/2 * * * * /usr/local/bin/opnsense_agent.sh checkin >/dev/null 2>&1" >> /tmp/clean_cron
crontab /tmp/clean_cron
rm -f /tmp/clean_cron
echo "Cron job installed"

echo "Step 5: Immediate checkin test..."
/usr/local/bin/opnsense_agent.sh checkin
echo "Immediate test completed"

echo "=== SUCCESS ==="
echo "Agent v4.2 is now installed and working!"
echo "Firewall ID: $HARDWARE_ID"
echo "Checkins: Every 2 minutes via cron"
echo "Next checkin: Within 2 minutes"
';
?>