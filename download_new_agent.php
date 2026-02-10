<?php
// Download endpoint for the NEW PID-safe agent

require_once __DIR__ . '/inc/version.php';

header('Content-Type: text/plain');
header('Content-Disposition: attachment; filename="opnsense_agent_v3.sh"');

// Get firewall_id from query string
$firewall_id = $_GET['firewall_id'] ?? 'UNKNOWN';
$agent_version = AGENT_VERSION;

echo <<<'AGENT'
#!/bin/sh
#
# OPNsense Management Agent v3.0
# Built-in PID locking to prevent duplicates
#

##############################################
# PID LOCK - MUST BE FIRST!
##############################################
PIDFILE="/var/run/opnsense_agent.pid"

if [ -f "$PIDFILE" ]; then
    OLD_PID=$(cat "$PIDFILE")
    if ps -p "$OLD_PID" >/dev/null 2>&1; then
        # Another agent is running - exit silently
        exit 0
    fi
    # Stale PID file
    rm -f "$PIDFILE"
fi

# Write our PID
echo $$ > "$PIDFILE"

# Ensure cleanup on exit
trap 'rm -f "$PIDFILE"' EXIT INT TERM

##############################################
# Configuration
##############################################
AGENT;

echo "MANAGEMENT_SERVER=\"https://opn.agit8or.net\"\n";
echo "AGENT_VERSION=\"$agent_version\"\n";
echo "FIREWALL_ID=\"$firewall_id\"\n";

echo <<<'AGENT'
LOG_FILE="/var/log/opnsense_agent.log"

# Logging function
log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') [$$] $1" >> "$LOG_FILE"
}

log_message "OPNsense Agent v$AGENT_VERSION starting with PID $$"

# Main check-in function
main() {
    # Get system info
    HOSTNAME=$(hostname)
    WAN_IP=$(curl -s -m 5 https://ipinfo.io/ip 2>/dev/null || echo "unknown")
    LAN_IP=$(ifconfig | grep 'inet ' | grep -v '127.0.0.1' | awk '{print $2}' | grep -E '^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)' | head -1)
    IPV6_ADDRESS=$(ifconfig | grep 'inet6' | grep -v '::1' | grep -v 'fe80:' | grep -v '%' | awk '{print $2}' | head -1)
    UPTIME=$(uptime | sed 's/.*up \([^,]*\).*/\1/' | sed 's/^ *//')
    OPNSENSE_VERSION=$(opnsense-version 2>/dev/null || echo "unknown")
    
    # Build JSON payload
    JSON_DATA=$(cat <<EOF
{
    "firewall_id": $FIREWALL_ID,
    "agent_version": "$AGENT_VERSION",
    "agent_type": "primary",
    "agent_pid": $$,
    "wan_ip": "$WAN_IP",
    "lan_ip": "${LAN_IP:-unknown}",
    "ipv6_address": "${IPV6_ADDRESS:-unknown}",
    "opnsense_version": "$OPNSENSE_VERSION",
    "uptime": "$UPTIME"
}
EOF
)
    
    # Send check-in
    RESPONSE=$(curl -k -s -m 30 -X POST \
        -H "Content-Type: application/json" \
        -d "$JSON_DATA" \
        "$MANAGEMENT_SERVER/agent_checkin.php")
    
    log_message "Check-in completed: $RESPONSE"
    
    # Parse response for next interval (default 120)
    NEXT_INTERVAL=$(echo "$RESPONSE" | grep -o '"checkin_interval":[0-9]*' | grep -o '[0-9]*')
    if [ -z "$NEXT_INTERVAL" ] || [ "$NEXT_INTERVAL" -lt 60 ]; then
        NEXT_INTERVAL=120
    fi
    
    # Check if server told us to die
    if echo "$RESPONSE" | grep -q '"die":true'; then
        log_message "Server requested shutdown - exiting"
        exit 0
    fi
    
    log_message "Next checkin in $NEXT_INTERVAL seconds"
    sleep "$NEXT_INTERVAL"
}

# Main loop
while true; do
    main
done
AGENT;
?>
