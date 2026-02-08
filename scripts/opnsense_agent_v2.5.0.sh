#!/bin/sh

# OPNsense Management Agent v2.5.0
# With on-demand reverse proxy support

# Configuration
MANAGEMENT_SERVER="https://opn.agit8or.net"
AGENT_VERSION="2.5.0"
FIREWALL_ID="21"  # This firewall's ID in the management system
LOG_FILE="/var/log/opnsense_agent.log"
LOCKFILE="/var/run/opnsense_agent.lock"
PROXY_LOCKFILE="/var/run/opnsense_proxy.lock"
SSH_KEY_FILE="/root/.ssh/opnsense_proxy_key"

# Hardware ID generation (persistent)
HARDWARE_ID_FILE="/tmp/opnsense_hardware_id"
if [ ! -f "$HARDWARE_ID_FILE" ]; then
    openssl rand -hex 16 > "$HARDWARE_ID_FILE"
fi
HARDWARE_ID=$(cat "$HARDWARE_ID_FILE")

# Lockfile management (prevent multiple instances)
acquire_lock() {
    local lockfile="$1"
    local max_age=300  # 5 minutes
    
    if [ -f "$lockfile" ]; then
        lock_age=$(($(date +%s) - $(stat -f %m "$lockfile" 2>/dev/null || stat -c %Y "$lockfile" 2>/dev/null || echo 0)))
        if [ "$lock_age" -lt "$max_age" ]; then
            return 1  # Lock is active
        else
            rm -f "$lockfile"  # Stale lock
        fi
    fi
    
    echo $$ > "$lockfile"
    return 0
}

release_lock() {
    rm -f "$1"
}

# Get system information
get_system_info() {
    HOSTNAME=$(hostname)
    WAN_IP=$(curl -s -m 5 https://ipinfo.io/ip 2>/dev/null || echo "unknown")
    LAN_IP=$(ifconfig | grep 'inet ' | grep -v '127.0.0.1' | head -1 | awk '{print $2}')
    IPV6_ADDRESS=$(ifconfig | grep 'inet6' | grep -v '::1' | grep -v 'fe80' | head -1 | awk '{print $2}')
}

# Get OPNsense version
get_opnsense_version() {
    if [ -f "/usr/local/opnsense/version/core" ]; then
        cat /usr/local/opnsense/version/core 2>/dev/null | head -1
    else
        echo "unknown"
    fi
}

# Log function
log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" >> "$LOG_FILE"
}

# Check-in function
perform_checkin() {
    get_system_info
    VERSION=$(get_opnsense_version)
    
    log_message "Performing checkin to $MANAGEMENT_SERVER"
    
    RESPONSE=$(curl -s -k -X POST "$MANAGEMENT_SERVER/agent_checkin.php" \
        -H "Content-Type: application/json" \
        -d "{
            \"agent_type\": \"primary\",
            \"firewall_id\": $FIREWALL_ID,
            \"hardware_id\": \"$HARDWARE_ID\",
            \"agent_version\": \"$AGENT_VERSION\",
            \"wan_ip\": \"$WAN_IP\",
            \"lan_ip\": \"$LAN_IP\",
            \"ipv6_address\": \"$IPV6_ADDRESS\",
            \"opnsense_version\": \"$VERSION\"
        }" 2>&1)
    
    if [ $? -eq 0 ] && [ -n "$RESPONSE" ]; then
        log_message "Checkin successful"
        
        # Extract checkin interval
        INTERVAL=$(echo "$RESPONSE" | grep -o '"checkin_interval":[0-9]*' | cut -d: -f2)
        [ -z "$INTERVAL" ] && INTERVAL=120
        
        echo "$INTERVAL"
    else
        log_message "Checkin failed"
        echo "120"
    fi
}

# Check for pending proxy requests
check_proxy_requests() {
    log_message "Checking for proxy requests"
    
    PROXY_REQUEST=$(curl -s -k -X POST "$MANAGEMENT_SERVER/agent_proxy_check.php" \
        -H "Content-Type: application/json" \
        -d "{\"firewall_id\": $FIREWALL_ID, \"agent_version\": \"$AGENT_VERSION\"}" 2>&1)
    
    if [ $? -eq 0 ] && [ -n "$PROXY_REQUEST" ]; then
        # Check if there's an active request
        HAS_REQUEST=$(echo "$PROXY_REQUEST" | grep -o '"has_request":true')
        
        if [ -n "$HAS_REQUEST" ]; then
            REQUEST_ID=$(echo "$PROXY_REQUEST" | grep -o '"request_id":[0-9]*' | cut -d: -f2)
            TUNNEL_PORT=$(echo "$PROXY_REQUEST" | grep -o '"tunnel_port":[0-9]*' | cut -d: -f2)
            
            log_message "Found proxy request: ID=$REQUEST_ID, Port=$TUNNEL_PORT"
            echo "$REQUEST_ID:$TUNNEL_PORT"
        fi
    fi
}

