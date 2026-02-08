#!/bin/sh

# Emergency Agent Fix Script v2
# Simple and robust version for OPNsense firewalls

LOG_FILE="/tmp/emergency_agent_fix.log"

log_msg() {
    echo "$(date) - $1" | tee -a "$LOG_FILE"
}

log_msg "=== EMERGENCY AGENT FIX STARTED ==="

# Step 1: Basic diagnostics
log_msg "Current user: $(whoami)"
log_msg "Date: $(date)"
log_msg "Python3: $(which python3 || echo 'NOT FOUND')"
log_msg "Curl: $(which curl || echo 'NOT FOUND')"
log_msg "Fetch: $(which fetch || echo 'NOT FOUND')"

# Step 2: Check current agent
if [ -f "/usr/local/bin/opnsense_agent.sh" ]; then
    log_msg "Agent exists: $(ls -la /usr/local/bin/opnsense_agent.sh)"
    log_msg "First 5 lines of agent:"
    head -5 /usr/local/bin/opnsense_agent.sh >> "$LOG_FILE" 2>&1
else
    log_msg "Agent NOT FOUND at /usr/local/bin/opnsense_agent.sh"
fi

# Step 3: Test network
log_msg "Testing network connectivity..."
if ping -c 1 opn.agit8or.net >/dev/null 2>&1; then
    log_msg "Network OK - can ping server"
else
    log_msg "Network FAILED - cannot ping server"
fi

# Step 4: Download fresh agent
log_msg "Downloading fresh agent..."
if fetch -o /tmp/fresh_agent.sh "https://opn.agit8or.net/opnsense_agent_v3.1.sh" >/dev/null 2>&1; then
    log_msg "Fresh agent downloaded successfully"
    
    # Backup old agent
    if [ -f "/usr/local/bin/opnsense_agent.sh" ]; then
        cp /usr/local/bin/opnsense_agent.sh /usr/local/bin/opnsense_agent.sh.backup
        log_msg "Old agent backed up"
    fi
    
    # Install fresh agent
    cp /tmp/fresh_agent.sh /usr/local/bin/opnsense_agent.sh
    chmod +x /usr/local/bin/opnsense_agent.sh
    log_msg "Fresh agent installed and made executable"
else
    log_msg "FAILED to download fresh agent"
fi

# Step 5: Fix cron job
log_msg "Fixing cron job..."
crontab -l 2>/dev/null | grep -v "opnsense_agent" > /tmp/new_cron
echo "*/2 * * * * /usr/local/bin/opnsense_agent.sh checkin >/dev/null 2>&1" >> /tmp/new_cron
crontab /tmp/new_cron
if [ $? -eq 0 ]; then
    log_msg "Cron job updated to run every 2 minutes"
else
    log_msg "FAILED to update cron job"
fi

# Step 6: Test agent manually
log_msg "Testing agent manually..."
if [ -f "/usr/local/bin/opnsense_agent.sh" ]; then
    /usr/local/bin/opnsense_agent.sh checkin >> "$LOG_FILE" 2>&1
    if [ $? -eq 0 ]; then
        log_msg "Agent test SUCCESSFUL"
        echo "SUCCESS: Agent fixed and working"
    else
        log_msg "Agent test FAILED"
        echo "FAILED: Agent still not working"
    fi
else
    log_msg "Agent file missing after download"
    echo "FAILED: Could not install agent"
fi

log_msg "=== EMERGENCY FIX COMPLETED ==="
log_msg "Log file: $LOG_FILE"