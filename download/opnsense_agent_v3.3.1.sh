#!/bin/sh

# OPNsense Management Agent v3.3.0
# Enhanced with SSH Reverse Tunnel Support for Remote Access

MANAGEMENT_SERVER="https://opn.agit8or.net"
AGENT_VERSION="3.3.1"
FIREWALL_ID_FILE="/tmp/opnsense_firewall_id"
LOG_FILE="/var/log/opnsense_agent.log"
SSH_KEY_FILE="/root/.ssh/opnsense_tunnel_key"
TUNNEL_PIDS_FILE="/tmp/opnsense_tunnel_pids"

# Get or create firewall ID
if [ ! -f "$FIREWALL_ID_FILE" ]; then
    # Generate a unique hardware ID based on system info
    HARDWARE_ID=$(ifconfig | grep ether | head -1 | awk '{print $2}' | tr -d ':')
    if [ -z "$HARDWARE_ID" ]; then
        HARDWARE_ID=$(hostname | md5)
    fi
    FIREWALL_ID="$HARDWARE_ID"
    echo "$FIREWALL_ID" > "$FIREWALL_ID_FILE"
else
    FIREWALL_ID=$(cat "$FIREWALL_ID_FILE")
fi

# Hardware ID for registration
HARDWARE_ID=$(ifconfig | grep ether | head -1 | awk '{print $2}' | tr -d ':')

# Get network information
get_network_info() {
    HOSTNAME=$(hostname)
    WAN_IP=$(curl -s -m 5 https://ipinfo.io/ip 2>/dev/null || echo "unknown")
    LAN_IP=$(ifconfig | grep 'inet ' | grep -v '127.0.0.1' | head -1 | awk '{print $2}')
    IPV6_ADDRESS=$(ifconfig | grep 'inet6' | grep -v '::1' | grep -v 'fe80' | head -1 | awk '{print $2}')
    
    # Get system uptime - convert to "X days, Y minutes" format
    # Extract uptime string, then parse it with sed and awk
    UPTIME_RAW=$(uptime | sed 's/.*up //' | sed 's/,.*//' | xargs)
    
    # Parse the uptime format more reliably
    if echo "$UPTIME_RAW" | grep -q "day"; then
        DAYS=$(echo "$UPTIME_RAW" | awk '{print $1}')
        TIME=$(echo "$UPTIME_RAW" | awk '{print $3}')
    else
        DAYS=""
        TIME="$UPTIME_RAW"
    fi
    
    # Convert time to minutes
    if echo "$TIME" | grep -q ":"; then
        HOURS=$(echo "$TIME" | cut -d: -f1)
        MINS=$(echo "$TIME" | cut -d: -f2 | sed 's/,.*//')
        TOTAL_MINS=$((HOURS * 60 + MINS))
    else
        TOTAL_MINS=$(echo "$TIME" | sed 's/[^0-9]//g')
    fi
    
    # Build uptime string
    if [ -n "$DAYS" ]; then
        UPTIME="$DAYS days, $TOTAL_MINS minutes"
    else
        UPTIME="$TOTAL_MINS minutes"
    fi
}

# Get OPNsense version information
get_opnsense_version() {
    VERSION_FILE="/usr/local/opnsense/version/core"
    VERSION_NAME_FILE="/usr/local/opnsense/version/core.name"
    VERSION_SERIES_FILE="/usr/local/opnsense/version/core.series"
    
    VERSION="unknown"
    VERSION_NAME="unknown"
    VERSION_SERIES="unknown"
    
    if [ -f "$VERSION_FILE" ]; then
        VERSION=$(cat "$VERSION_FILE" 2>/dev/null | head -1 | tr -d '\n')
    fi
    
    if [ -f "$VERSION_NAME_FILE" ]; then
        VERSION_NAME=$(cat "$VERSION_NAME_FILE" 2>/dev/null | head -1 | tr -d '\n')
    fi
    
    if [ -f "$VERSION_SERIES_FILE" ]; then
        VERSION_SERIES=$(cat "$VERSION_SERIES_FILE" 2>/dev/null | head -1 | tr -d '\n')
    fi
    
    # Return as JSON-like string
    echo "{\"version\": \"$VERSION\", \"name\": \"$VERSION_NAME\", \"series\": \"$VERSION_SERIES\"}"
}

# Log message
log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" >> "$LOG_FILE"
}

# Execute command based on type
execute_command() {
    local command_type="$1"
    local command_data="$2"
    local result="unknown"
    
    log_message "Executing command type: $command_type"
    
    case "$command_type" in
        "system_update")
            log_message "Starting OPNsense system update: $command_data"
            # Perform system update automatically
            /usr/local/sbin/opnsense-update -bkf > /tmp/update_output.log 2>&1
            if [ $? -eq 0 ]; then
                result="success"
                log_message "System update completed successfully"
            else
                result="failed"
                log_message "System update failed"
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

