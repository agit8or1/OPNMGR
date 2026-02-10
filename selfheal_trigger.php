<?php
// Web-based Self-Healing Trigger for OPNsense Agents
// Called by existing agents to download and execute self-healing script

require_once __DIR__ . '/inc/bootstrap_agent.php';

header('Content-Type: text/plain');

// Validate request
$hostname = $_GET['hostname'] ?? $_POST['hostname'] ?? '';
$current_version = $_GET['version'] ?? $_POST['version'] ?? '';

if (empty($hostname)) {
    http_response_code(400);
    echo "ERROR: hostname parameter required";
    exit;
}

// Log the self-healing request
$log_file = "/tmp/selfheal_requests.log";
$timestamp = date('Y-m-d H:i:s');
file_put_contents($log_file, "[$timestamp] Self-healing requested by $hostname (current version: $current_version)\n", FILE_APPEND | LOCK_EX);

// Generate the self-healing script for this specific firewall
$script_content = '#!/bin/bash

# OPNsense Agent Self-Healing Script - Dynamic Version
# Generated for: ' . $hostname . '
# Requested at: ' . $timestamp . '

VERSION="2.3.0"
SCRIPT_NAME="opnsense_agent_v2.3.sh"
BASE_URL="https://opn.agit8or.net"
LOG_FILE="/tmp/agent_selfheal_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $hostname) . '.log"

# Logging function
log() {
    echo "[$(date \'%Y-%m-%d %H:%M:%S\')] $1" | tee -a "$LOG_FILE"
}

log "=== Self-Healing Started for ' . $hostname . ' ==="
log "Current version reported: ' . $current_version . '"
log "Target version: $VERSION"

# Function to report status back to management server
report_status() {
    local status="$1"
    local details="$2"
    
    curl -k -X POST "${BASE_URL}/agent_selfheal_report.php" \
        -d "hostname=' . $hostname . '" \
        -d "status=$status" \
        -d "details=$details" \
        -d "version=$VERSION" \
        -d "timestamp=$(date)" \
        --connect-timeout 10 --max-time 30 >/dev/null 2>&1 || true
}

report_status "started" "Self-healing process initiated"

# Step 1: Complete agent process cleanup
log "Step 1: Comprehensive agent cleanup..."

# Kill all potential agent processes
pkill -f "opnsense_agent" 2>/dev/null || true
pkill -f "agent.*opnsense" 2>/dev/null || true
pkill -f "firewall.*agent" 2>/dev/null || true
sleep 3

# Force kill any remaining processes
pkill -9 -f "opnsense_agent" 2>/dev/null || true
pkill -9 -f "agent.*opnsense" 2>/dev/null || true
sleep 2

# Check for and terminate any remaining processes
REMAINING=$(ps aux | grep -E "(opnsense_agent|agent.*opnsense)" | grep -v grep | grep -v "$$" || true)
if [ -n "$REMAINING" ]; then
    log "WARNING: Found persistent processes:"
    echo "$REMAINING" | tee -a "$LOG_FILE"
    # Try to kill by PID
    echo "$REMAINING" | awk "{print \$2}" | xargs -r kill -9 2>/dev/null || true
fi

report_status "processes_cleaned" "Terminated all agent processes"

# Step 2: Remove all agent files and installations
log "Step 2: Removing all agent installations..."

# Find and remove all agent-related files
AGENT_FILES=$(find /usr/local /opt /root /tmp /home -name "*opnsense_agent*" -type f 2>/dev/null | grep -v "$LOG_FILE" || true)
if [ -n "$AGENT_FILES" ]; then
    log "Removing agent files:"
    echo "$AGENT_FILES" | tee -a "$LOG_FILE"
    echo "$AGENT_FILES" | xargs -r rm -f 2>/dev/null || true
fi

# Remove agent directories
find /usr/local /opt /root -name "*opnsense*agent*" -type d 2>/dev/null | xargs -r rm -rf 2>/dev/null || true

