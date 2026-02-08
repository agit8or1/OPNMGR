#!/bin/sh

# OPNsense Management Agent v3.4.0
# Enhanced with WAN Interface Auto-Detection and Monitoring

MANAGEMENT_SERVER="https://opn.agit8or.net"
AGENT_VERSION="3.4.0"
FIREWALL_ID_FILE="/tmp/opnsense_firewall_id"
LOG_FILE="/var/log/opnsense_agent.log"
SSH_KEY_FILE="/root/.ssh/opnsense_tunnel_key"
TUNNEL_PIDS_FILE="/tmp/opnsense_tunnel_pids"
CONFIG_FILE="/conf/config.xml"

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

# Log message
log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" >> "$LOG_FILE"
}

# Detect WAN interfaces from OPNsense configuration
detect_wan_interfaces() {
    local wan_interfaces=""

    if [ ! -f "$CONFIG_FILE" ]; then
        log_message "WARNING: Config file not found at $CONFIG_FILE"
        echo ""
        return 1
    fi

    # Extract WAN interface(s) from config.xml
    # Look for <wan> section and extract <if> tag
    wan_interfaces=$(grep -A 20 "<wan>" "$CONFIG_FILE" | grep "<if>" | sed 's/<[^>]*>//g' | tr -d ' ')

    # Also check for WAN_DHCP, WAN2, WAN3, etc.
    local additional_wans=$(grep -E "<(wan[0-9]+|wan_dhcp|wan_pppoe)>" "$CONFIG_FILE" | sed 's/<\([^>]*\)>.*/\1/' | tr '\n' ' ')

    if [ -n "$additional_wans" ]; then
        for wan_key in $additional_wans; do
            wan_if=$(grep -A 20 "<${wan_key}>" "$CONFIG_FILE" | grep "<if>" | head -1 | sed 's/<[^>]*>//g' | tr -d ' ')
            if [ -n "$wan_if" ]; then
                if [ -z "$wan_interfaces" ]; then
                    wan_interfaces="$wan_if"
                else
                    wan_interfaces="$wan_interfaces,$wan_if"
                fi
            fi
        done
    fi

    if [ -z "$wan_interfaces" ]; then
        log_message "WARNING: No WAN interfaces detected in configuration"
    else
        log_message "Detected WAN interface(s): $wan_interfaces"
    fi

    echo "$wan_interfaces"
}

# Detect WAN gateway groups
detect_wan_groups() {
    local wan_groups=""

    if [ ! -f "$CONFIG_FILE" ]; then
        echo ""
        return 1
    fi

    # Extract gateway groups from config
    wan_groups=$(grep -A 5 "gateway_group" "$CONFIG_FILE" | grep "<name>" | sed 's/<[^>]*>//g' | tr -d ' ' | tr '\n' ',' | sed 's/,$//')

    if [ -n "$wan_groups" ]; then
        log_message "Detected WAN group(s): $wan_groups"
    fi

    echo "$wan_groups"
}

# Get interface statistics
get_interface_stats() {
    local interface="$1"

    if [ -z "$interface" ]; then
        echo "{}"
        return
    fi

    # Get interface status using netstat
    local stats=$(netstat -ibn | grep "^${interface}" | head -1)

    if [ -z "$stats" ]; then
        echo "{}"
        return
    fi

    # Parse netstat output: Iface Mtu Network Address Ipkts Ierrs Ibytes Opkts Oerrs Obytes
    local ipkts=$(echo "$stats" | awk '{print $5}')
    local ierrs=$(echo "$stats" | awk '{print $6}')
    local ibytes=$(echo "$stats" | awk '{print $7}')
    local opkts=$(echo "$stats" | awk '{print $8}')
    local oerrs=$(echo "$stats" | awk '{print $9}')
    local obytes=$(echo "$stats" | awk '{print $10}')

    # Get interface status (up/down)
    local status="down"
    if ifconfig "$interface" | grep -q "status: active"; then
        status="up"
    elif ifconfig "$interface" | grep -q "status: no carrier"; then
        status="no_carrier"
    fi

    # Get IP address assigned to interface
    local ip_addr=$(ifconfig "$interface" | grep 'inet ' | grep -v '127.0.0.1' | awk '{print $2}' | head -1)

    # Get interface speed/media
    local media=$(ifconfig "$interface" | grep 'media:' | sed 's/.*media: //' | cut -d '(' -f1)

    # Construct JSON
    echo "{\"interface\": \"$interface\", \"status\": \"$status\", \"ip\": \"$ip_addr\", \"media\": \"$media\", \"rx_packets\": $ipkts, \"rx_errors\": $ierrs, \"rx_bytes\": $ibytes, \"tx_packets\": $opkts, \"tx_errors\": $oerrs, \"tx_bytes\": $obytes}"
}

