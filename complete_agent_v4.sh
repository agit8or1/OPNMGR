#!/bin/sh

# Complete OPNsense Agent v4 with Command Processing
# This script installs the full-featured agent

echo "Installing OPNsense Agent v4 with command processing..."

# Create the complete agent script
cat > /usr/local/bin/opnsense_agent.sh << 'EOF'
#!/bin/sh

AGENT_VERSION="4.0_complete_with_commands"
SERVER_URL="https://opn.agit8or.net"
FIREWALL_ID="21"
CHECKIN_INTERVAL=120
DEBUG=0

log_message() {
    echo "$(date): $1" >> /var/log/opnsense_agent.log
    [ "$DEBUG" = "1" ] && echo "$(date): $1"
}

checkin_agent() {
    log_message "Agent checkin started (v$AGENT_VERSION)"
    
    WAN_IP=$(ifconfig em0 | grep "inet " | awk '{print $2}' | head -1)
    [ -z "$WAN_IP" ] && WAN_IP="unknown"
    
    UPTIME=$(uptime | sed 's/.*up[[:space:]]*//; s/,[[:space:]]*[0-9]* user.*//')
    HOSTNAME=$(hostname)
    
    OPNsense_VERSION=""
    if command -v opnsense-version >/dev/null 2>&1; then
        OPNsense_VERSION=$(opnsense-version 2>/dev/null || echo "unknown")
    else
        OPNsense_VERSION=$(uname -r)
    fi
    
    # Create JSON payload
    JSON_DATA=$(cat << JSONEOF
{
    "firewall_id": "${FIREWALL_ID}",
    "agent_version": "${AGENT_VERSION}",
    "wan_ip": "${WAN_IP}",
    "uptime": "${UPTIME}",
    "hostname": "${HOSTNAME}",
    "opnsense_version": "${OPNsense_VERSION}"
}
JSONEOF
)
    
    RESPONSE=$(curl -k -s -X POST \
        -H "Content-Type: application/json" \
        -d "${JSON_DATA}" \
        "${SERVER_URL}/agent_checkin.php" 2>/dev/null)
    
    if [ $? -eq 0 ] && [ -n "$RESPONSE" ]; then
        log_message "Checkin successful: $RESPONSE"
        echo "$RESPONSE"
        return 0
    else
        log_message "Checkin failed"
        return 1
    fi
}

process_commands() {
    log_message "Checking for commands..."
    
    COMMANDS_RESPONSE=$(curl -k -s -X POST \
        -H "Content-Type: application/x-www-form-urlencoded" \
        -d "firewall_id=${FIREWALL_ID}" \
        "${SERVER_URL}/api/get_commands.php" 2>/dev/null)
    
    if [ $? -ne 0 ] || [ -z "$COMMANDS_RESPONSE" ]; then
        log_message "Failed to get commands"
        return 1
    fi
    
    # Check if we have commands (simple JSON check)
    if echo "$COMMANDS_RESPONSE" | grep -q '"commands":\[' && ! echo "$COMMANDS_RESPONSE" | grep -q '"commands":\[\]'; then
        log_message "Commands found, processing..."
        
        # Simple JSON parsing for command IDs and commands
        echo "$COMMANDS_RESPONSE" | sed 's/},{/}\n{/g' | while IFS= read -r line; do
            if echo "$line" | grep -q '"id":'; then
                CMD_ID=$(echo "$line" | sed 's/.*"id":"\?\([^",]*\)"\?.*/\1/')
                COMMAND=$(echo "$line" | sed 's/.*"command":"\([^"]*\)".*/\1/')
                
                if [ -n "$COMMAND" ] && [ -n "$CMD_ID" ] && [ "$COMMAND" != "$line" ]; then
                    # Decode JSON escaped characters
                    COMMAND=$(echo "$COMMAND" | sed 's/\\"/"/g; s/\\\\/\\/g; s/\\\//\//g')
                    execute_command "$CMD_ID" "$COMMAND"
                fi
            fi
        done
    else
        log_message "No commands to process"
    fi
}

