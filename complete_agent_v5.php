<?php
header('Content-Type: text/plain');
?>#!/bin/sh

echo "=== COMPLETE AGENT WITH COMMAND PROCESSING ==="
echo "Time: `date`"

echo "Step 1: Ensuring correct firewall ID..."
echo "21" > /usr/local/etc/opnsense_firewall_id
echo "Firewall ID: `cat /usr/local/etc/opnsense_firewall_id`"

echo "Step 2: Creating full-featured agent with command processing..."
cat > /usr/local/bin/opnsense_agent.sh << 'EOF'
#!/bin/sh

AGENT_VERSION="5.0_complete_with_commands"
MANAGEMENT_SERVER="https://opn.agit8or.net"
LOG_FILE="/var/log/opnsense_agent.log"

if [ -f "/usr/local/etc/opnsense_firewall_id" ]; then
    FIREWALL_ID=`cat /usr/local/etc/opnsense_firewall_id`
else
    FIREWALL_ID="21"
fi

log_message() {
    echo "`date '+%Y-%m-%d %H:%M:%S'` [AGENTv5.0] $1" >> "$LOG_FILE"
}

do_checkin() {
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
    if command -v curl >/dev/null 2>&1; then
        RESULT=`curl -s -X POST -H "Content-Type: application/json" -d @/tmp/checkin.json "$MANAGEMENT_SERVER/agent_checkin.php" 2>/dev/null`
    else
        RESULT=`fetch -q -o - -T 10 --upload-file=/tmp/checkin.json "$MANAGEMENT_SERVER/agent_checkin.php" 2>/dev/null`
    fi
    
    if echo "$RESULT" | grep -q '"success":true'; then
        log_message "Checkin successful"
        echo "SUCCESS"
        return 0
    else
        log_message "Checkin failed: $RESULT"
        echo "FAILED"
        return 1
    fi
}

check_and_execute_commands() {
    log_message "Checking for pending commands..."
    
    # Get pending commands
    if command -v curl >/dev/null 2>&1; then
        CMD_RESPONSE=`curl -s "$MANAGEMENT_SERVER/get_commands.php?firewall_id=$FIREWALL_ID" 2>/dev/null`
    else
        CMD_RESPONSE=`fetch -q -o - "$MANAGEMENT_SERVER/get_commands.php?firewall_id=$FIREWALL_ID" 2>/dev/null`
    fi
    
    if echo "$CMD_RESPONSE" | grep -q '"has_command":true'; then
        # Extract command from JSON response
        COMMAND=`echo "$CMD_RESPONSE" | sed 's/.*"command":"//' | sed 's/".*//' | sed 's/\\\\//g'`
        CMD_ID=`echo "$CMD_RESPONSE" | sed 's/.*"command_id"://' | sed 's/,.*//'`
        
        if [ -n "$COMMAND" ] && [ "$COMMAND" != "null" ]; then
            log_message "Executing command ID $CMD_ID: $COMMAND"
            echo "Executing: $COMMAND"
            
            # Execute the command and capture output
            EXEC_OUTPUT=`eval "$COMMAND" 2>&1`
            EXEC_EXIT=$?
            
            log_message "Command completed with exit code: $EXEC_EXIT"
            log_message "Command output: $EXEC_OUTPUT"
            
            # Report command completion (optional - add endpoint later)
            if [ $EXEC_EXIT -eq 0 ]; then
                echo "Command executed successfully"
            else
                echo "Command failed with exit code: $EXEC_EXIT"
            fi
        fi
    else
        log_message "No pending commands"
    fi
}

case "${1:-checkin}" in
    "checkin")
        do_checkin
        check_and_execute_commands
        ;;
    "test")
        echo "Agent $AGENT_VERSION ready, ID: $FIREWALL_ID"
        echo "Features: Checkin + Command Processing + Execution"
        ;;
    "commands")
        check_and_execute_commands
        ;;
    *)
        echo "Usage: $0 [checkin|test|commands]"
        ;;
esac
EOF

chmod +x /usr/local/bin/opnsense_agent.sh

echo "Step 3: Testing complete agent..."
echo "Agent test:"
/usr/local/bin/opnsense_agent.sh test

echo ""
echo "Checkin test:"
/usr/local/bin/opnsense_agent.sh checkin

echo ""
echo "Step 4: Installing cron job..."
crontab -l 2>/dev/null | grep -v opnsense_agent > /tmp/cron_fixed || touch /tmp/cron_fixed
echo "*/2 * * * * /usr/local/bin/opnsense_agent.sh checkin >/dev/null 2>&1" >> /tmp/cron_fixed
crontab /tmp/cron_fixed
rm -f /tmp/cron_fixed

echo "Step 5: Force execute pending command..."
/usr/local/bin/opnsense_agent.sh commands

echo ""
echo "=== COMPLETE AGENT DEPLOYED ==="
echo "✅ Agent v5.0 with full command processing"
echo "✅ Correct firewall ID: 21" 
echo "✅ Checkin + Command execution"
echo "✅ Cron job: Every 2 minutes"
echo "✅ Ready to process pending commands"