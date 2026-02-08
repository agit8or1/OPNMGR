#!/bin/sh
# Super Simple OPNsense Agent - FreeBSD Compatible
# This agent sends data in the exact format expected by agent_checkin.php

FIREWALL_ID="21"
AGENT_VERSION="2.0"
MANAGEMENT_SERVER="https://opn.agit8or.net"

# Get basic system info
HOSTNAME=$(hostname)
WAN_IP=$(ifconfig em0 2>/dev/null | grep 'inet ' | grep -v '127.0.0.1' | awk '{print $2}' | head -1)
if [ -z "$WAN_IP" ]; then
    WAN_IP=$(fetch -qo - http://ipinfo.io/ip 2>/dev/null)
fi

# Get OPNsense version
if [ -f /usr/local/opnsense/version/opnsense ]; then
    OPNSENSE_VERSION=$(cat /usr/local/opnsense/version/opnsense)
else
    OPNSENSE_VERSION="24.1.0"
fi

# Create the data in the exact format agent_checkin.php expects
send_checkin() {
    echo "Sending check-in for firewall ID: $FIREWALL_ID"
    echo "Hostname: $HOSTNAME"
    echo "WAN IP: $WAN_IP"
    echo "Agent Version: $AGENT_VERSION"
    echo "OPNsense Version: $OPNSENSE_VERSION"
    
    # Use fetch with POST data in the format the server expects
    RESPONSE=$(fetch -qo - --post-data "firewall_id=${FIREWALL_ID}&hostname=${HOSTNAME}&wan_ip=${WAN_IP}&agent_version=${AGENT_VERSION}&opnsense_version=${OPNSENSE_VERSION}" "${MANAGEMENT_SERVER}/agent_checkin.php" 2>/dev/null)
    
    echo "Server response: $RESPONSE"
    
    if echo "$RESPONSE" | grep -q '"success":true'; then
        echo "✅ Check-in successful!"
        return 0
    else
        echo "❌ Check-in failed"
        return 1
    fi
}

# Install function
install_agent() {
    echo "Installing super simple agent..."
    
    # Copy to system location
    cp "$0" /usr/local/bin/simple_opnsense_agent.sh
    chmod +x /usr/local/bin/simple_opnsense_agent.sh
    
    # Remove any existing cron jobs for this
    crontab -l 2>/dev/null | grep -v "simple_opnsense_agent" | crontab -
    
    # Add new cron job
    (crontab -l 2>/dev/null; echo "*/5 * * * * /usr/local/bin/simple_opnsense_agent.sh checkin") | crontab -
    
    echo "✅ Agent installed with 5-minute cron job"
    
    # Do immediate check-in
    send_checkin
}

# Main execution
case "${1:-checkin}" in
    "install")
        install_agent
        ;;
    "checkin")
        send_checkin
        ;;
    "debug")
        # Send to debug endpoint
        RESPONSE=$(fetch -qo - --post-data "firewall_id=${FIREWALL_ID}&hostname=${HOSTNAME}&wan_ip=${WAN_IP}&agent_version=${AGENT_VERSION}&opnsense_version=${OPNSENSE_VERSION}" "${MANAGEMENT_SERVER}/debug_agent_checkin.php" 2>/dev/null)
        echo "Debug response: $RESPONSE"
        ;;
    *)
        echo "Usage: $0 {install|checkin|debug}"
        exit 1
        ;;
esac