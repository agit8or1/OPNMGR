#!/bin/sh
# Agent Update Script for FreeBSD/OPNsense
# This script safely updates the agent without killing itself

DOWNLOAD_URL="$1"
LOG_FILE="/tmp/agent_update.log"
AGENT_PATH="/usr/local/bin/tunnel_agent.sh"
BACKUP_PATH="/tmp/tunnel_agent.sh.backup"

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S'): $1" | tee -a "$LOG_FILE"
}

log "=== Agent Update Started ==="
log "Download URL: $DOWNLOAD_URL"

# Validate download URL
if [ -z "$DOWNLOAD_URL" ]; then
    log "ERROR: No download URL provided"
    exit 1
fi

# Step 1: Backup current agent
log "Step 1: Backing up current agent..."
if [ -f "$AGENT_PATH" ]; then
    cp "$AGENT_PATH" "$BACKUP_PATH"
    log "✓ Backup created: $BACKUP_PATH"
else
    log "WARNING: No existing agent found at $AGENT_PATH"
fi

# Step 2: Download new agent
log "Step 2: Downloading new agent..."
NEW_AGENT="/tmp/tunnel_agent_new.sh"
fetch -q -o "$NEW_AGENT" "$DOWNLOAD_URL" 2>&1 | tee -a "$LOG_FILE"

if [ ! -f "$NEW_AGENT" ]; then
    log "ERROR: Failed to download new agent"
    exit 1
fi

# Verify it's a valid shell script
if ! head -1 "$NEW_AGENT" | grep -q "^#!"; then
    log "ERROR: Downloaded file is not a valid shell script"
    rm -f "$NEW_AGENT"
    exit 1
fi

log "✓ New agent downloaded successfully"

# Step 3: Get new version
NEW_VERSION=$(grep 'AGENT_VERSION=' "$NEW_AGENT" | head -1 | cut -d'"' -f2)
log "New version: $NEW_VERSION"

# Step 4: Install new agent
log "Step 3: Installing new agent..."
mv "$NEW_AGENT" "$AGENT_PATH"
chmod +x "$AGENT_PATH"
log "✓ New agent installed"

# Step 5: Preserve firewall ID if it exists
if [ -f "/tmp/opnsense_firewall_id" ]; then
    FIREWALL_ID=$(cat /tmp/opnsense_firewall_id)
    log "Preserved firewall ID: $FIREWALL_ID"
fi

# Step 6: Verify cron job exists
log "Step 4: Verifying cron job..."
if crontab -l 2>/dev/null | grep -q "tunnel_agent.sh"; then
    log "✓ Cron job exists"
else
    log "WARNING: Cron job not found, creating..."
    (crontab -l 2>/dev/null; echo "*/2 * * * * /usr/local/bin/tunnel_agent.sh") | crontab -
    log "✓ Cron job created"
fi

# Step 7: Run new agent immediately (in background)
log "Step 5: Starting new agent..."
nohup "$AGENT_PATH" > /tmp/agent_first_run_after_update.log 2>&1 &
sleep 2

log "=== Agent Update Complete ==="
log "Updated to version: $NEW_VERSION"
log "Agent will check in within 2 minutes"
log "Backup available at: $BACKUP_PATH"
log "Update log: $LOG_FILE"

exit 0
