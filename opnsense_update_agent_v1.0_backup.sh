#!/bin/sh

###############################################################################
# OPNsense Update Agent v1.0.0
# 
# CRITICAL: This is the FAILSAFE agent - keeps running even when primary dies
# 
# Purpose: Emergency recovery and updates ONLY
# - Simple, minimal code to reduce risk of breaking
# - Cannot be killed via commands (no shell command execution)
# - Only downloads and restarts primary agent
# - Checks in independently
#
# NEVER update this agent via commands - manual SSH only!
###############################################################################

AGENT_VERSION="1.0.0"
AGENT_TYPE="update"
FIREWALL_ID="__FIREWALL_ID__"
MANAGER_URL="https://opn.agit8or.net"
CHECKIN_URL="${MANAGER_URL}/agent_checkin.php"
PRIMARY_AGENT_PATH="/usr/local/bin/opnsense_agent.sh"
PID_FILE="/var/run/opnsense_update_agent.pid"
CHECKIN_INTERVAL=300  # 5 minutes

# Write our PID
echo $$ > "$PID_FILE"

log_message() {
    logger -t opnsense_update_agent "$1"
}

log_message "Update Agent v${AGENT_VERSION} starting (PID: $$)"

# Main loop
while true; do
    # Get system info for checkin
    HOSTNAME=$(hostname)
    UPTIME=$(uptime | awk '{print $3}')
    
    # Check in and get update commands (using JSON like primary agent)
    RESPONSE=$(curl -k -s -X POST "$CHECKIN_URL" \
        -H "Content-Type: application/json" \
        -d "{\"firewall_id\":${FIREWALL_ID},\"agent_version\":\"${AGENT_VERSION}\",\"agent_type\":\"${AGENT_TYPE}\",\"hostname\":\"${HOSTNAME}\",\"uptime\":\"${UPTIME}\"}")
    
    if [ $? -eq 0 ]; then
        log_message "Checked in successfully"
        
        # Check if there's an update command (look for download URL in response)
        DOWNLOAD_URL=$(echo "$RESPONSE" | grep -o 'download_primary_agent:[^"]*' | cut -d: -f2)
        
        if [ -n "$DOWNLOAD_URL" ]; then
            log_message "Update command received, downloading primary agent..."
            
            # Download new primary agent
            curl -k -s -o "${PRIMARY_AGENT_PATH}.new" "${MANAGER_URL}${DOWNLOAD_URL}"
            
            if [ $? -eq 0 ] && [ -s "${PRIMARY_AGENT_PATH}.new" ]; then
                # Backup old agent
                if [ -f "$PRIMARY_AGENT_PATH" ]; then
                    mv "$PRIMARY_AGENT_PATH" "${PRIMARY_AGENT_PATH}.backup.$(date +%Y%m%d_%H%M%S)"
                fi
                
                # Install new agent
                mv "${PRIMARY_AGENT_PATH}.new" "$PRIMARY_AGENT_PATH"
                chmod +x "$PRIMARY_AGENT_PATH"
                
                log_message "New primary agent installed"
                
                # Kill old primary agent processes
                pkill -f "opnsense_agent.sh"
                sleep 2
                
                # Start new primary agent
                nohup "$PRIMARY_AGENT_PATH" > /dev/null 2>&1 &
                
                log_message "Primary agent restarted (PID: $!)"
                
                # Report success back to server
                curl -k -s -X POST "${MANAGER_URL}/agent_update_status.php" \
                    -d "firewall_id=${FIREWALL_ID}" \
                    -d "agent_type=primary" \
                    -d "status=updated" \
                    -d "version=$(grep AGENT_VERSION= "$PRIMARY_AGENT_PATH" | cut -d'"' -f2)"
            else
                log_message "ERROR: Failed to download primary agent"
                
                # Report failure
                curl -k -s -X POST "${MANAGER_URL}/agent_update_status.php" \
                    -d "firewall_id=${FIREWALL_ID}" \
                    -d "agent_type=primary" \
                    -d "status=failed" \
                    -d "error=download_failed"
            fi
        fi
        
        # Check if primary agent is running, if not restart it
        if ! pgrep -f "opnsense_agent.sh" > /dev/null; then
            log_message "WARNING: Primary agent not running, restarting..."
            if [ -f "$PRIMARY_AGENT_PATH" ] && [ -x "$PRIMARY_AGENT_PATH" ]; then
                nohup "$PRIMARY_AGENT_PATH" > /dev/null 2>&1 &
                log_message "Primary agent restarted (PID: $!)"
            else
                log_message "ERROR: Primary agent file missing or not executable"
            fi
        fi
    else
        log_message "ERROR: Checkin failed"
    fi
    
    # Sleep until next checkin
    sleep $CHECKIN_INTERVAL
done
