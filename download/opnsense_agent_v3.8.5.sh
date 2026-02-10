#!/bin/sh

# OPNsense Management Agent v3.3.0
# Enhanced with SSH Reverse Tunnel Support for Remote Access

MANAGEMENT_SERVER="https://opn.agit8or.net"
AGENT_VERSION="3.8.5"
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
    UPTIME_RAW=$(uptime | sed 's/.*up //' | sed 's/,[[:space:]]*[0-9]* user.*//' | sed 's/,[[:space:]]*load.*//' | xargs)

    if echo "$UPTIME_RAW" | grep -q "day"; then
        DAYS=$(echo "$UPTIME_RAW" | grep -o '[0-9]* day' | awk '{print $1}')
        TIME=$(echo "$UPTIME_RAW" | sed 's/.*days*,* *//' | awk '{print $1}')
    else
        DAYS=""
        TIME=$(echo "$UPTIME_RAW" | awk '{print $1}')
    fi

    if echo "$TIME" | grep -q ":"; then
        HOURS=$(echo "$TIME" | cut -d: -f1)
        MINS=$(echo "$TIME" | cut -d: -f2 | sed 's/[^0-9]//g')
        TOTAL_MINS=$((HOURS * 60 + MINS))
    else
        TOTAL_MINS=$(echo "$TIME" | sed 's/[^0-9]//g')
    fi

    if [ -n "$DAYS" ]; then
        UPTIME="$DAYS days, $TOTAL_MINS minutes"
    else
        UPTIME="$TOTAL_MINS minutes"
    fi

    # WAN interface and network config
    WAN_IFACE=$(netstat -rn 2>/dev/null | grep '^default' | head -1 | awk '{print $NF}')
    [ -z "$WAN_IFACE" ] && WAN_IFACE="em0"

    WAN_NETMASK=$(ifconfig "$WAN_IFACE" 2>/dev/null | grep 'inet ' | head -1 | awk '{print $4}')
    # Convert hex netmask to dotted decimal
    if echo "$WAN_NETMASK" | grep -q '^0x'; then
        WAN_NETMASK=$(printf "%d.%d.%d.%d" \
            $(( $(printf '%d' "$WAN_NETMASK") >> 24 & 255 )) \
            $(( $(printf '%d' "$WAN_NETMASK") >> 16 & 255 )) \
            $(( $(printf '%d' "$WAN_NETMASK") >> 8 & 255 )) \
            $(( $(printf '%d' "$WAN_NETMASK") & 255 )))
    fi

    WAN_GATEWAY=$(netstat -rn 2>/dev/null | grep '^default' | head -1 | awk '{print $2}')
    WAN_DNS_PRIMARY=$(awk '/^nameserver/{print $2; exit}' /etc/resolv.conf 2>/dev/null)
    WAN_DNS_SECONDARY=$(awk '/^nameserver/{n++; if(n==2) print $2}' /etc/resolv.conf 2>/dev/null)

    # LAN network config
    LAN_IP_PRIV=$(ifconfig | grep 'inet ' | awk '{print $2}' | grep -E '^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)' | head -1)
    LAN_NETMASK=""
    LAN_NETWORK=""
    if [ -n "$LAN_IP_PRIV" ]; then
        LAN_IFACE=$(ifconfig | grep -B5 "inet $LAN_IP_PRIV " | grep '^[a-z]' | awk -F: '{print $1}' | tail -1)
        if [ -n "$LAN_IFACE" ]; then
            LAN_NETMASK=$(ifconfig "$LAN_IFACE" 2>/dev/null | grep "inet $LAN_IP_PRIV " | awk '{print $4}')
            if echo "$LAN_NETMASK" | grep -q '^0x'; then
                LAN_NETMASK=$(printf "%d.%d.%d.%d" \
                    $(( $(printf '%d' "$LAN_NETMASK") >> 24 & 255 )) \
                    $(( $(printf '%d' "$LAN_NETMASK") >> 16 & 255 )) \
                    $(( $(printf '%d' "$LAN_NETMASK") >> 8 & 255 )) \
                    $(( $(printf '%d' "$LAN_NETMASK") & 255 )))
            fi
        fi
    fi

    # All active interfaces
    WAN_INTERFACES=""
    for iface in $(ifconfig -l 2>/dev/null); do
        case "$iface" in pflog*|pfsync*|enc*) continue ;; esac
        if ifconfig "$iface" 2>/dev/null | grep -q 'inet \|flags.*UP'; then
            [ -n "$WAN_INTERFACES" ] && WAN_INTERFACES="${WAN_INTERFACES},"
            WAN_INTERFACES="${WAN_INTERFACES}${iface}"
        fi
    done

    # Check for available OPNsense updates
    UPDATES_AVAILABLE=0
    AVAILABLE_VERSION=""
    if command -v opnsense-update >/dev/null 2>&1; then
        update_output=$(opnsense-update -c 2>&1)
        if [ $? -eq 0 ] && [ -n "$update_output" ]; then
            UPDATES_AVAILABLE=1
            AVAILABLE_VERSION=$(echo "$update_output" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+[_0-9]*' | tail -1)
        fi
    fi
    # Fallback to pkg check
    if [ "$UPDATES_AVAILABLE" -eq 0 ] && command -v pkg >/dev/null 2>&1; then
        pkg_output=$(pkg upgrade -n 2>&1)
        if echo "$pkg_output" | grep -q "opnsense.*->"; then
            UPDATES_AVAILABLE=1
            AVAILABLE_VERSION=$(echo "$pkg_output" | grep "opnsense:" | sed -n 's/.*-> \([0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*[_0-9]*\).*/\1/p' | head -1)
        fi
    fi

    # Get per-interface stats as JSON
    WAN_IFACE_STATS="["
    first=1
    for iface in $(ifconfig -l 2>/dev/null); do
        case "$iface" in pflog*|pfsync*|enc*|lo*) continue ;; esac
        iface_data=$(ifconfig "$iface" 2>/dev/null)
        [ -z "$iface_data" ] && continue

        status="down"
        echo "$iface_data" | grep -q 'status: active' && status="active"
        echo "$iface_data" | grep -q 'status: associated' && status="active"
        if [ "$status" = "down" ]; then
            echo "$iface_data" | grep -q 'flags=.*UP' && ! echo "$iface_data" | grep -q 'status: no carrier' && status="active"
        fi

        ip=$(echo "$iface_data" | grep 'inet ' | head -1 | awk '{print $2}')
        mask=$(echo "$iface_data" | grep 'inet ' | head -1 | awk '{print $4}')

        gw=""
        [ "$iface" = "$WAN_IFACE" ] && gw="$WAN_GATEWAY"

        link_stats=$(netstat -ibn 2>/dev/null | grep "^${iface}" | grep '<Link' | head -1)
        rx_pkts=0; rx_err=0; rx_b=0; tx_pkts=0; tx_err=0; tx_b=0
        if [ -n "$link_stats" ]; then
            rx_pkts=$(echo "$link_stats" | awk '{print $5}')
            rx_err=$(echo "$link_stats" | awk '{print $6}')
            rx_b=$(echo "$link_stats" | awk '{print $8}')
            tx_pkts=$(echo "$link_stats" | awk '{print $9}')
            tx_err=$(echo "$link_stats" | awk '{print $10}')
            tx_b=$(echo "$link_stats" | awk '{print $11}')
        fi

        [ $first -eq 0 ] && WAN_IFACE_STATS="${WAN_IFACE_STATS},"
        first=0
        WAN_IFACE_STATS="${WAN_IFACE_STATS}{\"interface\":\"$iface\",\"status\":\"$status\",\"ip\":\"${ip:-}\",\"netmask\":\"${mask:-}\",\"gateway\":\"${gw:-}\",\"media\":\"none\",\"rx_packets\":${rx_pkts:-0},\"rx_errors\":${rx_err:-0},\"rx_bytes\":${rx_b:-0},\"tx_packets\":${tx_pkts:-0},\"tx_errors\":${tx_err:-0},\"tx_bytes\":${tx_b:-0}}"
    done
    WAN_IFACE_STATS="${WAN_IFACE_STATS}]"
}

