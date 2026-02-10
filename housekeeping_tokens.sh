#!/bin/bash

# OPNsense Manager Housekeeping Script
# This script cleans up expired tokens and old logs

LOG_FILE="/var/log/opnsense_manager_housekeeping.log"

log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" >> "$LOG_FILE"
}

log_message "Starting housekeeping tasks..."

# Clean up expired enrollment tokens
mysql -u opnsense_user -p'password' opnsense_fw -e "
DELETE FROM enrollment_tokens WHERE expires_at < NOW();
" 2>/dev/null

DELETED_TOKENS=$?
if [ $DELETED_TOKENS -eq 0 ]; then
    log_message "Cleaned up expired enrollment tokens"
else
    log_message "Error cleaning up tokens"
fi

# Clean up old logs (keep 30 days)
find /var/log -name "*.log" -type f -mtime +30 -delete 2>/dev/null
log_message "Cleaned up old log files"

# Rotate our own log file if it gets too large (>10MB)
if [ -f "$LOG_FILE" ] && [ $(stat -f%z "$LOG_FILE" 2>/dev/null || stat -c%s "$LOG_FILE") -gt 10485760 ]; then
    mv "$LOG_FILE" "${LOG_FILE}.old"
    log_message "Rotated housekeeping log file"
fi

log_message "Housekeeping tasks completed"