execute_command() {
    local CMD_ID="$1"
    local COMMAND="$2"
    
    log_message "Executing command ID $CMD_ID: $COMMAND"
    
    # Check if this is an OPNsense update command
    if echo "$COMMAND" | grep -q "opnsense-update\|configctl.*firmware"; then
        log_message "Detected OPNsense update command - enabling auto-reboot"
        enable_auto_reboot_setting
    fi
    
    # Execute the command and capture output
    RESULT=$(eval "$COMMAND" 2>&1)
    EXIT_CODE=$?
    
    # Report the result
    report_result "$CMD_ID" "$RESULT" "$EXIT_CODE"
}

enable_auto_reboot_setting() {
    # Enable "Always reboot after successful update" setting
    log_message "Configuring auto-reboot after updates"
    
    # Method 1: Try configctl if available
    if command -v configctl >/dev/null 2>&1; then
        configctl system reboot_required 1 2>/dev/null || true
        configctl firmware set_auto_reboot 1 2>/dev/null || true
    fi
    
    # Method 2: Try direct config modification
    if [ -f /conf/config.xml ]; then
        # Backup current config
        cp /conf/config.xml /tmp/config_backup_$(date +%s).xml
        
        # Add auto-reboot setting to firmware section
        sed -i '/<firmware>/,/<\/firmware>/{
            /<auto_reboot>/d
            /<\/firmware>/i\
                <auto_reboot>1</auto_reboot>
        }' /conf/config.xml 2>/dev/null || true
        
        # If no firmware section exists, add it
        if ! grep -q "<firmware>" /conf/config.xml; then
            sed -i '/<\/opnsense>/i\
    <firmware>\
        <auto_reboot>1</auto_reboot>\
    </firmware>' /conf/config.xml 2>/dev/null || true
        fi
        
        log_message "Auto-reboot setting enabled in config.xml"
    fi
    
    # Method 3: CLI command if available
    if command -v opnsense-config >/dev/null 2>&1; then
        opnsense-config set firmware.auto_reboot 1 2>/dev/null || true
    fi
}

report_result() {
    local CMD_ID="$1"
    local RESULT="$2"
    local EXIT_CODE="$3"
    
    # Create JSON payload for result
    JSON_RESULT=$(cat << JSONEOF
{
    "command_id": "${CMD_ID}",
    "firewall_id": "${FIREWALL_ID}",
    "result": "$([ "$EXIT_CODE" = "0" ] && echo "success" || echo "failed")",
    "output": "$(echo "$RESULT" | sed 's/"/\\"/g; s/\\/\\\\/g')"
}
JSONEOF
)
    
    RESPONSE=$(curl -k -s -X POST \
        -H "Content-Type: application/json" \
        -d "${JSON_RESULT}" \
        "${SERVER_URL}/api/command_result.php" 2>/dev/null)
    
    if [ $? -eq 0 ]; then
        log_message "Result reported for command $CMD_ID (exit code: $EXIT_CODE)"
    else
        log_message "Failed to report result for command $CMD_ID"
    fi
}

main() {
    case "$1" in
        "checkin")
            checkin_agent
            ;;
        "commands")
            process_commands
            ;;
        "daemon")
            log_message "Starting agent daemon (version $AGENT_VERSION)"
            while true; do
                checkin_agent
                process_commands
                sleep $CHECKIN_INTERVAL
            done
            ;;
        *)
            # Default behavior - do both checkin and commands
            checkin_agent
            process_commands
            ;;
    esac
}

main "$@"
EOF

# Make it executable
chmod +x /usr/local/bin/opnsense_agent.sh

# Kill any existing agent processes
pkill -f opnsense_agent.sh 2>/dev/null || true

# Test the new agent
echo "Testing new agent..."
/usr/local/bin/opnsense_agent.sh checkin

# Create cron job for regular checkins and command processing
crontab -l 2>/dev/null | grep -v opnsense | crontab -
(crontab -l 2>/dev/null; echo "*/2 * * * * /usr/local/bin/opnsense_agent.sh >/dev/null 2>&1") | crontab -

echo "OPNsense Agent v4 installation completed!"
echo "Agent version: 4.0_complete_with_commands"
echo "Features: Checkin + Command Processing + Result Reporting"
echo "Schedule: Every 2 minutes via cron"

# Test command processing immediately
/usr/local/bin/opnsense_agent.sh commands

echo "Installation and test completed."