# Get OPNsense version information
get_opnsense_version() {
    VERSION="unknown"
    VERSION_NAME="unknown"
    VERSION_SERIES="unknown"
    
    # Method 1: Try opnsense-version command (most reliable)
    if command -v opnsense-version >/dev/null 2>&1; then
        FULL_VERSION=$(opnsense-version 2>/dev/null)
        if [ -n "$FULL_VERSION" ]; then
            # Parse output like "OPNsense 24.7.4-amd64"
            VERSION=$(echo "$FULL_VERSION" | awk '{print $2}' | cut -d'-' -f1)
            VERSION_SERIES=$(echo "$VERSION" | cut -d'.' -f1-2)
            VERSION_NAME=$(echo "$FULL_VERSION" | awk '{print $1}')
        fi
    fi
    
    # Method 2: Try version files if command didn't work
    if [ "$VERSION" = "unknown" ]; then
        VERSION_FILE="/usr/local/opnsense/version/core"
        VERSION_NAME_FILE="/usr/local/opnsense/version/core.name"
        VERSION_SERIES_FILE="/usr/local/opnsense/version/core.series"
        
        if [ -f "$VERSION_FILE" ]; then
            VERSION=$(cat "$VERSION_FILE" 2>/dev/null | head -1 | tr -d '
')
        fi
        
        if [ -f "$VERSION_NAME_FILE" ]; then
            VERSION_NAME=$(cat "$VERSION_NAME_FILE" 2>/dev/null | head -1 | tr -d '
')
        fi
        
        if [ -f "$VERSION_SERIES_FILE" ]; then
            VERSION_SERIES=$(cat "$VERSION_SERIES_FILE" 2>/dev/null | head -1 | tr -d '
')
        fi
    fi
    
    # Method 3: Try pkg info as last resort
    if [ "$VERSION" = "unknown" ] && command -v pkg >/dev/null 2>&1; then
        PKG_VERSION=$(pkg info opnsense 2>/dev/null | grep -i "^Version" | awk '{print $3}')
        if [ -n "$PKG_VERSION" ]; then
            VERSION="$PKG_VERSION"
            VERSION_NAME="OPNsense"
        fi
    fi
    
    # Escape special characters for JSON (remove quotes, braces, backslashes)
    VERSION=$(echo "$VERSION" | sed 's/["{}\\]//g')
    VERSION_NAME=$(echo "$VERSION_NAME" | sed 's/["{}\\]//g')
    VERSION_SERIES=$(echo "$VERSION_SERIES" | sed 's/["{}\\]//g')
    
    # Return as JSON-like string
    echo "{\"version\": \"$VERSION\", \"name\": \"$VERSION_NAME\", \"series\": \"$VERSION_SERIES\"}"
}

# Log message
log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" >> "$LOG_FILE"
}

# Execute command based on type
execute_command() {
    cmd_type="$1"
    cmd_data="$2"
    tmpout="/tmp/cmd_out_$$.txt"
    case "$cmd_type" in
        "system_update")
            /usr/local/sbin/opnsense-update -bkf > "$tmpout" 2>&1
            result=$?; [ $result -eq 0 ] && result="success" || result="failed" ;;
        "update_agent")
            pkg update > "$tmpout" 2>&1 && pkg upgrade -y >> "$tmpout" 2>&1
            result=$?; [ $result -eq 0 ] && result="success" || result="partial" ;;
        "shell")
            eval "$cmd_data" > "$tmpout" 2>&1
            result=$?; [ $result -eq 0 ] && result="success" || result="failed" ;;
        "api_test")
            curl -s -k https://localhost/api/core/firmware/status > "$tmpout" 2>&1
            result=$?; [ $result -eq 0 ] && result="success" || result="failed" ;;
        *)
            result="unknown"; echo "Unknown: $cmd_type" > "$tmpout" ;;
    esac
    output=$(cat "$tmpout" 2>/dev/null | head -c 4000); rm -f "$tmpout"
    echo "${result}|||${output}"
}

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
    "opnsense_version": $OPNSENSE_VERSION,
    "wan_netmask": "$WAN_NETMASK",
    "wan_gateway": "$WAN_GATEWAY",
    "wan_dns_primary": "$WAN_DNS_PRIMARY",
    "wan_dns_secondary": "$WAN_DNS_SECONDARY",
    "lan_netmask": "$LAN_NETMASK",
    "lan_network": "$LAN_NETWORK",
    "wan_interfaces": "$WAN_INTERFACES",
    "updates_available": $UPDATES_AVAILABLE,
    "available_version": "$AVAILABLE_VERSION",
    "wan_interface_stats": $WAN_IFACE_STATS
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
        
        
        # Check for wake signal (NEW in v3.3.2)
        WAKE_SIGNAL=$(echo "$RESPONSE" | python3 -c "import json, sys; data = json.load(sys.stdin); print(data.get('wake_immediately', 'false'))" 2>/dev/null)
        if [ "$WAKE_SIGNAL" = "True" ] || [ "$WAKE_SIGNAL" = "true" ]; then
            log_message "Wake signal received - will recheck in 5 seconds"
            FORCE_IMMEDIATE_RECHECK=1
        fi
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

    # NEW in v3.3.2: If wake signal received, check in again after brief delay
    if [ "${FORCE_IMMEDIATE_RECHECK:-0}" = "1" ]; then
        log_message "Wake signal detected - rechecking after 5 seconds"
        sleep 5
        FORCE_IMMEDIATE_RECHECK=0  # Prevent infinite loop
        perform_checkin > /dev/null
        log_message "Immediate recheck completed"
    fi
}

# Run main function
main