# Setup SSH key for tunnels
setup_ssh_key() {
    if [ ! -f "$SSH_KEY_FILE" ]; then
        log_message "Generating SSH key for reverse tunnels"
        ssh-keygen -t ed25519 -f "$SSH_KEY_FILE" -N "" -C "opnsense-tunnel-fw${FIREWALL_ID}" >/dev/null 2>&1
        if [ $? -eq 0 ]; then
            log_message "SSH key generated successfully"
            # Send public key to server for authorization
            PUB_KEY=$(cat "${SSH_KEY_FILE}.pub" 2>/dev/null)
            if [ -n "$PUB_KEY" ]; then
                curl -s -k -X POST "$MANAGEMENT_SERVER/api/register_tunnel_key.php" \
                    -H "Content-Type: application/json" \
                    -d "{\"firewall_id\": \"$FIREWALL_ID\", \"public_key\": \"$PUB_KEY\"}" >/dev/null 2>&1
                log_message "Public key sent to server"
            fi
        else
            log_message "ERROR: Failed to generate SSH key"
        fi
    fi
}

# Create reverse SSH tunnel
create_tunnel() {
    local request_id="$1"
    local tunnel_port="$2"
    
    log_message "Creating reverse tunnel: request_id=$request_id, tunnel_port=$tunnel_port"
    
    # Ensure SSH key exists
    setup_ssh_key
    
    # Check if tunnel already exists for this port
    if [ -f "$TUNNEL_PIDS_FILE" ]; then
        EXISTING_PID=$(grep "^${tunnel_port}:" "$TUNNEL_PIDS_FILE" | cut -d: -f2)
        if [ -n "$EXISTING_PID" ] && kill -0 "$EXISTING_PID" 2>/dev/null; then
            log_message "Tunnel already active on port $tunnel_port (PID: $EXISTING_PID)"
            return 0
        fi
    fi
    
    # Create reverse tunnel: remote port -> local OPNsense web interface (port 443)
    # -R ${tunnel_port}:localhost:443 means: 
    #   - Listen on port ${tunnel_port} on the remote server
    #   - Forward connections to localhost:443 (OPNsense web interface)
    
    ssh -o StrictHostKeyChecking=no \
        -o ServerAliveInterval=15 \
        -o ServerAliveCountMax=3 \
        -o ExitOnForwardFailure=yes \
        -i "$SSH_KEY_FILE" \
        -N -R "${tunnel_port}:localhost:443" \
        tunnel@opn.agit8or.net \
        >> "$LOG_FILE" 2>&1 &
    
    TUNNEL_PID=$!
    
    # Save tunnel PID
    touch "$TUNNEL_PIDS_FILE"
    # Remove old entry for this port
    grep -v "^${tunnel_port}:" "$TUNNEL_PIDS_FILE" > "${TUNNEL_PIDS_FILE}.tmp" 2>/dev/null
    # Add new entry
    echo "${tunnel_port}:${TUNNEL_PID}:${request_id}" >> "${TUNNEL_PIDS_FILE}.tmp"
    mv "${TUNNEL_PIDS_FILE}.tmp" "$TUNNEL_PIDS_FILE"
    
    log_message "Tunnel started: PID=$TUNNEL_PID, Port=$tunnel_port"
    
    # Wait a moment for tunnel to establish
    sleep 2
    
    # Check if tunnel is still running
    if kill -0 "$TUNNEL_PID" 2>/dev/null; then
        log_message "Tunnel successfully established (PID: $TUNNEL_PID)"
        
        # Update request status to 'processing'
        curl -s -k -X POST "$MANAGEMENT_SERVER/api/update_tunnel_status.php" \
            -H "Content-Type: application/json" \
            -d "{\"request_id\": $request_id, \"status\": \"processing\", \"tunnel_pid\": $TUNNEL_PID}" >/dev/null 2>&1
        
        return 0
    else
        log_message "ERROR: Tunnel failed to start or died immediately"
        
        # Update request status to 'failed'
        curl -s -k -X POST "$MANAGEMENT_SERVER/api/update_tunnel_status.php" \
            -H "Content-Type: application/json" \
            -d "{\"request_id\": $request_id, \"status\": \"failed\"}" >/dev/null 2>&1
        
        return 1
    fi
}

# Clean up dead tunnels
cleanup_tunnels() {
    if [ ! -f "$TUNNEL_PIDS_FILE" ]; then
        return
    fi
    
    while IFS=: read -r port pid request_id; do
        if [ -n "$pid" ] && ! kill -0 "$pid" 2>/dev/null; then
            log_message "Cleaning up dead tunnel: Port=$port, PID=$pid"
            # Remove from PID file
            grep -v "^${port}:" "$TUNNEL_PIDS_FILE" > "${TUNNEL_PIDS_FILE}.tmp" 2>/dev/null
            mv "${TUNNEL_PIDS_FILE}.tmp" "$TUNNEL_PIDS_FILE"
            
            # Update request status to 'timeout' or 'completed'
            if [ -n "$request_id" ]; then
                curl -s -k -X POST "$MANAGEMENT_SERVER/api/update_tunnel_status.php" \
                    -H "Content-Type: application/json" \
                    -d "{\"request_id\": $request_id, \"status\": \"timeout\"}" >/dev/null 2>&1
            fi
        fi
    done < "$TUNNEL_PIDS_FILE"
}

