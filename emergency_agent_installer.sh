#!/bin/sh

# Emergency OPNsense Agent Installer
# This script fixes the broken command processing in the current agent
# Copy and paste this entire script into the OPNsense shell to fix the agent

echo "=== Emergency Agent Installer ==="
echo "Starting at $(date)"

# Backup current agent
if [ -f "/usr/local/bin/opnsense_agent.sh" ]; then
    echo "Backing up current agent..."
    cp /usr/local/bin/opnsense_agent.sh /tmp/opnsense_agent.sh.bak.$(date +%s)
fi

# Create the fixed agent
echo "Creating fixed agent..."
cat > /tmp/opnsense_agent_fixed.sh << 'EOF'
#!/bin/sh

# OPNsense Management Agent v3.3 - Emergency Fixed Version
# Fixed command processing and parsing issues

AGENT_VERSION="3.3_emergency_fix"
FIREWALL_ID=21
MANAGEMENT_SERVER="https://opn.agit8or.net"
LOG_FILE="/var/log/opnsense_agent.log"

# Logging function
log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" >> "$LOG_FILE"
}

# Get system information
get_system_info() {
    # Get WAN IP
    WAN_IP=$(fetch -q -o - http://ipv4.icanhazip.com 2>/dev/null || echo "unknown")
    
    # Get LAN IP
    LAN_IP=$(ifconfig | grep -A 1 "inet " | grep -v "127.0.0.1" | head -1 | awk '{print $2}' | cut -d: -f2)
    
    # Get IPv6 (if available)
    IPV6_ADDRESS=$(ifconfig | grep "inet6" | grep -v "::1" | grep -v "fe80" | head -1 | awk '{print $2}' || echo "")
    
    # Get OPNsense version
    OPNSENSE_VERSION=$(opnsense-version 2>/dev/null || echo "unknown")
    
    # Get uptime
    UPTIME=$(uptime | awk '{print $3, $4}' | sed 's/,//')
}

# Execute commands received from server
execute_command() {
    local command_data="$1"
    local result="unknown"
    
    log_message "Executing shell command: $command_data"
    
    # Execute shell command and capture output
    output_file="/tmp/shell_output_$$.log"
    eval "$command_data" > "$output_file" 2>&1
    exit_code=$?
    
    if [ $exit_code -eq 0 ]; then
        result="success"
        log_message "Shell command completed successfully"
    else
        result="failed"
        log_message "Shell command failed with exit code: $exit_code"
    fi
    
    echo "$result"
}

# Report command result back to server
report_command_result() {
    local cmd_id="$1"
    local result="$2"
    local output_file="$3"
    
    # Read output if file exists
    if [ -f "$output_file" ]; then
        output=$(cat "$output_file" 2>/dev/null | head -50)  # Limit output size
    else
        output="No output file found"
    fi
    
    # Create result payload
    result_data=$(cat << EOJ
{
    "command_id": $cmd_id,
    "firewall_id": $FIREWALL_ID,
    "result": "$result",
    "output": "$output",
    "timestamp": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
EOJ
)
    
    # Send result to server
    fetch -q -o /dev/null --method=POST \
        --header="Content-Type: application/json" \
        --data="$result_data" \
        "$MANAGEMENT_SERVER/command_result.php" 2>/dev/null
    
    log_message "Reported result for command $cmd_id: $result"
    
    # Clean up output file
    rm -f "$output_file" 2>/dev/null
}

# Main checkin function
do_checkin() {
    log_message "Starting checkin process"
    
    # Get current system information
    get_system_info
    
    # Prepare checkin data
    checkin_data=$(cat << EOJ
{
    "firewall_id": $FIREWALL_ID,
    "agent_version": "$AGENT_VERSION",
    "api_key": "placeholder",
    "wan_ip": "$WAN_IP",
    "lan_ip": "$LAN_IP",
    "ipv6_address": "$IPV6_ADDRESS",
    "opnsense_version": "$OPNSENSE_VERSION",
    "uptime": "$UPTIME"
}
EOJ
)
    
    # Send checkin
    response=$(fetch -q -o - --method=POST \
        --header="Content-Type: application/json" \
        --data="$checkin_data" \
        "$MANAGEMENT_SERVER/agent_checkin.php" 2>/dev/null)
    
    if [ $? -eq 0 ] && [ -n "$response" ]; then
        log_message "Checkin successful"
        
        # Check for queued commands with FIXED parsing
        echo "$response" | grep -q '"queued_commands":\['
        if [ $? -eq 0 ]; then
            log_message "Found queued commands in response"
            
            # Extract the queued_commands array
            commands_json=$(echo "$response" | sed -n 's/.*"queued_commands":\(\[[^]]*\]\).*/\1/p')
            
            if [ -n "$commands_json" ]; then
                echo "$commands_json" | sed 's/},{/}\n{/g' | while IFS= read -r cmd_line; do
                    # Clean up the JSON object
                    cmd_obj=$(echo "$cmd_line" | sed 's/^\[//' | sed 's/\]$//')
                    
                    # Extract command details with FIXED patterns
                    cmd_id=$(echo "$cmd_obj" | sed -n 's/.*"id":"\([^"]*\)".*/\1/p')
                    command=$(echo "$cmd_obj" | sed -n 's/.*"command":"\([^"]*\)".*/\1/p')
                    
                    # Handle unescaped quotes in command
                    command=$(echo "$command" | sed 's/\\"/"/g')
                    
                    if [ -n "$cmd_id" ] && [ -n "$command" ]; then
                        log_message "Processing command $cmd_id: $command"
                        output_file="/tmp/cmd_output_${cmd_id}.log"
                        result=$(execute_command "$command")
                        report_command_result "$cmd_id" "$result" "$output_file"
                    else
                        log_message "Failed to parse command: $cmd_obj"
                    fi
                done
            fi
        fi
        
        # Check for OPNsense update requests
        echo "$response" | grep -q '"opnsense_update_requested":true'
        if [ $? -eq 0 ]; then
            log_message "OPNsense system update requested"
            
            # Get the update command from response
            update_command=$(echo "$response" | sed -n 's/.*"opnsense_update_command":"\([^"]*\)".*/\1/p')
            fallback_command=$(echo "$response" | sed -n 's/.*"opnsense_update_fallback":"\([^"]*\)".*/\1/p')
            
            # Execute update in background
            (
                sleep 5  # Give time for checkin to complete
                log_message "Starting OPNsense system update with command: $update_command"
                
                # Try primary update command
                if [ -n "$update_command" ]; then
                    eval "$update_command" >> "$LOG_FILE" 2>&1
                    update_result=$?
                else
                    update_result=1
                fi
                
                # Try fallback command if primary failed
                if [ $update_result -ne 0 ] && [ -n "$fallback_command" ]; then
                    log_message "Primary update failed, trying fallback: $fallback_command"
                    eval "$fallback_command" >> "$LOG_FILE" 2>&1
                    update_result=$?
                fi
                
                # Report results
                if [ $update_result -eq 0 ]; then
                    log_message "OPNsense system update completed successfully"
                else
                    log_message "OPNsense system update failed"
                fi
            ) &
        fi
        
        echo "SUCCESS"
    else
        log_message "Checkin failed or no response"
        echo "FAILED"
    fi
}

# Main execution
case "${1:-checkin}" in
    "checkin")
        do_checkin
        ;;
    "install")
        log_message "Agent installation/update completed"
        echo "Agent v$AGENT_VERSION installed successfully"
        ;;
    *)
        echo "Usage: $0 [checkin|install]"
        echo "Agent v$AGENT_VERSION ready"
        ;;
