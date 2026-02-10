<?php
header('Content-Type: text/plain');
?>#!/bin/sh

echo "=== WORKING AGENT WITH CURL ==="
echo "Time: `date`"

echo "Step 1: Creating curl-based agent (we know curl works)..."
cat > /usr/local/bin/opnsense_agent.sh << 'EOF'
#!/bin/sh

AGENT_VERSION="5.1_curl_working"
MANAGEMENT_SERVER="https://opn.agit8or.net"
LOG_FILE="/var/log/opnsense_agent.log"

if [ -f "/usr/local/etc/opnsense_firewall_id" ]; then
    FIREWALL_ID=`cat /usr/local/etc/opnsense_firewall_id`
else
    FIREWALL_ID="21"
fi

log_message() {
    echo "`date '+%Y-%m-%d %H:%M:%S'` [AGENTv5.1] $1" >> "$LOG_FILE"
}

do_checkin_and_commands() {
    log_message "Starting checkin and command processing for firewall ID: $FIREWALL_ID"
    
    # Gather system info
    WAN_IP=`fetch -q -o - http://ipv4.icanhazip.com 2>/dev/null || echo unknown`
    LAN_IP=`ifconfig | grep 'inet ' | grep -v 127.0.0.1 | head -1 | cut -d' ' -f2 || echo 10.0.0.1`
    OPNSENSE_VER=`opnsense-version 2>/dev/null | head -1 || echo unknown`
    UPTIME_VAL=`uptime | sed 's/.*up *//' | sed 's/,.*//' || echo unknown`
    
    # Create JSON payload
    cat > /tmp/checkin.json << JSONEOF
{
    "firewall_id": $FIREWALL_ID,
    "agent_version": "$AGENT_VERSION",
    "api_key": "placeholder",
    "wan_ip": "$WAN_IP",
    "lan_ip": "$LAN_IP",
    "ipv6_address": "",
    "opnsense_version": "$OPNSENSE_VER",
    "uptime": "$UPTIME_VAL"
}
JSONEOF

    # Send checkin using curl (we know this works)
    CHECKIN_RESULT=`curl -s -X POST -H "Content-Type: application/json" -d @/tmp/checkin.json "$MANAGEMENT_SERVER/agent_checkin.php" 2>/dev/null`
    
    if echo "$CHECKIN_RESULT" | grep -q '"success":true'; then
        log_message "Checkin successful"
        
        # Check if there are queued commands in the response
        if echo "$CHECKIN_RESULT" | grep -q '"queued_commands"'; then
            log_message "Queued commands found in checkin response"
            
            # Extract and execute the first command
            # Simple extraction for the first command in the array
            FIRST_CMD=`echo "$CHECKIN_RESULT" | sed 's/.*"command":"\\([^"]*\\)".*/\\1/' | sed 's/\\\\\//g'`
            
            if [ -n "$FIRST_CMD" ] && [ "$FIRST_CMD" != "$CHECKIN_RESULT" ]; then
                log_message "Executing command: $FIRST_CMD"
                echo "Executing: $FIRST_CMD"
                
                # Execute the command
                EXEC_OUTPUT=`eval "$FIRST_CMD" 2>&1`
                EXEC_EXIT=$?
                
                log_message "Command completed with exit code: $EXEC_EXIT"
                log_message "Command output: $EXEC_OUTPUT"
                
                if [ $EXEC_EXIT -eq 0 ]; then
                    echo "Command executed successfully"
                else
                    echo "Command failed with exit code: $EXEC_EXIT"
                fi
            else
                log_message "No valid command found to execute"
            fi
        else
            log_message "No queued commands"
        fi
        
        echo "SUCCESS"
        return 0
    else
        log_message "Checkin failed: $CHECKIN_RESULT"
        echo "FAILED"
        return 1
    fi
}

case "${1:-checkin}" in
    "checkin")
        do_checkin_and_commands
        ;;
    "test")
        echo "Agent $AGENT_VERSION ready, ID: $FIREWALL_ID"
        echo "Features: Curl-based checkin + Command processing"
        ;;
    *)
        echo "Usage: $0 [checkin|test]"
        ;;
esac
EOF

chmod +x /usr/local/bin/opnsense_agent.sh

echo "Step 2: Testing curl-based agent..."
echo "Agent test:"
/usr/local/bin/opnsense_agent.sh test

echo ""
echo "Checkin test (should process commands):"
/usr/local/bin/opnsense_agent.sh checkin

echo ""
echo "=== CURL AGENT DEPLOYED ==="
echo "✅ Agent v5.1 using curl (proven working)"
echo "✅ Processes commands from checkin response"
echo "✅ Should execute pending commands immediately"