#!/bin/sh

# OPNsense Management Agent v2.1 - Fixed for FreeBSD
# Uses curl instead of fetch for POST requests

AGENT_VERSION="2.1"
SCRIPT_PATH="/usr/local/bin/opnsense_agent_v2.sh"
CONFIG_FILE="/usr/local/etc/opnsense_agent.conf"
LOCK_FILE="/tmp/opnsense_agent.lock"
LOG_FILE="/var/log/opnsense_agent.log"

# Default configuration
FIREWALL_ID=""
MANAGEMENT_SERVER="https://opn.agit8or.net"
API_KEY=""
CHECKIN_INTERVAL=300

# Load configuration
if [ -f "$CONFIG_FILE" ]; then
    . "$CONFIG_FILE"
fi

# Logging function
log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" >> "$LOG_FILE"
}

# Check-in function using curl
checkin() {
    log_message "Starting check-in..."
    
    # Get system info
    HOSTNAME=$(hostname)
    WAN_IP=$(fetch -qo - "https://api.ipify.org" 2>/dev/null || echo "unknown")
    
    # Get OPNsense version
    if [ -f /usr/local/opnsense/version/opnsense ]; then
        OPNSENSE_VERSION=$(cat /usr/local/opnsense/version/opnsense)
    else
        OPNSENSE_VERSION="Unknown"
    fi
    
    # Create JSON data
    if [ -n "$FIREWALL_ID" ] && [ "$FIREWALL_ID" != "" ]; then
        JSON_DATA="{\"firewall_id\":$FIREWALL_ID,\"hostname\":\"$HOSTNAME\",\"agent_version\":\"$AGENT_VERSION\",\"wan_ip\":\"$WAN_IP\",\"opnsense_version\":\"$OPNSENSE_VERSION\"}"
    else
        JSON_DATA="{\"hostname\":\"$HOSTNAME\",\"agent_version\":\"$AGENT_VERSION\",\"wan_ip\":\"$WAN_IP\",\"opnsense_version\":\"$OPNSENSE_VERSION\"}"
    fi
    
    log_message "Sending data: $JSON_DATA"
    
    # Send check-in data using curl
    RESPONSE=$(curl -s -X POST -H "Content-Type: application/json" \
        -d "$JSON_DATA" \
        "${MANAGEMENT_SERVER}/agent_checkin.php" 2>/dev/null)
    
    if [ $? -eq 0 ] && [ -n "$RESPONSE" ]; then
        log_message "Check-in successful: $RESPONSE"
        
        # Parse response to get firewall_id if we don't have one
        if [ -z "$FIREWALL_ID" ] || [ "$FIREWALL_ID" = "" ]; then
            NEW_FIREWALL_ID=$(echo "$RESPONSE" | grep -o '"firewall_id":[0-9]*' | cut -d':' -f2)
            if [ -n "$NEW_FIREWALL_ID" ] && [ "$NEW_FIREWALL_ID" != "" ]; then
                # Update config file
                sed -i '' "s/FIREWALL_ID=\".*\"/FIREWALL_ID=\"$NEW_FIREWALL_ID\"/" "$CONFIG_FILE" 2>/dev/null || \
                echo "FIREWALL_ID=\"$NEW_FIREWALL_ID\"" >> "$CONFIG_FILE"
                log_message "Updated firewall ID: $NEW_FIREWALL_ID"
                FIREWALL_ID="$NEW_FIREWALL_ID"
            fi
        fi
    else
        log_message "Check-in failed"
    fi
}

# Install function
install_agent() {
    log_message "Installing agent..."
    
    # Copy script to permanent location
    cp "$0" "$SCRIPT_PATH"
    chmod +x "$SCRIPT_PATH"
    
    # Create config file if it doesn't exist
    if [ ! -f "$CONFIG_FILE" ]; then
        cat > "$CONFIG_FILE" << EOF
FIREWALL_ID="21"
MANAGEMENT_SERVER="https://opn.agit8or.net"
API_KEY=""
CHECKIN_INTERVAL=300
EOF
    fi
    
    # Add to cron if not already there
    if ! crontab -l 2>/dev/null | grep -q "$SCRIPT_PATH"; then
        (crontab -l 2>/dev/null; echo "*/5 * * * * $SCRIPT_PATH >/dev/null 2>&1") | crontab -
        log_message "Added cron job for 5-minute check-ins"
    fi
    
    log_message "Agent installation complete"
    
    # Perform initial check-in
    checkin
    exit 0
}

# Main execution
case "${1:-checkin}" in
    "install")
        install_agent
        ;;
    "checkin")
        checkin
        ;;
    *)
        checkin
        ;;
esac