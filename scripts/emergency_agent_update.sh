#!/bin/bash

# Emergency Agent Update Script
# This script forcefully updates the OPNsense agent when the regular command system is broken

LOG_FILE="/var/log/opnmanager_emergency_update.log"

log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - EMERGENCY_UPDATE: $1" | tee -a "$LOG_FILE"
}

log_message "Starting emergency agent update for firewall ID 21"

# Method 1: Force agent update via database manipulation
log_message "Setting agent version to force update"
mysql -u opnsense_user -ppassword -D opnsense_fw -e "
UPDATE firewalls SET agent_version = '1.0.0' WHERE id = 21;
" 2>/dev/null

# Method 2: Use the working auto-update script to trigger updates
log_message "Triggering automatic update via script"
/var/www/opnsense/scripts/auto_update_firewall.sh

# Method 3: Monitor for changes
log_message "Monitoring for firewall response..."
for i in {1..10}; do
    sleep 30
    result=$(mysql -u opnsense_user -ppassword -D opnsense_fw -e "SELECT agent_version, last_checkin FROM firewalls WHERE id = 21;" -s -N 2>/dev/null)
    log_message "Check $i: $result"
    
    if echo "$result" | grep -q "2.1.3"; then
        log_message "SUCCESS: Agent updated to version 2.1.3"
        break
    fi
done

log_message "Emergency update process completed"