#!/bin/sh

# Simple OPNsense Agent for FreeBSD/OPNsense
# This version is optimized for FreeBSD compatibility

AGENT_VERSION="2.0"
MANAGEMENT_SERVER="https://opn.agit8or.net"
FIREWALL_ID="21"  # Set from database

# Get system information
get_system_info() {
    # Get OPNsense version
    if [ -f /usr/local/opnsense/version/opnsense ]; then
        OPNSENSE_VERSION=$(cat /usr/local/opnsense/version/opnsense)
    else
        OPNSENSE_VERSION="Unknown"
    fi
    
    # Get WAN IP - try multiple methods
    WAN_IP=""
    
    # Method 1: Try common interfaces
    for iface in em0 igb0 re0 rl0 bce0; do
        WAN_IP=$(ifconfig $iface 2>/dev/null | grep 'inet ' | grep -v '127.0.0.1' | awk '{print $2}' | head -1)
        [ -n "$WAN_IP" ] && break
    done
    
    # Method 2: External IP detection
    if [ -z "$WAN_IP" ]; then
        WAN_IP=$(fetch -qo - http://ipinfo.io/ip 2>/dev/null || echo "Unknown")
    fi
    
    # Get hostname
    HOSTNAME=$(hostname)
    
    # Hardware ID
    MAC=$(ifconfig | grep ether | head -1 | awk '{print $2}')
    HARDWARE_ID="mac-${MAC}"
    
    # Create JSON data
    JSON_DATA="{\"firewall_id\":\"$FIREWALL_ID\",\"hardware_id\":\"$HARDWARE_ID\",\"hostname\":\"$HOSTNAME\",\"ip_address\":\"$WAN_IP\",\"opnsense_version\":\"$OPNSENSE_VERSION\",\"agent_version\":\"$AGENT_VERSION\"}"
}

# Check-in function
checkin() {
    get_system_info
    
    echo "Sending check-in data..."
    echo "Data: $JSON_DATA"
    
    # Send check-in data
    RESPONSE=$(fetch -qo - "${MANAGEMENT_SERVER}/agent_checkin.php" \
        --post-data "$JSON_DATA" 2>/dev/null)
    
    if [ $? -eq 0 ] && [ -n "$RESPONSE" ]; then
        echo "Check-in successful: $RESPONSE"
    else
        echo "Check-in failed"
    fi
}

# Install function
install() {
    echo "Installing agent..."
    
    # Copy script to proper location
    cp "$0" "/usr/local/bin/opnsense_agent_simple.sh"
    chmod +x "/usr/local/bin/opnsense_agent_simple.sh"
    
    # Add to cron if not already there
    if ! crontab -l 2>/dev/null | grep -q "opnsense_agent_simple"; then
        (crontab -l 2>/dev/null; echo "*/5 * * * * /usr/local/bin/opnsense_agent_simple.sh >/dev/null 2>&1") | crontab -
        echo "Added cron job for 5-minute check-ins"
    fi
    
    echo "Agent installation complete"
    
    # Perform initial check-in
    checkin
}

# Main execution
case "${1:-checkin}" in
    "install")
        install
        ;;
    "checkin")
        checkin
        ;;
    *)
        checkin
        ;;
esac