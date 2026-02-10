#!/bin/bash

# Automatic Firewall Update Script
# This script triggers automatic updates for OPNsense firewalls

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="/var/log/opnmanager_auto_updates.log"

# Function to log messages
log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - AUTO_UPDATE: $1" | tee -a "$LOG_FILE"
}

# Function to trigger update for a specific firewall
trigger_firewall_update() {
    local firewall_id="$1"
    local firewall_name="$2"
    
    log_message "Triggering automatic update for firewall: $firewall_name (ID: $firewall_id)"
    
    # Set update request flag
    mysql -u opnsense_user -ppassword -D opnsense_fw -e "UPDATE firewalls SET update_requested = 1, update_requested_at = NOW() WHERE id = $firewall_id;" 2>/dev/null
    
    if [ $? -eq 0 ]; then
        log_message "Update request set successfully for firewall: $firewall_name"
        return 0
    else
        log_message "ERROR: Failed to set update request for firewall: $firewall_name"
        return 1
    fi
}

# Function to check if firewall needs updates
check_firewall_updates() {
    local firewall_id="$1"
    
    # Query firewall update status
    local result=$(mysql -u opnsense_user -ppassword -D opnsense_fw -e "SELECT updates_available FROM firewalls WHERE id = $firewall_id;" -s -N 2>/dev/null)
    
    if [ "$result" = "1" ]; then
        return 0  # Updates available
    else
        return 1  # No updates or error
    fi
}

# Main function
main() {
    log_message "Starting automatic firewall update check"
    
    # Get list of online firewalls
    local firewalls=$(mysql -u opnsense_user -ppassword -D opnsense_fw -e "SELECT id, hostname FROM firewalls WHERE status = 'online';" -s -N 2>/dev/null)
    
    if [ -z "$firewalls" ]; then
        log_message "No online firewalls found"
        exit 0
    fi
    
    # Process each firewall
    while IFS=$'\t' read -r firewall_id hostname; do
        log_message "Checking firewall: $hostname (ID: $firewall_id)"
        
        if check_firewall_updates "$firewall_id"; then
            log_message "Updates available for firewall: $hostname"
            trigger_firewall_update "$firewall_id" "$hostname"
        else
            log_message "No updates needed for firewall: $hostname"
        fi
        
        # Small delay between firewalls
        sleep 2
        
    done <<< "$firewalls"
    
    log_message "Automatic firewall update check completed"
}

# Run main function
main "$@"