# Monitor all WAN interfaces
monitor_wan_interfaces() {
    local wan_interfaces="$1"
    local all_stats="[]"

    if [ -z "$wan_interfaces" ]; then
        echo "$all_stats"
        return
    fi

    # Split interfaces by comma
    local first=1
    all_stats="["

    echo "$wan_interfaces" | tr ',' '\n' | while read -r interface; do
        if [ -n "$interface" ]; then
            local stats=$(get_interface_stats "$interface")

            if [ "$first" -eq 1 ]; then
                all_stats="${all_stats}${stats}"
                first=0
            else
                all_stats="${all_stats},${stats}"
            fi
        fi
    done

    # Get all interface stats at once using a different approach
    local json_stats=""
    for iface in $(echo "$wan_interfaces" | tr ',' ' '); do
        if [ -n "$iface" ]; then
            local stat=$(get_interface_stats "$iface")
            if [ -z "$json_stats" ]; then
                json_stats="$stat"
            else
                json_stats="${json_stats},${stat}"
            fi
        fi
    done

    echo "[${json_stats}]"
}

# Get network information with WAN interface details
get_network_info() {
    HOSTNAME=$(hostname)
    WAN_IP=$(curl -s -m 5 https://ipinfo.io/ip 2>/dev/null || echo "unknown")
    LAN_IP=$(ifconfig | grep 'inet ' | grep -v '127.0.0.1' | head -1 | awk '{print $2}')
    IPV6_ADDRESS=$(ifconfig | grep 'inet6' | grep -v '::1' | grep -v 'fe80' | head -1 | awk '{print $2}')

    # Detect WAN interfaces and groups
    WAN_INTERFACES=$(detect_wan_interfaces)
    WAN_GROUPS=$(detect_wan_groups)

    # Monitor WAN interface statistics
    WAN_INTERFACE_STATS=$(monitor_wan_interfaces "$WAN_INTERFACES")
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

# Execute command based on type
execute_command() {
    local command_type="$1"
    local command_data="$2"
    local result="unknown"

    log_message "Executing command type: $command_type"

    case "$command_type" in
        "system_update")
            log_message "Starting OPNsense system update: $command_data"
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
            pkg update > /tmp/update_output.log 2>&1
            if [ $? -eq 0 ]; then
                log_message "Repository update completed"
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

    local output=""
    if [ -f "$output_file" ]; then
        output=$(tail -20 "$output_file" 2>/dev/null | base64 -w 0)
    fi

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

    setup_ssh_key

    if [ -f "$TUNNEL_PIDS_FILE" ]; then
        EXISTING_PID=$(grep "^${tunnel_port}:" "$TUNNEL_PIDS_FILE" | cut -d: -f2)
        if [ -n "$EXISTING_PID" ] && kill -0 "$EXISTING_PID" 2>/dev/null; then
            log_message "Tunnel already active on port $tunnel_port (PID: $EXISTING_PID)"
            return 0
        fi
    fi

    ssh -o StrictHostKeyChecking=no \
        -o ServerAliveInterval=15 \
        -o ServerAliveCountMax=3 \
        -o ExitOnForwardFailure=yes \
        -i "$SSH_KEY_FILE" \
        -N -R "${tunnel_port}:localhost:443" \
        tunnel@opn.agit8or.net \
        >> "$LOG_FILE" 2>&1 &

    TUNNEL_PID=$!

    touch "$TUNNEL_PIDS_FILE"
    grep -v "^${tunnel_port}:" "$TUNNEL_PIDS_FILE" > "${TUNNEL_PIDS_FILE}.tmp" 2>/dev/null
    echo "${tunnel_port}:${TUNNEL_PID}:${request_id}" >> "${TUNNEL_PIDS_FILE}.tmp"
    mv "${TUNNEL_PIDS_FILE}.tmp" "$TUNNEL_PIDS_FILE"

    log_message "Tunnel started: PID=$TUNNEL_PID, Port=$tunnel_port"

    sleep 2

    if kill -0 "$TUNNEL_PID" 2>/dev/null; then
        log_message "Tunnel successfully established (PID: $TUNNEL_PID)"

        curl -s -k -X POST "$MANAGEMENT_SERVER/api/update_tunnel_status.php" \
            -H "Content-Type: application/json" \
            -d "{\"request_id\": $request_id, \"status\": \"processing\", \"tunnel_pid\": $TUNNEL_PID}" >/dev/null 2>&1

        return 0
    else
        log_message "ERROR: Tunnel failed to start or died immediately"

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
            grep -v "^${port}:" "$TUNNEL_PIDS_FILE" > "${TUNNEL_PIDS_FILE}.tmp" 2>/dev/null
            mv "${TUNNEL_PIDS_FILE}.tmp" "$TUNNEL_PIDS_FILE"

            if [ -n "$request_id" ]; then
                curl -s -k -X POST "$MANAGEMENT_SERVER/api/update_tunnel_status.php" \
                    -H "Content-Type: application/json" \
                    -d "{\"request_id\": $request_id, \"status\": \"timeout\"}" >/dev/null 2>&1
            fi
        fi
    done < "$TUNNEL_PIDS_FILE"
}

# Process pending proxy requests
process_proxy_requests() {
    local response="$1"

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
    log_message "Starting agent checkin with WAN interface monitoring..."

    cleanup_tunnels

    # Get network info including WAN interface detection
    get_network_info

    OPNSENSE_VERSION=$(get_opnsense_version)

    # Prepare checkin data with WAN interface information
    # Escape special characters in JSON
    WAN_INTERFACES_ESC=$(echo "$WAN_INTERFACES" | sed 's/"/\\"/g')
    WAN_GROUPS_ESC=$(echo "$WAN_GROUPS" | sed 's/"/\\"/g')

    CHECKIN_DATA=$(cat << EOF
{
    "firewall_id": "$FIREWALL_ID",
    "hardware_id": "$HARDWARE_ID",
    "hostname": "$HOSTNAME",
    "agent_version": "$AGENT_VERSION",
    "wan_ip": "$WAN_IP",
    "lan_ip": "$LAN_IP",
    "ipv6_address": "$IPV6_ADDRESS",
    "opnsense_version": $OPNSENSE_VERSION,
    "wan_interfaces": "$WAN_INTERFACES_ESC",
    "wan_groups": "$WAN_GROUPS_ESC",
    "wan_interface_stats": $WAN_INTERFACE_STATS
}
EOF
)

    log_message "Checkin data prepared with WAN interfaces: $WAN_INTERFACES"

    RESPONSE=$(curl -s -k -X POST "$MANAGEMENT_SERVER/agent_checkin.php" \
        -H "Content-Type: application/json" \
        -d "$CHECKIN_DATA" 2>/dev/null)

    if [ $? -eq 0 ] && [ -n "$RESPONSE" ]; then
        log_message "Checkin successful"

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
    if 'queued_commands' in data:
        for cmd in data['queued_commands']:
            cmd_b64 = base64.b64encode(str(cmd['command']).encode()).decode()
            print(f\"{cmd['id']}|shell|{cmd_b64}\")
except:
    pass
" 2>/dev/null)

        if [ -n "$COMMANDS" ]; then
            echo "$COMMANDS" | while IFS='|' read -r cmd_id cmd_type cmd_data_b64; do
                if [ -n "$cmd_id" ] && [ -n "$cmd_type" ] && [ -n "$cmd_data_b64" ]; then
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

        if [ -n "$OPNSENSE_UPDATE" ]; then
            log_message "OPNsense system update requested: $OPNSENSE_UPDATE"

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
        echo "300"
    fi
}

# Main execution
main() {
    touch "$LOG_FILE"

    NEXT_INTERVAL=$(perform_checkin)

    if ! echo "$NEXT_INTERVAL" | grep -q '^[0-9]\+$'; then
        NEXT_INTERVAL=300
    fi

    log_message "Next checkin in $NEXT_INTERVAL seconds (Agent v$AGENT_VERSION with WAN monitoring)"
}

# Run main function
main
