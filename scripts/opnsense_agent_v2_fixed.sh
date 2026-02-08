#!/bin/sh

# OPNsense Management Agent v2.0
# Enhanced with command processing and improved version reporting

# Configuration
MANAGEMENT_SERVER="https://opn.agit8or.net"
AGENT_VERSION="2.1.3"
FIREWALL_ID="21"  # This firewall's ID in the management system
LOG_FILE="/var/log/opnsense_agent.log"

# Hardware ID generation (persistent)
HARDWARE_ID_FILE="/tmp/opnsense_hardware_id"
if [ ! -f "$HARDWARE_ID_FILE" ]; then
    openssl rand -hex 16 > "$HARDWARE_ID_FILE"
fi
HARDWARE_ID=$(cat "$HARDWARE_ID_FILE")

# Get system information
HOSTNAME=$(hostname)
WAN_IP=$(curl -s -m 5 https://ipinfo.io/ip 2>/dev/null || echo "unknown")
LAN_IP=$(ifconfig | grep 'inet ' | grep -v '127.0.0.1' | head -1 | awk '{print $2}')
IPV6_ADDRESS=$(ifconfig | grep 'inet6' | grep -v '::1' | grep -v 'fe80' | head -1 | awk '{print $2}')

# Get OPNsense version information
get_opnsense_version() {
    # Try multiple methods to get version info
    if [ -f "/usr/local/opnsense/version/core" ]; then
        CORE_VERSION=$(cat /usr/local/opnsense/version/core 2>/dev/null)
    else
        CORE_VERSION="unknown"
    fi
    
    # Get detailed version info
    if command -v opnsense-version >/dev/null 2>&1; then
        VERSION_OUTPUT=$(opnsense-version -v 2>/dev/null)
        if [ $? -eq 0 ]; then
            FULL_VERSION="$VERSION_OUTPUT"
        else
            FULL_VERSION="$CORE_VERSION"
        fi
    else
        FULL_VERSION="$CORE_VERSION"
    fi
    
    # Create JSON version data - handle core version properly
    if echo "$CORE_VERSION" | grep -q '^{'; then
        # Core version is already JSON, extract just the version number
        PRODUCT_VERSION=$(echo "$CORE_VERSION" | python3 -c "import json, sys; data=json.load(sys.stdin); print(data.get('product_version', data.get('CORE_VERSION', 'unknown')))" 2>/dev/null || echo "unknown")
    else
        PRODUCT_VERSION="$CORE_VERSION"
    fi
    
    cat << EOF
{
    "product_version": "$PRODUCT_VERSION",
    "firmware_version": "$FULL_VERSION",
    "system_version": "$(uname -r)",
    "architecture": "$(uname -m)",
    "last_updated": "$(date -Iseconds)"
}
EOF
}

# Log function
log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" >> "$LOG_FILE"
}

# Execute command function
execute_command() {
    local command_type="$1"
    local command_data="$2"
    local result="unknown"
    
    log_message "Executing command: $command_type"
    
    case "$command_type" in
        "firmware_update")
            log_message "Starting firmware update process..."
            # Execute OPNsense firmware update
            if command -v opnsense-update >/dev/null 2>&1; then
                # Use opnsense-update for firmware updates
                opnsense-update -f > /tmp/update_output.log 2>&1
                if [ $? -eq 0 ]; then
                    result="success"
                    log_message "Firmware update completed successfully"
                else
                    result="failed"
                    log_message "Firmware update failed"
                fi
            else
                # Fallback: use pkg for base system updates
                pkg update > /tmp/update_output.log 2>&1
                pkg upgrade -y >> /tmp/update_output.log 2>&1
                if [ $? -eq 0 ]; then
                    result="success"
                    log_message "System update completed successfully"
                else
                    result="failed"
                    log_message "System update failed"
                fi
            fi
            ;;
        "update_agent")
            log_message "Starting agent update process: $command_data"
            # Perform system update automatically
            pkg update > /tmp/update_output.log 2>&1
            if [ $? -eq 0 ]; then
                log_message "Repository update completed"
                # Check for available updates and apply them
                pkg upgrade -y >> /tmp/update_output.log 2>&1
                if [ $? -eq 0 ]; then
                    result="success"
                    log_message "Agent update completed successfully for $command_data"
                else
                    result="partial"
                    log_message "Agent update completed with warnings for $command_data"
                fi
            else
                result="failed"
                log_message "Agent update failed - repository update error"
            fi
            ;;
        "shell")
            log_message "Executing shell command: $command_data"
            # Execute arbitrary shell command
            eval "$command_data" > /tmp/shell_output.log 2>&1
            if [ $? -eq 0 ]; then
                result="success"
                log_message "Shell command completed successfully: $command_data"
            else
                result="failed"
                log_message "Shell command failed: $command_data"
            fi
            ;;
        "api_test")
            log_message "Testing API connectivity..."
            # Test local API connection
            if curl -s -k https://localhost/api/core/firmware/status >/dev/null 2>&1; then
                result="success"
                log_message "API test successful"
            else
                result="failed"
                log_message "API test failed"
            fi
            ;;
        *)
            log_message "Unknown command type: $command_type"
            result="unknown_command"
            ;;
    esac
    
    echo "$result"
}

