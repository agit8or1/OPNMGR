#!/bin/bash

# Push Marketing Website Disable Update to All Firewalls
# This script triggers the disable update on all managed firewalls

LOG_FILE="/var/log/opnmanager_updates.log"

log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - PUSH_UPDATE: $1" | tee -a "$LOG_FILE"
}

log_message "Starting marketing website disable update push"

# Get list of all active firewalls
mysql -u opnsense_user -p'password' opnsense_fw -N -e "SELECT id, hostname, ip_address FROM firewalls WHERE status = 'online'" | while read firewall_id hostname ip_address; do
    log_message "Triggering update for firewall: $hostname ($ip_address) [ID: $firewall_id]"
    
    # Create update trigger in database
    mysql -u opnsense_user -p'password' opnsense_fw -e "INSERT INTO firewall_commands (firewall_id, command, description, status, created_at) VALUES ($firewall_id, 'update_agent', 'marketing_disable_v1.0.1', 'pending', NOW());" 2>/dev/null
    
    if [ $? -eq 0 ]; then
        log_message "Update queued successfully for firewall $hostname"
    else
        log_message "ERROR: Failed to queue update for firewall $hostname (firewall_commands table may not exist)"
        # Alternative: Set update flag directly on firewall
        mysql -u opnsense_user -p'password' opnsense_fw -e "UPDATE firewalls SET update_requested = 1, update_requested_at = NOW() WHERE id = $firewall_id;"
        log_message "Set update_requested flag for firewall $hostname"
    fi
done

# Update the change log to mark as applied
mysql -u opnsense_user -p'password' opnsense_fw -e "UPDATE change_log SET change_type = 'update_applied' WHERE version = '1.0.1' AND change_type = 'update_applied';"

log_message "Marketing website disable update push completed"
log_message "Check individual firewall logs for update status"

echo "Update push completed. Check $LOG_FILE for details."