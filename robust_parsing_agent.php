<?php
header('Content-Type: text/plain');
?>#!/bin/sh

echo "=== FIXING COMMAND EXTRACTION FOR BACKTICKS ==="
echo "Time: `date`"

echo "Creating agent with robust command parsing..."
cat > /usr/local/bin/opnsense_agent.sh << 'EOF'
#!/bin/sh

AGENT_VERSION="5.4_robust_parsing"
MANAGEMENT_SERVER="https://opn.agit8or.net"
LOG_FILE="/var/log/opnsense_agent.log"

if [ -f "/usr/local/etc/opnsense_firewall_id" ]; then
    FIREWALL_ID=`cat /usr/local/etc/opnsense_firewall_id`
else
    FIREWALL_ID="21"
fi

log_message() {
    echo "`date '+%Y-%m-%d %H:%M:%S'` [AGENTv5.4] $1" >> "$LOG_FILE"
}

extract_command_from_json() {
    # More robust JSON parsing using awk instead of sed
    # This handles backticks, quotes, and other special characters
    echo "$1" | awk -F'"command":"' '{
        if (NF > 1) {
            cmd = $2
            # Find the end of the command (next unescaped quote)
            gsub(/\\\\/, "\001")  # Temporarily replace escaped backslashes
            gsub(/\\"/, "\002")   # Temporarily replace escaped quotes
            split(cmd, parts, "\"")
            result = parts[1]
            gsub("\001", "\\\\", result)  # Restore escaped backslashes
            gsub("\002", "\"", result)    # Restore escaped quotes
            print result
        }
    }'
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
        
        # Get commands
        CMD_RESPONSE=`curl -s "$MANAGEMENT_SERVER/get_commands.php?firewall_id=$FIREWALL_ID" 2>/dev/null`
        
        if echo "$CMD_RESPONSE" | grep -q '"has_command":true'; then
            log_message "Command response: $CMD_RESPONSE"
            
            # Extract command using robust function
            COMMAND=`extract_command_from_json "$CMD_RESPONSE"`
            
            if [ -n "$COMMAND" ]; then
                log_message "Extracted command: $COMMAND"
                echo "Executing: $COMMAND"
                
                # Execute with explicit shell
                EXEC_OUTPUT=`/bin/sh -c "$COMMAND" 2>&1`
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
            else
                log_message "Failed to extract command from response"
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

echo "Testing robust parsing agent..."
/usr/local/bin/opnsense_agent.sh checkin

echo ""
echo "=== ROBUST PARSING AGENT DEPLOYED ==="
echo "✅ AWK-based JSON parsing (handles backticks)"
echo "✅ Explicit /bin/sh execution"
echo "✅ Better command logging"
echo "✅ Agent v5.4_robust_parsing ready"