esac
EOF

# Make the fixed agent executable
chmod +x /tmp/opnsense_agent_fixed.sh

# Test the fixed agent
echo "Testing fixed agent..."
test_result=$(/tmp/opnsense_agent_fixed.sh install)
echo "Test result: $test_result"

# Stop current agent process
echo "Stopping current agent..."
pkill -f opnsense_agent 2>/dev/null
sleep 2

# Install the fixed agent
echo "Installing fixed agent..."
cp /tmp/opnsense_agent_fixed.sh /usr/local/bin/opnsense_agent.sh
chmod +x /usr/local/bin/opnsense_agent.sh

# Start the fixed agent
echo "Starting fixed agent..."
nohup /usr/local/bin/opnsense_agent.sh > /dev/null 2>&1 &

# Verify it's running
sleep 3
if pgrep -f opnsense_agent > /dev/null; then
    echo "SUCCESS: Fixed agent is now running with PID $(pgrep -f opnsense_agent)"
    echo "Agent version: 3.3_emergency_fix"
    echo "The agent should now properly process commands including reboot requests"
else
    echo "WARNING: Agent may not be running, check manually"
fi

# Test checkin
echo "Testing checkin..."
checkin_result=$(/usr/local/bin/opnsense_agent.sh checkin)
echo "Checkin result: $checkin_result"

echo "=== Installation Complete ==="
echo "The agent has been fixed and should now process commands properly."
echo "Check /var/log/opnsense_agent.log for detailed logs."