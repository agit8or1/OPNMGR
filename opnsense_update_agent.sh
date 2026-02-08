#!/bin/sh

###############################################################################
# OPNsense Update Agent v1.1.0
# 
# CRITICAL: This is the FAILSAFE agent - keeps running even when primary dies
# 
# Purpose: Emergency recovery and updates ONLY
# - Simple, minimal code to reduce risk of breaking
# - Cannot be killed via commands (no shell command execution)
# - Only downloads and restarts primary agent
# - Checks in independently
# - Monitors primary agent health
#
# NEVER update this agent via commands - manual SSH only!
###############################################################################

# === PID LOCKING (MUST BE FIRST) ===
PID_FILE="/var/run/opnsense_update_agent.pid"

# Check if already running
if [ -f "$PID_FILE" ]; then
    OLD_PID=$(cat "$PID_FILE")
    if kill -0 "$OLD_PID" 2>/dev/null; then
        echo "Update agent already running with PID $OLD_PID"
        exit 1
    fi
fi

# Write our PID immediately
echo $$ > "$PID_FILE"

# Cleanup on exit
trap 'rm -f "$PID_FILE"' EXIT INT TERM

# === CONFIGURATION ===
AGENT_VERSION="1.1.0"
AGENT_TYPE="update"
FIREWALL_ID="__FIREWALL_ID__"
MANAGER_URL="https://opn.agit8or.net"
CHECKIN_URL="${MANAGER_URL}/agent_checkin.php"
PRIMARY_AGENT_PATH="/usr/local/bin/opnsense_agent_v2.sh"
PRIMARY_AGENT_PID="/var/run/opnsense_agent.pid"
LOG_FILE="/var/log/opnsense_update_agent.log"
CHECKIN_INTERVAL=300  # 5 minutes

# === EXPLICIT BINARY PATHS (FreeBSD compatibility) ===
CURL="/usr/local/bin/curl"
HOSTNAME_BIN="/bin/hostname"
DATE="/bin/date"
GREP="/usr/bin/grep"
AWK="/usr/bin/awk"

# === LOGGING ===
log_message() {
    TIMESTAMP=$($DATE '+%Y-%m-%d %H:%M:%S')
    echo "[$TIMESTAMP] $1" >> "$LOG_FILE"
    logger -t opnsense_update_agent "$1"
}

log_message "=== Update Agent v${AGENT_VERSION} starting (PID: $$) ==="
log_message "Firewall ID: ${FIREWALL_ID}"
log_message "Check-in interval: ${CHECKIN_INTERVAL}s"

