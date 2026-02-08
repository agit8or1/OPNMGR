#!/bin/sh

# Emergency Agent Fix Script
# This script will diagnose and fix the agent on the firewall

LOG_FILE="/tmp/emergency_agent_fix.log"

log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_FILE"
}

log_message "=== EMERGENCY AGENT FIX STARTED ==="

# Step 1: Diagnose the current situation
log_message "=== DIAGNOSTIC PHASE ==="
log_message "Current user: $(whoami)"
log_message "Current directory: $(pwd)"
log_message "Date: $(date)"

# Check dependencies
log_message "Python3 location: $(which python3 2>/dev/null || echo 'NOT FOUND')"
log_message "Curl location: $(which curl 2>/dev/null || echo 'NOT FOUND')"
log_message "Fetch location: $(which fetch 2>/dev/null || echo 'NOT FOUND')"

# Check current agent
if [ -f "/usr/local/bin/opnsense_agent.sh" ]; then
    log_message "Agent script exists: $(ls -la /usr/local/bin/opnsense_agent.sh)"
    log_message "Agent script first 10 lines:"
    head -10 /usr/local/bin/opnsense_agent.sh >> "$LOG_FILE" 2>&1
else
    log_message "Agent script NOT FOUND at /usr/local/bin/opnsense_agent.sh"
fi

# Check cron jobs
log_message "Current cron jobs:"
crontab -l 2>/dev/null >> "$LOG_FILE"

# Check processes
log_message "Current opnsense processes:"
ps aux | grep opnsense >> "$LOG_FILE" 2>&1

# Step 2: Test network connectivity
log_message "=== NETWORK TEST ==="
if ping -c 1 opn.agit8or.net >/dev/null 2>&1; then
    log_message "✅ Can ping management server"
else
    log_message "❌ Cannot ping management server"
fi

if curl -s -k "https://opn.agit8or.net/" >/dev/null 2>&1; then
    log_message "✅ HTTPS connection to management server works"
elif fetch -o /dev/null "https://opn.agit8or.net/" >/dev/null 2>&1; then
    log_message "✅ HTTPS connection works (using fetch)"
else
    log_message "❌ Cannot connect to management server via HTTPS"
fi

# Step 3: Try manual agent execution
log_message "=== MANUAL AGENT TEST ==="
if [ -f "/usr/local/bin/opnsense_agent.sh" ]; then
    log_message "Testing manual agent execution..."
    chmod +x /usr/local/bin/opnsense_agent.sh
    timeout 30 /usr/local/bin/opnsense_agent.sh checkin >> "$LOG_FILE" 2>&1
    if [ $? -eq 0 ]; then
        log_message "✅ Manual agent execution succeeded"
    else
        log_message "❌ Manual agent execution failed with exit code: $?"
    fi
else
    log_message "❌ Cannot test agent - script missing"
fi

# Step 4: Download fresh agent
log_message "=== DOWNLOADING FRESH AGENT ==="
if command -v curl >/dev/null 2>&1; then
    curl -s -k -o /tmp/fresh_agent.sh "https://opn.agit8or.net/opnsense_agent_v3.1.sh"
    download_result=$?
elif command -v fetch >/dev/null 2>&1; then
    fetch -o /tmp/fresh_agent.sh "https://opn.agit8or.net/opnsense_agent_v3.1.sh"
    download_result=$?
else
    log_message "❌ No download tool available (curl or fetch)"
    download_result=1
fi

if [ $download_result -eq 0 ] && [ -f "/tmp/fresh_agent.sh" ]; then
    log_message "✅ Fresh agent downloaded"
    chmod +x /tmp/fresh_agent.sh
    
    # Backup old agent
    if [ -f "/usr/local/bin/opnsense_agent.sh" ]; then
        cp /usr/local/bin/opnsense_agent.sh /usr/local/bin/opnsense_agent.sh.backup.$(date +%s)
        log_message "Old agent backed up"
    fi
    
    # Install fresh agent
    cp /tmp/fresh_agent.sh /usr/local/bin/opnsense_agent.sh
    chmod +x /usr/local/bin/opnsense_agent.sh
    log_message "✅ Fresh agent installed"
    
    # Test fresh agent
    log_message "Testing fresh agent..."
    timeout 30 /usr/local/bin/opnsense_agent.sh checkin >> "$LOG_FILE" 2>&1
    if [ $? -eq 0 ]; then
        log_message "✅ Fresh agent works!"
    else
        log_message "❌ Fresh agent also failed"
    fi
else
    log_message "❌ Failed to download fresh agent"
fi

# Step 5: Fix cron job (set to every 2 minutes)
log_message "=== FIXING CRON JOB ==="
# Remove old opnsense agent cron jobs
crontab -l 2>/dev/null | grep -v "opnsense_agent" > /tmp/new_crontab
# Add new cron job for every 2 minutes
echo "*/2 * * * * /usr/local/bin/opnsense_agent.sh checkin >/dev/null 2>&1" >> /tmp/new_crontab
crontab /tmp/new_crontab
if [ $? -eq 0 ]; then
    log_message "✅ Cron job updated to run every 2 minutes"
else
    log_message "❌ Failed to update cron job"
fi

# Step 6: Final test
log_message "=== FINAL TEST ==="
log_message "Performing final agent test..."
timeout 30 /usr/local/bin/opnsense_agent.sh checkin >> "$LOG_FILE" 2>&1
final_result=$?

if [ $final_result -eq 0 ]; then
    log_message "🎉 EMERGENCY FIX COMPLETED SUCCESSFULLY!"
    log_message "Agent should now check in every 2 minutes automatically"
else
    log_message "💀 EMERGENCY FIX FAILED"
    log_message "Manual intervention required on firewall"
fi

log_message "=== EMERGENCY FIX COMPLETE ==="
log_message "Full log available at: $LOG_FILE"

# Report results back to management server
if [ $final_result -eq 0 ]; then
    echo "SUCCESS: Emergency agent fix completed"
else
    echo "FAILED: Emergency agent fix failed - manual intervention required"
fi