# Process pending proxy requests (NEW in v3.3.0)
process_proxy_requests() {
    local response="$1"
    
    # Extract pending requests using Python
    PENDING_REQUESTS=$(echo "$RESPONSE" | python3 -c "
import json, sys
try:
    data = json.load(sys.stdin)
    if 'pending_requests' in data:
        for req in data['pending_requests']:
            print(f\"{req['id']}:{req.get('tunnel_port', 0)}\")
except Exception as e:
    pass
" 2>/dev/null)
    
    if [ -n "$PENDING_REQUESTS" ]; then
        log_message "Found $(echo \"$PENDING_REQUESTS\" | wc -l) pending proxy request(s)"
        
        # Process each request
        echo "$PENDING_REQUESTS" | while IFS=: read -r request_id tunnel_port; do
            if [ -n "$request_id" ] && [ -n "$tunnel_port" ] && [ "$tunnel_port" != "0" ]; then
                log_message "Processing proxy request: ID=$request_id, Port=$tunnel_port"
                create_tunnel "$request_id" "$tunnel_port"
            else
                log_message "Skipping invalid proxy request: ID=$request_id, Port=$tunnel_port"
            fi
        done
    fi
}

# Main checkin function
perform_checkin() {
    log_message "Starting agent checkin..."
    
    # Clean up any dead tunnels first
    cleanup_tunnels
    
    # Get network info
    get_network_info
    
    # Get OPNsense version data
    OPNSENSE_VERSION=$(get_opnsense_version)
    
    # Prepare checkin data
    CHECKIN_DATA=$(cat << EOF
{
    "firewall_id": "$FIREWALL_ID",
    "hardware_id": "$HARDWARE_ID",
    "hostname": "$HOSTNAME",
    "agent_version": "$AGENT_VERSION",
    "wan_ip": "$WAN_IP",
    "lan_ip": "$LAN_IP",
    "ipv6_address": "$IPV6_ADDRESS",
    "uptime": "$UPTIME",
    "opnsense_version": $OPNSENSE_VERSION
}
EOF
)
    
    # Perform checkin
    RESPONSE=$(curl -s -k -X POST "$MANAGEMENT_SERVER/agent_checkin.php" \
        -H "Content-Type: application/json" \
        -d "$CHECKIN_DATA" 2>/dev/null)
    
    if [ $? -eq 0 ] && [ -n "$RESPONSE" ]; then
        log_message "Checkin successful"
        
        # Process pending proxy/tunnel requests (NEW in v3.3.0)
        process_proxy_requests "$RESPONSE"
        
        # Check for pending commands
        COMMANDS=$(echo "$RESPONSE" | python3 -c "
import json, sys, base64
try:
    data = json.load(sys.stdin)
    if 'commands' in data:
        for cmd in data['commands']:
            cmd_data_b64 = base64.b64encode(str(cmd['command_data']).encode()).decode()
            print(f\"{cmd['command_id']}|{cmd['command_type']}|{cmd_data_b64}\")
    # Also check for queued_commands (newer format)
    if 'queued_commands' in data:
        for cmd in data['queued_commands']:
            cmd_b64 = base64.b64encode(str(cmd['command']).encode()).decode()
            print(f\"{cmd['id']}|shell|{cmd_b64}\")
except:
    pass
" 2>/dev/null)
        
        # Execute any pending commands
        if [ -n "$COMMANDS" ]; then
            # Parse and execute commands
            echo "$COMMANDS" | while IFS='|' read -r cmd_id cmd_type cmd_data_b64; do
                if [ -n "$cmd_id" ] && [ -n "$cmd_type" ] && [ -n "$cmd_data_b64" ]; then
                    # Decode base64 command
                    cmd_data=$(echo "$cmd_data_b64" | base64 -d 2>/dev/null)
                    if [ -n "$cmd_data" ]; then
                        log_message "Processing command: $cmd_id ($cmd_type)"
                        result=$(execute_command "$cmd_type" "$cmd_data")
                        log_message "Command $cmd_id completed with result: $result"
                        report_command_result "$cmd_id" "$result"
                        log_message "Result reported for command $cmd_id"
                    else
                        log_message "Failed to decode command $cmd_id"
                    fi
                fi
            done
        fi
        
        # Check for OPNsense system update request
        OPNSENSE_UPDATE=$(echo "$RESPONSE" | python3 -c "
import json, sys
try:
    data = json.load(sys.stdin)
    if data.get('opnsense_update_requested', False):
        print(data.get('opnsense_update_command', 'pkg update && pkg upgrade -y'))
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

# Main execution
main() {
    # Create log file if it doesn't exist
    touch "$LOG_FILE"
    
    # Perform checkin and get interval
    NEXT_INTERVAL=$(perform_checkin)
    
    # Validate interval
    if ! echo "$NEXT_INTERVAL" | grep -q '^[0-9]\+$'; then
        NEXT_INTERVAL=300
    fi
    
    log_message "Next checkin in $NEXT_INTERVAL seconds (Agent v$AGENT_VERSION)"
}

# Run main function
main