# Step 3: Clean all cron jobs thoroughly
log "Step 3: Comprehensive cron cleanup..."

# Backup current crontab
crontab -l > /tmp/crontab_backup_$(date +%s).txt 2>/dev/null || true

# Remove all agent-related cron jobs
if crontab -l 2>/dev/null | grep -E "(opnsense|agent)" >/dev/null; then
    log "Found agent-related cron jobs, removing..."
    crontab -l 2>/dev/null | grep -v -E "(opnsense|agent)" | crontab - 2>/dev/null || true
    log "Cron jobs cleaned"
else
    log "No agent cron jobs found"
fi

# Also check system-wide cron
for crondir in /etc/cron.d /etc/cron.hourly /etc/cron.daily /etc/cron.weekly /etc/cron.monthly; do
    if [ -d "$crondir" ]; then
        find "$crondir" -name "*opnsense*" -o -name "*agent*" | xargs -r rm -f 2>/dev/null || true
    fi
done

report_status "cron_cleaned" "Removed all agent cron jobs"

# Step 4: Check and clean startup scripts
log "Step 4: Cleaning startup scripts..."

# Remove from rc.local if present
if [ -f /etc/rc.local ]; then
    sed -i "/opnsense.*agent/d" /etc/rc.local 2>/dev/null || true
fi

# Check systemd services (if systemd is available)
if command -v systemctl >/dev/null 2>&1; then
    systemctl list-units --all | grep -E "(opnsense|agent)" | awk "{print \$1}" | while read service; do
        log "Disabling systemd service: $service"
        systemctl stop "$service" 2>/dev/null || true
        systemctl disable "$service" 2>/dev/null || true
    done
fi

# Check for BSD-style rc scripts
if [ -d /etc/rc.d ]; then
    find /etc/rc.d -name "*opnsense*" -o -name "*agent*" | xargs -r rm -f 2>/dev/null || true
fi

if [ -d /usr/local/etc/rc.d ]; then
    find /usr/local/etc/rc.d -name "*opnsense*" -o -name "*agent*" | xargs -r rm -f 2>/dev/null || true
fi

report_status "startup_cleaned" "Cleaned startup scripts"

# Step 4: Download new agent
log "Step 4: Downloading agent v$VERSION..."
INSTALL_DIR="/usr/local/bin"
AGENT_PATH="$INSTALL_DIR/$SCRIPT_NAME"
mkdir -p "$INSTALL_DIR"

if curl -k -L "${BASE_URL}/download_agent.php?version=$VERSION" -o "$AGENT_PATH.tmp" --connect-timeout 30 --max-time 120; then
    if [ -s "$AGENT_PATH.tmp" ]; then
        mv "$AGENT_PATH.tmp" "$AGENT_PATH"
        chmod +x "$AGENT_PATH"
        log "✓ Agent downloaded and installed"
        report_status "agent_installed" "Downloaded agent v$VERSION"
    else
        log "ERROR: Downloaded file is empty"
        report_status "download_failed" "Downloaded file is empty"
        exit 1
    fi
else
    log "ERROR: Failed to download agent"
    report_status "download_failed" "Could not download agent"
    exit 1
fi

