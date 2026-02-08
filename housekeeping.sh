#!/bin/bash

# OPNsense Manager Housekeeping Script
# This script performs regular maintenance tasks

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="/var/log/opnsense_housekeeping.log"
MYSQL_USER="root"
MYSQL_PASS="opnsense123"
MYSQL_DB="opnsense_fw"

# Function to log messages
log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_FILE"
}

log_message "=== Starting OPNsense Manager Housekeeping ==="

# 1. Clean up old system logs (older than 30 days)
log_message "Cleaning up old system logs..."
DELETED_LOGS=$(mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DB" -s -N -e "
    SELECT COUNT(*) FROM system_logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL 30 DAY);
")

mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DB" -e "
    DELETE FROM system_logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL 30 DAY);
" 2>/dev/null

if [ $? -eq 0 ]; then
    log_message "Cleaned up $DELETED_LOGS old log entries"
    
    # Log this action
    mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DB" -e "
        INSERT INTO system_logs (level, category, message, additional_data) 
        VALUES ('INFO', 'housekeeping', 'Automated cleanup removed old log entries', '{\"deleted_count\": $DELETED_LOGS}');
    " 2>/dev/null
else
    log_message "ERROR: Failed to clean up old logs"
fi

# 2. Clean up old enrollment tokens (older than 48 hours)
log_message "Cleaning up old enrollment tokens..."
DELETED_TOKENS=$(mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DB" -s -N -e "
    SELECT COUNT(*) FROM enrollment_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 48 HOUR);
")

mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DB" -e "
    DELETE FROM enrollment_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 48 HOUR);
" 2>/dev/null

if [ $? -eq 0 ]; then
    log_message "Cleaned up $DELETED_TOKENS expired enrollment tokens"
else
    log_message "ERROR: Failed to clean up enrollment tokens"
fi

# 3. Update firewall status based on last checkin
log_message "Updating firewall status..."
mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DB" -e "
    UPDATE firewalls 
    SET status = CASE 
        WHEN last_checkin IS NULL THEN 'unknown'
        WHEN last_checkin < DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 'offline'
        ELSE 'online'
    END
    WHERE status != CASE 
        WHEN last_checkin IS NULL THEN 'unknown'
        WHEN last_checkin < DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 'offline'
        ELSE 'online'
    END;
" 2>/dev/null

if [ $? -eq 0 ]; then
    log_message "Updated firewall status based on last checkin"
else
    log_message "ERROR: Failed to update firewall status"
fi

# 4. Optimize database tables
log_message "Optimizing database tables..."
for table in firewalls system_logs enrollment_tokens firewall_agents firewall_tags tags customers; do
    mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DB" -e "OPTIMIZE TABLE $table;" 2>/dev/null
    if [ $? -eq 0 ]; then
        log_message "Optimized table: $table"
    else
        log_message "WARNING: Failed to optimize table: $table"
    fi
done

# 5. Check disk space and warn if low
log_message "Checking disk space..."
DISK_USAGE=$(df / | awk 'NR==2 {print $5}' | sed 's/%//')
if [ "$DISK_USAGE" -gt 80 ]; then
    log_message "WARNING: Disk usage is high: ${DISK_USAGE}%"
    
    # Log high disk usage warning
    mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DB" -e "
        INSERT INTO system_logs (level, category, message, additional_data) 
        VALUES ('WARNING', 'housekeeping', 'High disk usage detected', '{\"disk_usage\": $DISK_USAGE}');
    " 2>/dev/null
else
    log_message "Disk usage is normal: ${DISK_USAGE}%"
fi

# 6. Archive old agent checkin data (older than 90 days)
log_message "Archiving old agent checkin data..."
OLD_CHECKINS=$(mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DB" -s -N -e "
    SELECT COUNT(*) FROM firewall_agents WHERE last_checkin < DATE_SUB(NOW(), INTERVAL 90 DAY);
")

if [ "$OLD_CHECKINS" -gt 0 ]; then
    # Create backup before deletion
    BACKUP_FILE="/var/backups/firewall_agents_$(date +%Y%m%d_%H%M%S).sql"
    mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DB" -e "
        SELECT * FROM firewall_agents WHERE last_checkin < DATE_SUB(NOW(), INTERVAL 90 DAY);
    " > "$BACKUP_FILE" 2>/dev/null
    
    mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DB" -e "
        DELETE FROM firewall_agents WHERE last_checkin < DATE_SUB(NOW(), INTERVAL 90 DAY);
    " 2>/dev/null
    
    if [ $? -eq 0 ]; then
        log_message "Archived $OLD_CHECKINS old agent checkin records to $BACKUP_FILE"
    else
        log_message "ERROR: Failed to archive old agent checkin data"
    fi
else
    log_message "No old agent checkin data to archive"
fi

# 7. Generate daily statistics
log_message "Generating daily statistics..."
TOTAL_FIREWALLS=$(mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DB" -s -N -e "SELECT COUNT(*) FROM firewalls;")
ONLINE_FIREWALLS=$(mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DB" -s -N -e "SELECT COUNT(*) FROM firewalls WHERE status = 'online';")
OFFLINE_FIREWALLS=$(mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DB" -s -N -e "SELECT COUNT(*) FROM firewalls WHERE status = 'offline';")

log_message "Daily Statistics: Total: $TOTAL_FIREWALLS, Online: $ONLINE_FIREWALLS, Offline: $OFFLINE_FIREWALLS"

# Log daily statistics
mysql -u "$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DB" -e "
    INSERT INTO system_logs (level, category, message, additional_data) 
    VALUES ('INFO', 'housekeeping', 'Daily statistics generated', '{\"total_firewalls\": $TOTAL_FIREWALLS, \"online_firewalls\": $ONLINE_FIREWALLS, \"offline_firewalls\": $OFFLINE_FIREWALLS}');
" 2>/dev/null

log_message "=== Housekeeping completed successfully ==="

# Rotate log file if it gets too large (>10MB)
if [ -f "$LOG_FILE" ] && [ $(stat -f%z "$LOG_FILE" 2>/dev/null || stat -c%s "$LOG_FILE" 2>/dev/null || echo 0) -gt 10485760 ]; then
    mv "$LOG_FILE" "${LOG_FILE}.old"
    log_message "Rotated housekeeping log file"
fi