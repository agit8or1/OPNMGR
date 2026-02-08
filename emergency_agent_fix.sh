#!/bin/sh

# Emergency Agent Repair Script
# This script will forcibly fix the agent installation and cron job

LOG_FILE="/var/log/emergency_agent_repair.log"

log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" >> "$LOG_FILE"
}

log_message "=== EMERGENCY AGENT REPAIR STARTED ==="

# Step 1: Kill any existing agent processes
log_message "Killing existing agent processes..."
pkill -f opnsense_agent
pkill -f opnsense_updater
sleep 2

# Step 2: Remove old cron jobs
log_message "Cleaning up cron jobs..."
crontab -l 2>/dev/null | grep -v opnsense | crontab - 2>/dev/null

# Step 3: Download fresh agent
log_message "Downloading fresh agent..."
fetch -o /tmp/opnsense_agent_fresh.sh "https://opn.agit8or.net/opnsense_agent_v3.1.sh" 2>/dev/null
if [ $? -eq 0 ]; then
    chmod +x /tmp/opnsense_agent_fresh.sh
    cp /tmp/opnsense_agent_fresh.sh /usr/local/bin/opnsense_agent.sh
    log_message "Fresh agent installed"
else
    log_message "Failed to download fresh agent"
    exit 1
fi

# Step 4: Set up new cron job (every 2 minutes)
log_message "Setting up cron job..."
(crontab -l 2>/dev/null; echo "*/2 * * * * /usr/local/bin/opnsense_agent.sh checkin >/dev/null 2>&1") | crontab -

# Step 5: Start agent immediately and test
log_message "Starting agent and performing test checkin..."
/usr/local/bin/opnsense_agent.sh checkin >> "$LOG_FILE" 2>&1

# Step 6: Start updater if not running
log_message "Checking updater service..."
if ! pgrep -f opnsense_updater >/dev/null; then
    log_message "Starting updater service..."
    fetch -o /tmp/opnsense_updater.sh "https://opn.agit8or.net/downloads/opnsense_updater.sh" 2>/dev/null
    if [ $? -eq 0 ]; then
        chmod +x /tmp/opnsense_updater.sh
        /tmp/opnsense_updater.sh install >/dev/null 2>&1
        service opnsense_updater start >/dev/null 2>&1
        log_message "Updater service started"
    fi
fi

log_message "=== EMERGENCY REPAIR COMPLETED ==="
log_message "Agent should now check in every 2 minutes"
log_message "Updater service should be running independently"

# Final test checkin
log_message "Performing final test checkin..."
/usr/local/bin/opnsense_agent.sh checkin >> "$LOG_FILE" 2>&1

echo "Emergency repair completed - check $LOG_FILE for details"