# Step 5: Verify agent version
INSTALLED_VERSION=$(grep "VERSION=" "$AGENT_PATH" 2>/dev/null | head -1 | cut -d"\\"" -f2 || echo "unknown")
log "Installed version: $INSTALLED_VERSION"

if [ "$INSTALLED_VERSION" = "$VERSION" ]; then
    log "✓ Version verified"
    report_status "version_verified" "Agent v$VERSION verified"
else
    log "⚠ Version mismatch: expected $VERSION, got $INSTALLED_VERSION"
    report_status "version_mismatch" "Expected $VERSION, got $INSTALLED_VERSION"
fi

# Step 6: Set up cron job
log "Step 6: Setting up cron job..."
CRON_ENTRY="*/5 * * * * $AGENT_PATH >/dev/null 2>&1"
(crontab -l 2>/dev/null; echo "$CRON_ENTRY") | crontab -
log "✓ Cron job added"
report_status "cron_added" "Added cron job for v$VERSION"

# Step 7: Run immediate test
log "Step 7: Running immediate test..."
if timeout 60 "$AGENT_PATH" 2>&1 | tee -a "$LOG_FILE"; then
    log "✓ Agent test successful"
    report_status "test_success" "Agent executed successfully"
else
    log "⚠ Agent test completed with warnings"
    report_status "test_warning" "Agent execution had warnings"
fi

# Step 8: Force multiple checkins to ensure database update
log "Step 8: Forcing immediate checkins..."
for i in {1..3}; do
    log "Checkin attempt $i..."
    timeout 30 "$AGENT_PATH" >/dev/null 2>&1 || true
    sleep 10
done

# Step 9: Final verification and monitoring
log "Step 9: Final system verification..."

# Verify no old agents are running
sleep 5
FINAL_CHECK=$(ps aux | grep -E "(opnsense_agent)" | grep -v grep | grep -v "$$" || true)
if [ -n "$FINAL_CHECK" ]; then
    log "WARNING: Found agents still running after installation:"
    echo "$FINAL_CHECK" | tee -a "$LOG_FILE"
    # Try one more cleanup
    echo "$FINAL_CHECK" | awk "{print \$2}" | xargs -r kill -9 2>/dev/null || true
    report_status "final_cleanup" "Had to kill remaining processes"
else
    log "✓ No conflicting agents detected"
    report_status "verified_clean" "System verified clean of old agents"
fi

# Verify cron job
CRON_CHECK=$(crontab -l 2>/dev/null | grep "$AGENT_PATH" || true)
if [ -n "$CRON_CHECK" ]; then
    log "✓ Cron job verified: $CRON_CHECK"
    report_status "cron_verified" "Cron job properly installed"
else
    log "ERROR: Cron job not found"
    report_status "cron_missing" "Cron job installation failed"
fi

# Set up monitoring script to prevent conflicts
cat > /tmp/agent_monitor.sh << "EOF"
#!/bin/sh
# Agent conflict monitor - runs every minute
AGENT_COUNT=$(ps aux | grep -E "(opnsense_agent)" | grep -v grep | wc -l)
if [ "$AGENT_COUNT" -gt 1 ]; then
    logger "Multiple opnsense agents detected ($AGENT_COUNT), cleaning up"
    pkill -f "opnsense_agent"
    sleep 2
    # Restart only the correct one
    /usr/local/bin/opnsense_agent_v2.3.sh >/dev/null 2>&1 &
fi
EOF

chmod +x /tmp/agent_monitor.sh

# Add monitor to cron (runs every 5 minutes)
(crontab -l 2>/dev/null | grep -v "agent_monitor"; echo "*/5 * * * * /tmp/agent_monitor.sh >/dev/null 2>&1") | crontab -

log "✓ Agent conflict monitor installed"
report_status "monitor_installed" "Conflict monitoring system active"

log "=== Self-Healing Complete for ' . $hostname . ' ==="
report_status "completed" "Self-healing process completed successfully"

# Clean up
rm -f "$AGENT_PATH.tmp" 2>/dev/null || true
echo "$(date): Self-healing completed for ' . $hostname . '" > /tmp/selfheal_complete_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $hostname) . '.flag
';

// Output the script
echo $script_content;

// Also log this to database if possible
try {
    $stmt = db()->prepare("INSERT INTO agent_selfheal_log (hostname, status, details, agent_version) VALUES (?, ?, ?, ?)");
    $stmt->execute([$hostname, 'script_requested', "Self-healing script requested from web interface", $current_version]);
} catch(Exception $e) {
    // Continue even if database logging fails
    error_log("Failed to log self-healing request: " . $e->getMessage());
}
?>