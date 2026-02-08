<?php
header('Content-Type: text/plain');
?>#!/bin/sh

echo "=== SIMPLE FOOLPROOF COMMAND EXTRACTION ==="
echo "Time: `date`"

echo "Creating agent with Python-based JSON parsing..."
cat > /usr/local/bin/opnsense_agent.sh << 'EOF'
#!/bin/sh

AGENT_VERSION="5.5_python_parsing"
MANAGEMENT_SERVER="https://opn.agit8or.net"
LOG_FILE="/var/log/opnsense_agent.log"

if [ -f "/usr/local/etc/opnsense_firewall_id" ]; then
    FIREWALL_ID=`cat /usr/local/etc/opnsense_firewall_id`
else
    FIREWALL_ID="21"
fi

log_message() {
    echo "`date '+%Y-%m-%d %H:%M:%S'` [AGENTv5.5] $1" >> "$LOG_FILE"
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
            
            # Save response to file for Python parsing
            echo "$CMD_RESPONSE" > /tmp/cmd_response.json
            
            # Use Python to extract command (foolproof JSON parsing)
            COMMAND=`python3 -c "
import json
import sys
try:
    with open('/tmp/cmd_response.json', 'r') as f:
        data = json.load(f)
    print(data.get('command', ''))
except:
    pass
" 2>/dev/null`
            
            # Fallback to manual parsing if Python fails
            if [ -z "$COMMAND" ]; then
                # Last resort: string manipulation
                COMMAND=`cat /tmp/cmd_response.json | tr '\n' ' ' | sed 's/.*"command":"//' | sed 's/".*//' | sed 's/\\\\"/"/g' | sed 's/\\\\\//\//g'`
            fi
            
            if [ -n "$COMMAND" ]; then
                log_message "Extracted command: $COMMAND"
                echo "Executing: $COMMAND"
                
                # Execute command directly
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

echo "Testing Python-based parsing..."
/usr/local/bin/opnsense_agent.sh checkin

echo ""
echo "=== FOOLPROOF AGENT DEPLOYED ==="
echo "✅ Python JSON parsing (bulletproof)"
echo "✅ Fallback to manual string parsing"
echo "✅ Direct eval execution"
echo "✅ Agent v5.5_python_parsing ready"