# Report command results
report_command_result() {
    local command_id="$1"
    local result="$2"
    local output_file="/tmp/update_output.log"
    
    # Get command output if available
    local output=""
    if [ -f "$output_file" ]; then
        output=$(tail -20 "$output_file" 2>/dev/null | base64 -w 0)
    fi
    
    # Send result back to management server
    curl -s -k -X POST "$MANAGEMENT_SERVER/api/command_result.php" \
        -H "Content-Type: application/json" \
        -d "{
            \"command_id\": \"$command_id\",
            \"result\": \"$result\",
            \"output\": \"$output\",
            \"timestamp\": \"$(date -Iseconds)\",
            \"agent_version\": \"$AGENT_VERSION\"
        }" >/dev/null 2>&1
}

# Main checkin function
perform_checkin() {
    log_message "Starting agent checkin..."
    
    # Get OPNsense version data
    OPNSENSE_VERSION=$(get_opnsense_version)
    
    # Prepare checkin data
    CHECKIN_DATA=$(cat << EOF
{
    "firewall_id": $FIREWALL_ID,
    "hardware_id": "$HARDWARE_ID",
    "hostname": "$HOSTNAME",
    "agent_version": "$AGENT_VERSION",
    "wan_ip": "$WAN_IP",
    "lan_ip": "$LAN_IP",
    "ipv6_address": "$IPV6_ADDRESS",
    "opnsense_version": $OPNSENSE_VERSION
}
EOF
)
    
    # Perform checkin
    RESPONSE=$(curl -s -k -X POST "$MANAGEMENT_SERVER/agent_checkin.php" \
        -H "Content-Type: application/json" \
        -d "$CHECKIN_DATA" 2>/dev/null)
    
    if [ $? -eq 0 ] && [ -n "$RESPONSE" ]; then
        log_message "Checkin successful: $RESPONSE"
        
        # Check for pending commands
        COMMANDS=$(echo "$RESPONSE" | python3 -c "
import json, sys
try:
    data = json.load(sys.stdin)
    if 'commands' in data:
        for cmd in data['commands']:
            print(f\"{cmd['command_id']}|{cmd['command_type']}|{cmd['command_data']}\")
except:
    pass
" 2>/dev/null)
        
        # Execute any pending commands
        if [ -n "$COMMANDS" ]; then
            # Use process substitution instead of pipe to avoid subshell
            while IFS='|' read -r cmd_id cmd_type cmd_data; do
                if [ -n "$cmd_id" ] && [ -n "$cmd_type" ]; then
                    log_message "Processing command: $cmd_id ($cmd_type)"
                    result=$(execute_command "$cmd_type" "$cmd_data")
                    log_message "Command $cmd_id completed with result: $result"
                    report_command_result "$cmd_id" "$result"
                    log_message "Result reported for command $cmd_id"
                fi
            done <<< "$COMMANDS"
        fi
        
        # Extract checkin interval
        INTERVAL=$(echo "$RESPONSE" | python3 -c "
import json, sys
try:
    data = json.load(sys.stdin)
    print(data.get('checkin_interval', 300))
except:
    print(300)
" 2>/dev/null)
        
        echo "$INTERVAL"
    else
        log_message "Checkin failed or no response"
        echo "300"  # Default interval on failure
    fi
}

# Disable marketing website (for managed firewalls)
disable_marketing_website() {
    log_message "Disabling marketing website service on managed firewall"
    
    # Stop and disable nginx on port 88 if it exists
    if [ -f "/usr/local/etc/nginx/conf.d/marketing-website.conf" ]; then
        rm -f /usr/local/etc/nginx/conf.d/marketing-website.conf
        log_message "Removed marketing website nginx config"
    fi
    
    # Check for FreeBSD nginx service
    if service nginx status >/dev/null 2>&1; then
        service nginx reload >/dev/null 2>&1
        log_message "Reloaded nginx to apply changes"
    fi
    
    # Disable any marketing website processes on port 88
    if netstat -an | grep ':88 ' >/dev/null 2>&1; then
        # Kill any process listening on port 88
        PIDS=$(sockstat -l | grep ':88' | awk '{print $3}' | sort -u)
        for PID in $PIDS; do
            if [ -n "$PID" ] && [ "$PID" != "PID" ]; then
                kill -TERM "$PID" 2>/dev/null
                log_message "Terminated process $PID listening on port 88"
            fi
        done
    fi
    
    # Remove any marketing website files if they exist
    if [ -d "/usr/local/www/opnmanager-website" ]; then
        rm -rf /usr/local/www/opnmanager-website
        log_message "Removed marketing website files"
    fi
    
    log_message "Marketing website disable complete"
}

# Main execution
main() {
    # Create log file if it doesn't exist
    touch "$LOG_FILE"
    
    # Disable marketing website on first run
    if [ ! -f "/tmp/marketing_disabled" ]; then
        disable_marketing_website
        touch /tmp/marketing_disabled
    fi
    
    # Perform checkin and get interval
    NEXT_INTERVAL=$(perform_checkin)
    
    # Validate interval
    if ! echo "$NEXT_INTERVAL" | grep -q '^[0-9]\+$'; then
        NEXT_INTERVAL=300
    fi
    
    log_message "Next checkin in $NEXT_INTERVAL seconds"
    
    # Schedule next run
    echo "*/5 * * * * /usr/local/bin/opnsense_agent_v2.sh" | crontab -
}

# Run main function
main