#!/bin/sh

# OPNsense Management Agent v2.3
# Enhanced with automated backup functionality

# Configuration
MANAGEMENT_SERVER="https://opn.agit8or.net"
AGENT_VERSION="2.3.0"
FIREWALL_ID="21"  # This firewall's ID in the management system
LOG_FILE="/var/log/opnsense_agent.log"
BACKUP_TRACKER="/tmp/opnsense_last_backup"

# Hardware ID generation (persistent)
HARDWARE_ID_FILE="/tmp/opnsense_hardware_id"
if [ ! -f "$HARDWARE_ID_FILE" ]; then
    openssl rand -hex 16 > "$HARDWARE_ID_FILE"
fi
HARDWARE_ID=$(cat "$HARDWARE_ID_FILE")

# Get system information
HOSTNAME=$(hostname)
WAN_IP=$(curl -s -m 5 https://ipinfo.io/ip 2>/dev/null || echo "unknown")

# Get LAN IP (look for private network ranges, exclude WAN IP)
LAN_IP=$(ifconfig | grep 'inet ' | grep -v '127.0.0.1' | awk '{print $2}' | grep -E '^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)' | head -1)
if [ -z "$LAN_IP" ]; then
    # Fallback: get first non-localhost, non-WAN IP
    LAN_IP=$(ifconfig | grep 'inet ' | grep -v '127.0.0.1' | awk '{print $2}' | grep -v "$WAN_IP" | head -1)
fi
if [ -z "$LAN_IP" ]; then
    LAN_IP="unknown"
fi

# Get IPv6 address (prefer global unicast, avoid link-local)
IPV6_ADDRESS=$(ifconfig | grep 'inet6' | grep -v '::1' | grep -v 'fe80:' | grep -v '%' | awk '{print $2}' | head -1)
if [ -z "$IPV6_ADDRESS" ]; then
    IPV6_ADDRESS="unknown"
fi

# Get system uptime in a clean format
UPTIME=$(uptime | sed 's/.*up \([^,]*\).*/\1/' | sed 's/^ *//' | cut -c1-90)

# Logging function
log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') [$$] $1" >> "$LOG_FILE"
}

# Check if backup is needed (24-hour intervals with randomization)
check_backup_needed() {
    local current_time=$(date +%s)
    local backup_interval=86400  # 24 hours in seconds
    local randomize_window=3600  # 1 hour randomization window
    
    # Check if backup tracker exists
    if [ ! -f "$BACKUP_TRACKER" ]; then
        # First backup - randomize within next hour
        local random_delay=$((RANDOM % randomize_window))
        echo $((current_time + random_delay)) > "$BACKUP_TRACKER"
        log_message "First backup scheduled in $((random_delay / 60)) minutes"
        return 1
    fi
    
    local last_backup_time=$(cat "$BACKUP_TRACKER" 2>/dev/null || echo "0")
    local time_since_backup=$((current_time - last_backup_time))
    
    if [ $time_since_backup -ge $backup_interval ]; then
        return 0  # Backup needed
    else
        return 1  # Backup not needed yet
    fi
}

# Create automated backup
create_automated_backup() {
    log_message "Starting automated configuration backup..."
    
    local timestamp=$(date '+%Y%m%d_%H%M%S')
    local backup_filename="auto_backup_${FIREWALL_ID}_${timestamp}.xml"
    local temp_backup="/tmp/${backup_filename}"
    
    # Create configuration backup using OPNsense CLI
    if command -v configctl >/dev/null 2>&1; then
        # Use configctl if available
        configctl backup save "$temp_backup" >/dev/null 2>&1
        backup_result=$?
    elif [ -f "/conf/config.xml" ]; then
        # Fallback to copying config.xml directly
        cp /conf/config.xml "$temp_backup"
        backup_result=$?
    else
        log_message "ERROR: Cannot create backup - no config source found"
        return 1
    fi
    
    if [ $backup_result -eq 0 ] && [ -f "$temp_backup" ]; then
        log_message "Configuration backup created: $backup_filename"
        
        # Upload backup to management server
        upload_result=$(curl -s -k -X POST "$MANAGEMENT_SERVER/api/upload_backup.php" \
            -F "firewall_id=$FIREWALL_ID" \
            -F "backup_file=@$temp_backup" \
            -F "filename=$backup_filename" \
            -F "backup_type=automated" \
            -w "%{http_code}")
        
        # Clean up temporary file
        rm -f "$temp_backup"
        
        if echo "$upload_result" | grep -q "200$"; then
            log_message "✅ Automated backup uploaded successfully: $backup_filename"
            # Update backup tracker
            echo "$(date +%s)" > "$BACKUP_TRACKER"
            return 0
        else
            log_message "❌ Failed to upload backup: HTTP $upload_result"
            return 1
        fi
    else
        log_message "❌ Failed to create configuration backup"
        return 1
    fi
}

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
    
    # Extract simple version string for database (max 32 chars)
    if echo "$CORE_VERSION" | grep -q '^{'; then
        # Core version is JSON, extract just the version number
        PRODUCT_VERSION=$(echo "$CORE_VERSION" | python3 -c "import json, sys; data=json.load(sys.stdin); print(data.get('product_version', data.get('CORE_VERSION', 'unknown')))" 2>/dev/null || echo "unknown")
    else
        # Core version is plain text
        PRODUCT_VERSION="$CORE_VERSION"
    fi
    
    # Return truncated version string for database storage
    echo "$PRODUCT_VERSION" | cut -c1-30
}