# Create reverse SSH tunnel
create_reverse_tunnel() {
    local request_id="$1"
    local tunnel_port="$2"
    
    log_message "Creating reverse tunnel: request_id=$request_id, port=$tunnel_port"
    
    # Generate SSH key if it doesn't exist
    if [ ! -f "$SSH_KEY_FILE" ]; then
        log_message "Generating SSH key for proxy tunnels"
        ssh-keygen -t ed25519 -f "$SSH_KEY_FILE" -N "" -C "opnsense-proxy-$FIREWALL_ID"
    fi
    
    # Create reverse tunnel: Remote port on management server forwards to local 443
    # Management server's sshd on port 22 accepts connections
    # -R ${tunnel_port}:localhost:443 = Forward remote port to local OPNsense web interface
    
    SSH_CMD="ssh -o StrictHostKeyChecking=no -o ServerAliveInterval=30 -o ServerAliveCountMax=3 \
        -i $SSH_KEY_FILE -N -R ${tunnel_port}:localhost:443 \
        proxy@opn.agit8or.net"
    
    log_message "Starting SSH tunnel: $SSH_CMD"
    
    # Start tunnel in background
    $SSH_CMD &
    TUNNEL_PID=$!
    
    echo "$TUNNEL_PID" > "$PROXY_LOCKFILE"
    log_message "Tunnel started with PID $TUNNEL_PID"
    
    # Update request status to 'processing'
    curl -s -k -X POST "$MANAGEMENT_SERVER/agent_proxy_update.php" \
        -H "Content-Type: application/json" \
        -d "{\"request_id\": $request_id, \"status\": \"processing\", \"tunnel_pid\": $TUNNEL_PID}"
    
    return 0
}

# Stop reverse tunnel
stop_reverse_tunnel() {
    if [ -f "$PROXY_LOCKFILE" ]; then
        TUNNEL_PID=$(cat "$PROXY_LOCKFILE")
        if [ -n "$TUNNEL_PID" ]; then
            log_message "Stopping tunnel PID $TUNNEL_PID"
            kill "$TUNNEL_PID" 2>/dev/null
        fi
        rm -f "$PROXY_LOCKFILE"
    fi
}

# Main loop
main_loop() {
    log_message "Agent v$AGENT_VERSION starting (daemon mode)"
    
    CHECKIN_COUNTER=0
    PROXY_CHECK_COUNTER=0
    
    while true; do
        # Perform checkin every 120 seconds (2 minutes)
        if [ "$CHECKIN_COUNTER" -ge 120 ]; then
            INTERVAL=$(perform_checkin)
            CHECKIN_COUNTER=0
        fi
        
        # Check for proxy requests every 30 seconds
        if [ "$PROXY_CHECK_COUNTER" -ge 30 ]; then
            PROXY_INFO=$(check_proxy_requests)
            
            if [ -n "$PROXY_INFO" ]; then
                REQUEST_ID=$(echo "$PROXY_INFO" | cut -d: -f1)
                TUNNEL_PORT=$(echo "$PROXY_INFO" | cut -d: -f2)
                
                # Check if tunnel is already running
                if [ ! -f "$PROXY_LOCKFILE" ]; then
                    create_reverse_tunnel "$REQUEST_ID" "$TUNNEL_PORT"
                fi
            else
                # No active requests - stop tunnel if running
                if [ -f "$PROXY_LOCKFILE" ]; then
                    log_message "No active requests, stopping tunnel"
                    stop_reverse_tunnel
                fi
            fi
            
            PROXY_CHECK_COUNTER=0
        fi
        
        # Sleep 1 second
        sleep 1
        
        CHECKIN_COUNTER=$((CHECKIN_COUNTER + 1))
        PROXY_CHECK_COUNTER=$((PROXY_CHECK_COUNTER + 1))
    done
}

# Signal handlers
cleanup() {
    log_message "Agent shutting down"
    stop_reverse_tunnel
    release_lock "$LOCKFILE"
    exit 0
}

trap cleanup INT TERM

# Main execution
if ! acquire_lock "$LOCKFILE"; then
    echo "Agent is already running"
    exit 1
fi

# Create log file
touch "$LOG_FILE"

# Run main loop
main_loop
