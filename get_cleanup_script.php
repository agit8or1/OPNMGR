<?php
header('Content-Type: text/plain');

// Serve the cleanup script directly
echo '#!/bin/sh
# Complete Agent Cleanup and Reinstall
# This script removes ALL agent artifacts and installs a clean version

echo "$(date): Starting complete cleanup..."

# Kill ALL possible agent processes
pkill -9 -f tunnel_agent 2>/dev/null
pkill -9 -f opnsense_agent 2>/dev/null  
pkill -9 -f agent_checkin 2>/dev/null
pkill -9 -f "socat.*:81" 2>/dev/null

# Wait for processes to die
sleep 5

# Kill any remaining processes by searching process list
ps aux | grep -E "(tunnel|opnsense|agent)" | grep -v grep | awk \'{print $2}\' | xargs kill -9 2>/dev/null

# Remove all agent files
rm -f /tmp/tunnel_agent* /tmp/opnsense_agent* /tmp/*agent* 2>/dev/null
rm -f /usr/local/bin/tunnel_agent* /usr/local/bin/opnsense_agent* 2>/dev/null
rm -f /usr/local/opnsense_agent/* 2>/dev/null

echo "$(date): Cleanup completed. Installing clean agent..."

# Create clean installation directory
mkdir -p /usr/local/bin

# Install NEW agent with fixed version
cat > /usr/local/bin/tunnel_agent.sh << \'EOF\'
#!/bin/sh

# OPNsense Tunnel Agent v2.0.9 - FINAL STABLE VERSION
AGENT_VERSION="2.0.9"
MANAGEMENT_SERVER="opn.agit8or.net"
FIREWALL_ID="21"
LOCAL_WEB_PORT="443"
CHECKIN_INTERVAL="120"

echo "$(date): OPNsense Tunnel Agent v2.0.9 starting (PID: $$)..."

# Function to establish tunnel connection
establish_tunnel() {
    echo "$(date): Establishing tunnel connection..."
    
    # Find an available port in the range 8100-8200
    for port in $(seq 8100 8200); do
        if ! sockstat -l | grep -q ":$port "; then
            TUNNEL_PORT=$port
            break
        fi
    done
    
    if [ -z "$TUNNEL_PORT" ]; then
        echo "$(date): Error: No available ports in range 8100-8200"
        exit 1
    fi
    
    echo "$(date): Using tunnel port: $TUNNEL_PORT"
    
    # Kill any existing tunnel on this port
    pkill -f "socat.*:$TUNNEL_PORT" 2>/dev/null
    
    # Start reverse tunnel
    socat TCP-LISTEN:$TUNNEL_PORT,reuseaddr,fork TCP:127.0.0.1:$LOCAL_WEB_PORT &
    TUNNEL_PID=$!
    
    if [ $? -eq 0 ]; then
        echo "$(date): Tunnel established on port $TUNNEL_PORT (PID: $TUNNEL_PID)"
        return 0
    else
        echo "$(date): Failed to establish tunnel"
        return 1
    fi
}

# Function to perform checkin and keep alive
keep_alive() {
    while true; do
        sleep $CHECKIN_INTERVAL
        
        # Get system information for checkin
        OPNSENSE_VERSION=$(opnsense-version 2>/dev/null | head -1 | tr -d \'\n\r\')
        if [ -z "$OPNSENSE_VERSION" ] || [ "$OPNSENSE_VERSION" = "" ]; then
            OPNSENSE_VERSION=$(uname -r | sed \'s/-.*//\')
        fi
        if [ -z "$OPNSENSE_VERSION" ] || [ "$OPNSENSE_VERSION" = "" ]; then
            OPNSENSE_VERSION="25.7"
        fi
        
        UPTIME=$(uptime | cut -d\',\' -f1 | cut -d\' \' -f4-5)
        WAN_IP=$(curl -s -k --connect-timeout 5 https://api.ipify.org || echo "73.35.46.112")
        
        # Get actual LAN IP (not WAN IP) - try multiple interfaces
        LAN_IP=""
        for iface in em0 em1 igb0 igb1 re0 re1 bge0 bge1 vtnet0 vtnet1; do
            TEMP_IP=$(ifconfig "$iface" 2>/dev/null | awk \'/inet / && !/127\.0\.0\.1/ {print $2; exit}\')
            if [ -n "$TEMP_IP" ] && [ "$TEMP_IP" != "$WAN_IP" ]; then
                LAN_IP="$TEMP_IP"
                break
            fi
        done
        
        # Fallback method - get first non-WAN IP
        if [ -z "$LAN_IP" ]; then
            LAN_IP=$(ifconfig 2>/dev/null | awk \'/inet / && !/127\.0\.0\.1/ && !/169\.254\./ {print $2}\' | grep -v "$WAN_IP" | head -1)
        fi
        
        # Final fallback
        if [ -z "$LAN_IP" ] || [ "$LAN_IP" = "$WAN_IP" ]; then
            LAN_IP="10.0.0.1"
        fi
        
        # Get IPv6 address
        IPV6_ADDR=$(ifconfig 2>/dev/null | awk \'/inet6 / && !/fe80:/ && !/::1/ {print $2; exit}\')
        if [ -z "$IPV6_ADDR" ]; then
            IPV6_ADDR="unknown"
        fi
        
        # Send full checkin data
        RESPONSE=$(curl -s -k -X POST "https://$MANAGEMENT_SERVER/agent_checkin.php" \
            -H "Content-Type: application/json" \
            -d "{
                \"firewall_id\": \"$FIREWALL_ID\",
                \"agent_version\": \"$AGENT_VERSION\",
                \"tunnel_port\": \"$TUNNEL_PORT\",
                \"opnsense_version\": \"$OPNSENSE_VERSION\",
                \"uptime\": \"$UPTIME\",
                \"wan_ip\": \"$WAN_IP\",
                \"lan_ip\": \"$LAN_IP\",
                \"ipv6_address\": \"$IPV6_ADDR\"
            }")
        
        if [ $? -eq 0 ]; then
            echo "$(date): v2.0.9 Checkin OK - LAN: $LAN_IP, WAN: $WAN_IP"
        else
            echo "$(date): Checkin failed, attempting to re-establish tunnel"
            establish_tunnel
        fi
        
        # Check tunnel
        if [ -n "$TUNNEL_PID" ] && ! kill -0 $TUNNEL_PID 2>/dev/null; then
            establish_tunnel
        fi
    done
}

# Main execution
echo "$(date): Starting tunnel agent v2.0.9..."
establish_tunnel
keep_alive
EOF

# Make executable
chmod +x /usr/local/bin/tunnel_agent.sh

# Start the NEW agent
echo "$(date): Starting clean v2.0.9 agent..."
nohup /usr/local/bin/tunnel_agent.sh > /tmp/agent_v2.0.9.log 2>&1 &
NEW_PID=$!

# Verify it started
sleep 3
if kill -0 $NEW_PID 2>/dev/null; then
    echo "$(date): SUCCESS: Clean v2.0.9 agent started (PID: $NEW_PID)"
    echo "$(date): Log file: /tmp/agent_v2.0.9.log"
else
    echo "$(date): ERROR: Agent failed to start"
    exit 1
fi

echo "$(date): Complete cleanup and reinstall finished"
';
?>