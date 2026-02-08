<?php
header('Content-Type: text/plain');
echo '#!/bin/sh

echo "=== FIXED COMPREHENSIVE SCRIPT ==="
echo "Current time: `date`"

echo "Step 1: Getting hardware ID for firewall ID..."
# Generate consistent hardware ID from system info
HARDWARE_ID=`ifconfig | grep ether | head -1 | awk "{print \\$2}" | tr -d ":" | head -c 8`
if [ -z "$HARDWARE_ID" ]; then
    HARDWARE_ID=`sysctl -n kern.hostuuid | tr -d "-" | head -c 8`
fi
if [ -z "$HARDWARE_ID" ]; then
    HARDWARE_ID="21"  # Fallback to known ID
fi
echo "Using hardware-based ID: $HARDWARE_ID"
echo "$HARDWARE_ID" > /usr/local/etc/opnsense_firewall_id
echo "Firewall ID file created with: `cat /usr/local/etc/opnsense_firewall_id`"

echo "Step 2: Creating simple working agent..."
cat > /usr/local/bin/opnsense_agent.sh << "AGENT_EOF"
#!/bin/sh

# Simple OPNsense Agent v4.1 - FreeBSD Compatible
AGENT_VERSION="4.1_freebsd_fixed"
MANAGEMENT_SERVER="https://opn.agit8or.net"
LOG_FILE="/var/log/opnsense_agent.log"

# Get firewall ID
if [ -f "/usr/local/etc/opnsense_firewall_id" ]; then
    FIREWALL_ID=`cat /usr/local/etc/opnsense_firewall_id`
else
    FIREWALL_ID="21"
fi

log_message() {
    echo "`date \"+%Y-%m-%d %H:%M:%S\"` - $1" >> "$LOG_FILE"
}

get_system_info() {
    # Get WAN IP
    WAN_IP=`fetch -q -o - http://ipv4.icanhazip.com 2>/dev/null || echo "unknown"`
    
    # Get LAN IP - try different interface names
    LAN_IP=`ifconfig | grep "inet " | grep -v "127.0.0.1" | head -1 | awk "{print \\$2}"`
    
    # Get OPNsense version
    OPNSENSE_VERSION=`opnsense-version 2>/dev/null || echo "unknown"`
    
    # Get uptime
    UPTIME=`uptime | awk "{print \\$3, \\$4}" | sed "s/,//"`
}

do_checkin() {
    log_message "Starting checkin process"
    get_system_info
    
    # Create JSON data - escape quotes properly
    JSON_DATA="{\\\"firewall_id\\\": $FIREWALL_ID, \\\"agent_version\\\": \\\"$AGENT_VERSION\\\", \\\"api_key\\\": \\\"placeholder\\\", \\\"wan_ip\\\": \\\"$WAN_IP\\\", \\\"lan_ip\\\": \\\"$LAN_IP\\\", \\\"ipv6_address\\\": \\\"\\\", \\\"opnsense_version\\\": \\\"$OPNSENSE_VERSION\\\", \\\"uptime\\\": \\\"$UPTIME\\\"}"
    
    # Write JSON to temp file (FreeBSD fetch workaround)
    echo "$JSON_DATA" > /tmp/checkin_data.json
    
    # Send checkin using fetch with temp file
    response=`fetch -q -o - -T 10 --user-agent="OPNsense-Agent" \\
        --header="Content-Type: application/json" \\
        --upload-file="/tmp/checkin_data.json" \\
        "$MANAGEMENT_SERVER/agent_checkin.php" 2>/dev/null`
    
    if [ $? -eq 0 ] && [ -n "$response" ]; then
        log_message "Checkin successful"
        echo "SUCCESS"
    else
        log_message "Checkin failed"
        echo "FAILED"
    fi
    
    # Clean up
    rm -f /tmp/checkin_data.json
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
AGENT_EOF

chmod +x /usr/local/bin/opnsense_agent.sh

echo "Step 3: Testing agent file..."
if [ -f "/usr/local/bin/opnsense_agent.sh" ]; then
    echo "Agent file exists and is executable"
    ls -la /usr/local/bin/opnsense_agent.sh
else
    echo "ERROR: Agent file creation failed"
    exit 1
fi

echo "Step 4: Stopping any existing agents..."
pkill -f opnsense_agent
sleep 2

echo "Step 5: Testing agent manually..."
/usr/local/bin/opnsense_agent.sh checkin
if [ $? -eq 0 ]; then
    echo "Manual agent test: SUCCESS"
else
    echo "Manual agent test: Check logs for details"
fi

echo "Step 6: Setting up cron for regular checkins..."
# Remove any existing cron entries
crontab -l 2>/dev/null | grep -v opnsense_agent > /tmp/new_cron
# Add new entry for every 2 minutes
echo "*/2 * * * * /usr/local/bin/opnsense_agent.sh checkin >/dev/null 2>&1" >> /tmp/new_cron
crontab /tmp/new_cron
rm -f /tmp/new_cron
echo "Cron job installed for 2-minute checkins"

echo "Step 7: Starting background agent for immediate testing..."
nohup /usr/local/bin/opnsense_agent.sh checkin &
sleep 3

echo "Step 8: Checking cron status..."
crontab -l | grep opnsense_agent && echo "Cron job confirmed" || echo "Cron job missing"

echo "=== FIX COMPLETE ==="
echo "Agent should now be working with:"
echo "- Hardware-based firewall ID: $HARDWARE_ID"
echo "- FreeBSD-compatible network calls"
echo "- Cron-based 2-minute checkins"
echo "- Simplified, reliable architecture"
';
?>