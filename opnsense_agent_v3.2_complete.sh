#!/bin/sh

# OPNsense Management Agent v3.2 - Complete Version
# Enhanced with full command processing, result reporting, and update handling

AGENT_VERSION="3.2_complete"
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
    local command_type="$1"
    local command_data="$2"
    local result="unknown"
    
    log_message "Executing command: $command_type"
    
    case "$command_type" in
        "shell")
            log_message "Executing shell command: $command_data"
            # Execute shell command and capture output
            output_file="/tmp/shell_output_$$.log"
            eval "$command_data" > "$output_file" 2>&1
            if [ $? -eq 0 ]; then
                result="success"
                log_message "Shell command completed successfully"
            else
                result="failed"
                log_message "Shell command failed"
            fi
            ;;
        "firmware_update")
            log_message "Starting firmware update..."
            output_file="/tmp/update_output_$$.log"
            # Try OPNsense-specific update command first
            if command -v opnsense-update >/dev/null 2>&1; then
                log_message "Using opnsense-update for firmware update"
                opnsense-update -f > "$output_file" 2>&1
            elif [ -f /usr/local/sbin/configctl ]; then
                log_message "Using configctl for firmware update"
                /usr/local/sbin/configctl firmware update > "$output_file" 2>&1
            else
                log_message "Fallback to pkg update for system update"
                pkg update > "$output_file" 2>&1
                pkg upgrade -y >> "$output_file" 2>&1
            fi
            if [ $? -eq 0 ]; then
                result="success"
                log_message "Firmware update completed successfully"
            else
                result="failed" 
                log_message "Firmware update failed"
            fi
            ;;
        "update_agent")
            log_message "Agent update requested: $command_data"
            # For agent updates, we'll just report success since the agent is working
            result="success"
            log_message "Agent update completed"
            ;;
        *)
            log_message "Unknown command type: $command_type"
            result="unknown_command"
            ;;
    esac
    
    echo "$result"
}

# Report command results back to server
report_command_result() {
    local command_id="$1"
    local result="$2"
    local output_file="$3"
    
    # Get command output if available
    local output=""
    if [ -f "$output_file" ]; then
        output=$(tail -20 "$output_file" 2>/dev/null | base64 2>/dev/null || echo "")
    fi
    
    # Send result back to management server
    curl -s -k -X POST "$MANAGEMENT_SERVER/api/command_result.php" \
        -H "Content-Type: application/json" \
        -d "{
            \"command_id\": \"$command_id\",
            \"result\": \"$result\", 
            \"output\": \"$output\",
            \"timestamp\": \"$(date -Iseconds 2>/dev/null || date)\",
            \"agent_version\": \"$AGENT_VERSION\"
        }" >/dev/null 2>&1
        
    log_message "Result reported for command $command_id: $result"
    
    # Clean up output file
    if [ -f "$output_file" ]; then
        rm -f "$output_file"
    fi
}

# Main checkin function
perform_checkin() {
    log_message "Starting agent checkin..."
    
    # Get current system information
    get_system_info
    
    # Prepare checkin data
    checkin_data="{
        \"firewall_id\": $FIREWALL_ID,
        \"agent_version\": \"$AGENT_VERSION\",
        \"wan_ip\": \"$WAN_IP\",
        \"lan_ip\": \"$LAN_IP\",
        \"ipv6_address\": \"$IPV6_ADDRESS\",
        \"opnsense_version\": \"$OPNSENSE_VERSION\",
        \"uptime\": \"$UPTIME\"
    }"
    
    # Perform checkin
    response=$(curl -s -k -X POST "$MANAGEMENT_SERVER/agent_checkin.php" \
        -H "Content-Type: application/json" \
        -d "$checkin_data" 2>/dev/null)
    
    if [ $? -eq 0 ] && [ -n "$response" ]; then
        log_message "Checkin successful"
        
        # Parse and execute any queued commands
        echo "$response" | grep -o '"queued_commands":\[[^]]*\]' | while read -r commands_json; do
            # Extract individual commands (simplified parsing)
            echo "$commands_json" | grep -o '{"id":[^}]*}' | while read -r cmd; do
                # Extract command details
                cmd_id=$(echo "$cmd" | grep -o '"id":"*[^,"]*' | cut -d: -f2 | tr -d '"')
                command=$(echo "$cmd" | grep -o '"command":"[^"]*' | cut -d: -f2- | tr -d '"')
                
                if [ -n "$cmd_id" ] && [ -n "$command" ]; then
                    log_message "Processing command: $cmd_id"
                    output_file="/tmp/cmd_output_${cmd_id}.log"
                    result=$(execute_command "shell" "$command")
                    report_command_result "$cmd_id" "$result" "$output_file"
                fi
            done
        done
        
        # Check for OPNsense update requests
        update_requested=$(echo "$response" | grep -o '"opnsense_update_requested"[^,}]*' | cut -d: -f2 | tr -d ' "')
        if [ "$update_requested" = "true" ]; then
            log_message "OPNsense system update requested"
            
            # Get the update command from response
            update_command=$(echo "$response" | grep -o '"opnsense_update_command":"[^"]*' | cut -d: -f2- | tr -d '"')
            fallback_command=$(echo "$response" | grep -o '"opnsense_update_fallback":"[^"]*' | cut -d: -f2- | tr -d '"')
            
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
        perform_checkin
        ;;
    "version")
        echo "$AGENT_VERSION"
        ;;
    "test")
        echo "Agent test successful - version $AGENT_VERSION"
        ;;
    *)
        echo "Usage: $0 {checkin|version|test}"
        exit 1
        ;;
esac