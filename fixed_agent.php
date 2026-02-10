<?php
header('Content-Type: text/plain');
?>#!/bin/sh

echo "=== FIXED COMMAND PROCESSING ==="
echo "Time: `date`"

echo "Creating fixed agent with proper command parsing..."
cat > /usr/local/bin/opnsense_agent.sh << 'EOF'
#!/bin/sh

AGENT_VERSION="5.2_fixed_commands"
MANAGEMENT_SERVER="https://opn.agit8or.net"
LOG_FILE="/var/log/opnsense_agent.log"

if [ -f "/usr/local/etc/opnsense_firewall_id" ]; then
    FIREWALL_ID=`cat /usr/local/etc/opnsense_firewall_id`
else
    FIREWALL_ID="21"
fi

log_message() {
    echo "`date '+%Y-%m-%d %H:%M:%S'` [AGENTv5.2] $1" >> "$LOG_FILE"
}

do_checkin_and_commands() {
    log_message "Starting checkin for firewall ID: $FIREWALL_ID"
    
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

    # Send checkin
    CHECKIN_RESULT=`curl -s -X POST -H "Content-Type: application/json" -d @/tmp/checkin.json "$MANAGEMENT_SERVER/agent_checkin.php" 2>/dev/null`
    
    if echo "$CHECKIN_RESULT" | grep -q '"success":true'; then
        log_message "Checkin successful"
        
        # Simple approach: use separate API call to get commands (avoid complex JSON parsing)
        CMD_RESPONSE=`curl -s "$MANAGEMENT_SERVER/get_commands.php?firewall_id=$FIREWALL_ID" 2>/dev/null`
        
        if echo "$CMD_RESPONSE" | grep -q '"has_command":true'; then
            # Extract command using a simpler method
            echo "$CMD_RESPONSE" > /tmp/cmd_response.json
            
            # Get the command string (simple extraction)
            COMMAND=`grep -o '"command":"[^"]*"' /tmp/cmd_response.json | cut -d'"' -f4`
            
            if [ -n "$COMMAND" ]; then
                log_message "Executing command: $COMMAND"
                echo "Executing: $COMMAND"
                
                # Execute the command
                EXEC_OUTPUT=`eval "$COMMAND" 2>&1`
                EXEC_EXIT=$?
                
                log_message "Command completed with exit code: $EXEC_EXIT"
                log_message "Command output: $EXEC_OUTPUT"
                
                if [ $EXEC_EXIT -eq 0 ]; then
                    echo "Command executed successfully"
                    echo "Output: $EXEC_OUTPUT"
                else
                    echo "Command failed with exit code: $EXEC_EXIT"
                    echo "Error: $EXEC_OUTPUT"
                fi
            fi
        else
            log_message "No pending commands"
            echo "No commands to execute"
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
        ;;
    *)
        echo "Usage: $0 [checkin|test]"
        ;;
esac
EOF

chmod +x /usr/local/bin/opnsense_agent.sh

echo "Testing fixed agent..."
/usr/local/bin/opnsense_agent.sh checkin

echo ""
echo "Installing cron job..."
crontab -l 2>/dev/null | grep -v opnsense_agent > /tmp/cron_new || touch /tmp/cron_new
echo "*/2 * * * * /usr/local/bin/opnsense_agent.sh checkin >/dev/null 2>&1" >> /tmp/cron_new
crontab /tmp/cron_new
rm -f /tmp/cron_new

echo ""
echo "=== FIXED AGENT READY ==="
echo "✅ Simple command extraction (no complex sed)"
echo "✅ Uses separate get_commands.php API"  
echo "✅ Should process the pending diagnostic command"