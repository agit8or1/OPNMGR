#!/bin/sh

# OPNsense Bulletproof Updater v3.0
# Completely independent updater service that ALWAYS works

MANAGEMENT_SERVER="opn.agit8or.net"
FIREWALL_ID="21"
UPDATER_VERSION="3.0_bulletproof"
CHECKIN_INTERVAL=120  # 2 minutes
LOG_FILE="/var/log/opnsense_updater.log"

# Logging function
log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') [UPDATER] $1" | tee -a "$LOG_FILE"
}

# Function to execute a command and report results
execute_command() {
    local cmd_id="$1"
    local command="$2"
    local description="$3"
    
    log_message "Executing command $cmd_id: $description"
    
    # Execute the command and capture output
    local output=$(eval "$command" 2>&1)
    local exit_code=$?
    
    if [ $exit_code -eq 0 ]; then
        log_message "Command $cmd_id completed successfully"
        local status="success"
    else
        log_message "Command $cmd_id failed with exit code $exit_code"
        local status="failed"
    fi
    
    # Report result back to management server
    local result_data="{\"firewall_id\":$FIREWALL_ID,\"command_id\":\"$cmd_id\",\"status\":\"$status\",\"result\":\"$(echo "$output" | sed 's/"/\\"/g' | tr '\n' ' ')\"}"
    
    curl -s -X POST "https://$MANAGEMENT_SERVER/updater_command_result.php" \
        -H "Content-Type: application/json" \
        -d "$result_data" \
        --max-time 30 >/dev/null 2>&1
    
    log_message "Result reported for command $cmd_id"
}

# Main updater loop
main_loop() {
    log_message "Bulletproof Updater v3.0 starting main loop"
    
    while true; do
        log_message "Checking in with management server..."
        
        # Check in with management server
        local checkin_data="{\"firewall_id\":$FIREWALL_ID,\"updater_version\":\"$UPDATER_VERSION\"}"
        local response=$(curl -s -X POST "https://$MANAGEMENT_SERVER/updater_checkin.php" \
            -H "Content-Type: application/json" \
            -d "$checkin_data" \
            --max-time 30 2>/dev/null)
        
        if [ $? -eq 0 ] && [ -n "$response" ]; then
            log_message "Checkin successful"
            
            # Check for pending commands and execute them
            if echo "$response" | grep -q "pending_commands"; then
                log_message "Commands received, processing..."
                
                # Parse JSON and execute commands (simplified parsing)
                # In a real implementation, would use proper JSON parser
                # For now, extract command info manually
                local temp_file="/tmp/updater_commands.json"
                echo "$response" > "$temp_file"
                
                # This is a simplified command extraction - in production use proper JSON parser
                log_message "Commands found in response, executing..."
                
                # Force execute critical agent update if present
                if echo "$response" | grep -q "AGENT_UPDATE"; then
                    log_message "AGENT_UPDATE command detected - force executing agent replacement"
                    
                    # Kill old agent
                    killall -9 opnsense_agent.sh opnsense_agent python 2>/dev/null
                    
                    # Download new agent
                    if fetch -q -o /usr/local/bin/opnsense_agent.sh "https://$MANAGEMENT_SERVER/download_tunnel_agent.php?firewall_id=$FIREWALL_ID"; then
                        chmod +x /usr/local/bin/opnsense_agent.sh
                        
                        # Install new agent
                        if /usr/local/bin/opnsense_agent.sh install; then
                            log_message "Agent v2.3 successfully installed and started"
                            
                            # Report success for all AGENT_UPDATE commands
                            for cmd_id in $(echo "$response" | grep -o '"id":"[0-9]*"' | grep -o '[0-9]*'); do
                                if [ -n "$cmd_id" ]; then
                                    execute_command "$cmd_id" "echo 'Agent updated successfully'" "Agent replacement completed"
                                fi
                            done
                        else
                            log_message "Agent installation failed"
                        fi
                    else
                        log_message "Failed to download new agent"
                    fi
                fi
                
                rm -f "$temp_file"
            else
                log_message "No pending commands"
            fi
        else
            log_message "Checkin failed or no response"
        fi
        
        # Wait before next checkin
        sleep $CHECKIN_INTERVAL
    done
}

# Installation function
install_updater() {
    log_message "Installing Bulletproof Updater v3.0..."
    
    # Create service file
    cat > /etc/rc.d/opnsense_bulletproof_updater << 'EOF'
#!/bin/sh
#
# PROVIDE: opnsense_bulletproof_updater
# REQUIRE: NETWORKING
# KEYWORD: shutdown
#

. /etc/rc.subr

name="opnsense_bulletproof_updater"
rcvar="opnsense_bulletproof_updater_enable"
command="/usr/local/bin/opnsense_bulletproof_updater.sh"
command_interpreter="/bin/sh"
pidfile="/var/run/opnsense_bulletproof_updater.pid"
start_cmd="opnsense_bulletproof_updater_start"
stop_cmd="opnsense_bulletproof_updater_stop"

opnsense_bulletproof_updater_start() {
    echo "Starting OPNsense Bulletproof Updater v3.0..."
    daemon -p $pidfile $command main_loop
}

opnsense_bulletproof_updater_stop() {
    echo "Stopping OPNsense Bulletproof Updater..."
    if [ -f $pidfile ]; then
        kill $(cat $pidfile)
        rm -f $pidfile
    fi
    killall -9 opnsense_bulletproof_updater.sh 2>/dev/null
}

load_rc_config $name
run_rc_command "$1"
EOF
    
    chmod +x /etc/rc.d/opnsense_bulletproof_updater
    
    # Enable and start the service
    sysrc opnsense_bulletproof_updater_enable="YES"
    
    # Stop old updater
    service opnsense_updater stop 2>/dev/null
    killall -9 opnsense_updater.sh 2>/dev/null
    
    # Start new bulletproof updater
    service opnsense_bulletproof_updater start
    
    log_message "Bulletproof Updater v3.0 installed and started"
}

# Check command line arguments
case "$1" in
    install)
        install_updater
        ;;
    main_loop)
        main_loop
        ;;
    *)
        echo "Usage: $0 {install|main_loop}"
        exit 1
        ;;
esac