# === MAIN LOOP ===
while true; do
    # Get system info
    HOSTNAME_VAL=$($HOSTNAME_BIN)
    UPTIME_VAL=$(uptime | $AWK '{print $3}')
    
    # Build JSON payload (inline format - no heredoc!)
    JSON_PAYLOAD="{\"firewall_id\":${FIREWALL_ID},\"agent_version\":\"${AGENT_VERSION}\",\"agent_type\":\"${AGENT_TYPE}\",\"hostname\":\"${HOSTNAME_VAL}\",\"uptime\":\"${UPTIME_VAL}\"}"
    
    # Check in to server
    RESPONSE=$($CURL -k -s -m 30 -X POST "$CHECKIN_URL" \
        -H "Content-Type: application/json" \
        -d "$JSON_PAYLOAD" 2>&1)
    
    CURL_EXIT=$?
    
    if [ $CURL_EXIT -eq 0 ] && [ -n "$RESPONSE" ]; then
        log_message "Check-in completed: $(echo "$RESPONSE" | head -c 100)"
        
        # === CHECK FOR UPDATE COMMAND ===
        # Look for "update_primary":true in JSON response
        UPDATE_CMD=$(echo "$RESPONSE" | $GREP -o '"update_primary"[[:space:]]*:[[:space:]]*true')
        
        if [ -n "$UPDATE_CMD" ]; then
            log_message "!!! UPDATE COMMAND RECEIVED FOR PRIMARY AGENT !!!"
            
            # Get download URL from response
            DOWNLOAD_URL=$(echo "$RESPONSE" | $GREP -o '"download_url"[[:space:]]*:[[:space:]]*"[^"]*"' | $AWK -F'"' '{print $4}')
            
            if [ -n "$DOWNLOAD_URL" ]; then
                log_message "Downloading new primary agent from: ${DOWNLOAD_URL}"
                
                # Download new primary agent
                $CURL -k -s -m 60 -o "${PRIMARY_AGENT_PATH}.new" "${MANAGER_URL}${DOWNLOAD_URL}" 2>&1
                
                if [ $? -eq 0 ] && [ -s "${PRIMARY_AGENT_PATH}.new" ]; then
                    # Verify it's a valid shell script
                    if head -1 "${PRIMARY_AGENT_PATH}.new" | $GREP -q "^#!/"; then
                        log_message "New agent downloaded successfully"
                        
                        # Backup old agent
                        if [ -f "$PRIMARY_AGENT_PATH" ]; then
                            BACKUP_FILE="${PRIMARY_AGENT_PATH}.backup.$($DATE +%Y%m%d_%H%M%S)"
                            mv "$PRIMARY_AGENT_PATH" "$BACKUP_FILE"
                            log_message "Old agent backed up to: $BACKUP_FILE"
                        fi
                        
                        # Install new agent
                        mv "${PRIMARY_AGENT_PATH}.new" "$PRIMARY_AGENT_PATH"
                        chmod +x "$PRIMARY_AGENT_PATH"
                        
                        log_message "New primary agent installed"
                        
                        # Kill old primary agent processes
                        if [ -f "$PRIMARY_AGENT_PID" ]; then
                            OLD_PRIMARY_PID=$(cat "$PRIMARY_AGENT_PID")
                            if kill -0 "$OLD_PRIMARY_PID" 2>/dev/null; then
                                kill "$OLD_PRIMARY_PID"
                                log_message "Killed old primary agent (PID: $OLD_PRIMARY_PID)"
                            fi
                        fi
                        
                        # Also kill by name (backup)
                        pkill -f "opnsense_agent.sh"
                        sleep 3
                        
                        # Start new primary agent
                        nohup "$PRIMARY_AGENT_PATH" > /dev/null 2>&1 &
                        NEW_PID=$!
                        
                        log_message "Primary agent restarted (PID: $NEW_PID)"
                        
                        # Get new version
                        NEW_VERSION=$($GREP 'AGENT_VERSION=' "$PRIMARY_AGENT_PATH" | head -1 | $AWK -F'"' '{print $2}')
                        
                        # Report success back to server
                        SUCCESS_JSON="{\"firewall_id\":${FIREWALL_ID},\"agent_type\":\"primary\",\"status\":\"updated\",\"version\":\"${NEW_VERSION}\"}"
                        $CURL -k -s -X POST "${MANAGER_URL}/agent_update_status.php" \
                            -H "Content-Type: application/json" \
                            -d "$SUCCESS_JSON" 2>&1
                        
                        log_message "Update SUCCESS - Primary agent updated to v${NEW_VERSION}"
                    else
                        log_message "ERROR: Downloaded file is not a valid shell script"
                        rm -f "${PRIMARY_AGENT_PATH}.new"
                        
                        # Report failure
                        FAIL_JSON="{\"firewall_id\":${FIREWALL_ID},\"agent_type\":\"primary\",\"status\":\"failed\",\"error\":\"invalid_script\"}"
                        $CURL -k -s -X POST "${MANAGER_URL}/agent_update_status.php" \
                            -H "Content-Type: application/json" \
                            -d "$FAIL_JSON" 2>&1
                    fi
                else
                    log_message "ERROR: Failed to download primary agent (curl exit: $?)"
                    rm -f "${PRIMARY_AGENT_PATH}.new"
                    
                    # Report failure
                    FAIL_JSON="{\"firewall_id\":${FIREWALL_ID},\"agent_type\":\"primary\",\"status\":\"failed\",\"error\":\"download_failed\"}"
                    $CURL -k -s -X POST "${MANAGER_URL}/agent_update_status.php" \
                        -H "Content-Type: application/json" \
                        -d "$FAIL_JSON" 2>&1
                fi
            else
                log_message "ERROR: No download URL in update command"
            fi
        fi
        
        
    else
        log_message "Check-in failed (curl exit: $CURL_EXIT)"
        if [ -n "$RESPONSE" ]; then
            log_message "Response: $RESPONSE"
        fi
    fi
    
    # Sleep until next check-in
    sleep $CHECKIN_INTERVAL
done