# Execute commands received from management server
execute_command() {
    local command_type="$1"
    local command_data="$2"
    local result=""
    
    case "$command_type" in
        "reboot")
            log_message "Executing reboot command: $command_data"
            # Schedule reboot in 10 seconds to allow response
            (sleep 10 && shutdown -r now) &
            result="reboot_scheduled"
            ;;
        "shell")
            log_message "Executing shell command: $command_data"
            # Execute with timeout and capture output
            timeout 300 sh -c "$command_data" > /tmp/command_output.log 2>&1
            cmd_exit_code=$?
            if [ $cmd_exit_code -eq 0 ]; then
                result="success"
            elif [ $cmd_exit_code -eq 124 ]; then
                result="timeout"
            else
                result="failed"
            fi
            ;;
        "update_agent")
            log_message "Starting agent update process: $command_data"
            # Download and install new agent
            if fetch -o /tmp/opnsense_agent_new.sh "$command_data" && chmod +x /tmp/opnsense_agent_new.sh; then
                # Verify the downloaded agent
                if grep -q "OPNsense Management Agent" /tmp/opnsense_agent_new.sh; then
                    cp /tmp/opnsense_agent_new.sh /usr/local/bin/opnsense_agent_v2.sh
                    log_message "Agent update completed successfully for $command_data"
                    result="agent_updated"
                else
                    log_message "Agent update completed with warnings for $command_data"
                    result="agent_update_warning"
                fi
            else
                log_message "Agent update failed - repository update error"
                result="agent_update_failed"
            fi
            ;;
        "backup_config")
            log_message "Manual backup requested: $command_data"
            if create_automated_backup; then
                result="backup_created"
            else
                result="backup_failed"
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
    local output_file="/tmp/command_output.log"
    
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
    
    # Check if automated backup is needed
    if check_backup_needed; then
        log_message "Automated backup due - creating backup..."
        create_automated_backup
    fi
    
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
    "opnsense_version": "$OPNSENSE_VERSION",
    "uptime": "$UPTIME"
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
    # Also check for queued_commands (newer format)
    if 'queued_commands' in data:
        for cmd in data['queued_commands']:
            print(f\"{cmd['id']}|shell|{cmd['command']}\")
except:
    pass
" 2>/dev/null)
        
        # Execute any pending commands
        if [ -n "$COMMANDS" ]; then
            # Use echo and pipe instead of here-string for better compatibility
            echo "$COMMANDS" | while IFS='|' read -r cmd_id cmd_type cmd_data; do
                if [ -n "$cmd_id" ] && [ -n "$cmd_type" ]; then
                    log_message "Processing command: $cmd_id ($cmd_type)"
                    result=$(execute_command "$cmd_type" "$cmd_data")
                    log_message "Command $cmd_id completed with result: $result"
                    report_command_result "$cmd_id" "$result"
                    log_message "Result reported for command $cmd_id"
                fi
            done
        fi
        
        # Check for OPNsense system update request
        OPNSENSE_UPDATE=$(echo "$RESPONSE" | python3 -c "
import json, sys
try:
    data = json.load(sys.stdin)
    if data.get('opnsense_update_requested', False):
        print(data.get('opnsense_update_command', '/usr/sbin/pkg update && /usr/sbin/pkg upgrade -y'))
except:
    pass
" 2>/dev/null)
        
        # Execute OPNsense update if requested
        if [ -n "$OPNSENSE_UPDATE" ]; then
            log_message "OPNsense system update requested: $OPNSENSE_UPDATE"
            
            # Execute the update command in background
            nohup sh -c "
                echo 'Starting OPNsense system update...' >> $LOG_FILE
                $OPNSENSE_UPDATE >> $LOG_FILE 2>&1
                if [ \$? -eq 0 ]; then
                    echo 'OPNsense system update completed successfully' >> $LOG_FILE
                else
                    echo 'OPNsense system update failed or completed with warnings' >> $LOG_FILE
                fi
            " > /dev/null 2>&1 &
            
            log_message "OPNsense update initiated in background"
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