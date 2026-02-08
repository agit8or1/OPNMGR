#!/bin/sh

# Queue Cleanup Script for OPNsense Request Management
# Runs automatic cleanup of old request queue entries

SCRIPT_DIR=$(dirname "$0")
LOG_FILE="/var/log/opnmgr/queue_cleanup.log"

# Ensure log directory exists
mkdir -p "$(dirname "$LOG_FILE")"

# Function to log messages
log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') [QUEUE_CLEANUP] $1" | tee -a "$LOG_FILE"
}

log_message "Starting queue cleanup process"

# Call the cleanup API
CLEANUP_RESULT=$(curl -s "http://localhost/api/queue_management.php?action=cleanup_old_requests" 2>/dev/null)

if [ $? -eq 0 ]; then
    # Parse the JSON response (basic parsing for shell)
    if echo "$CLEANUP_RESULT" | grep -q '"success":true'; then
        # Extract counts from JSON
        TOTAL_CLEANED=$(echo "$CLEANUP_RESULT" | sed -n 's/.*"total_cleaned":\([0-9]*\).*/\1/p')
        TIMED_OUT=$(echo "$CLEANUP_RESULT" | sed -n 's/.*"timed_out":\([0-9]*\).*/\1/p')
        COMPLETED_REMOVED=$(echo "$CLEANUP_RESULT" | sed -n 's/.*"completed_removed":\([0-9]*\).*/\1/p')
        FAILED_REMOVED=$(echo "$CLEANUP_RESULT" | sed -n 's/.*"failed_removed":\([0-9]*\).*/\1/p')
        
        log_message "Cleanup successful: $TOTAL_CLEANED total ($TIMED_OUT timed out, $COMPLETED_REMOVED completed, $FAILED_REMOVED failed)"
    else
        log_message "Cleanup API returned error: $CLEANUP_RESULT"
    fi
else
    log_message "Failed to connect to cleanup API"
fi

# Also check current queue status and log it
QUEUE_STATUS=$(curl -s "http://localhost/api/queue_management.php?action=get_queue_status&firewall_id=21" 2>/dev/null)

if [ $? -eq 0 ] && echo "$QUEUE_STATUS" | grep -q '"success":true'; then
    # Count pending and processing requests
    PENDING_COUNT=$(echo "$QUEUE_STATUS" | grep -o '"status":"pending"' | wc -l)
    PROCESSING_COUNT=$(echo "$QUEUE_STATUS" | grep -o '"status":"processing"' | wc -l)
    
    if [ "$PENDING_COUNT" -gt 0 ] || [ "$PROCESSING_COUNT" -gt 0 ]; then
        log_message "Current queue status: $PENDING_COUNT pending, $PROCESSING_COUNT processing"
        
        # If there are many pending requests, it might indicate a problem
        if [ "$PENDING_COUNT" -gt 50 ]; then
            log_message "WARNING: High number of pending requests ($PENDING_COUNT) - agent may not be processing requests"
        fi
    fi
fi

log_message "Queue cleanup process completed"