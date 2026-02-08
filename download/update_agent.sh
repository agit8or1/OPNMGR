#!/bin/sh
# Agent Update Script for FreeBSD/OPNsense
# This script handles the update process safely and cleans up old artifacts

DOWNLOAD_URL="$1"
LOG_FILE="/tmp/agent_update.log"
AGENT_PATH="/usr/local/bin/tunnel_agent.sh"

log() {
    echo "$(date): $1" | tee -a "$LOG_FILE"
}

log "Starting agent update process with cleanup..."
log "Download URL: $DOWNLOAD_URL"

# Ensure we have a download URL
if [ -z "$DOWNLOAD_URL" ]; then
    log "ERROR: No download URL provided"
    exit 1
fi

# Wait for current checkin to complete
log "Waiting for current operations to complete..."
sleep 5

# COMPREHENSIVE CLEANUP - Remove all old agent artifacts
log "Cleaning up old agent artifacts..."

# Kill ALL possible agent processes (old versions)
pkill -f tunnel_agent 2>/dev/null
pkill -f opnsense_agent 2>/dev/null  
pkill -f agent_checkin 2>/dev/null
pkill -f "socat.*:81" 2>/dev/null

# Wait for graceful shutdown
sleep 3

# Force kill any remaining processes
ps aux | grep -E "(tunnel_agent|opnsense_agent)" | grep -v grep | awk '{print $2}' | xargs kill -9 2>/dev/null

# Remove old agent files and artifacts
log "Removing old agent files..."
rm -f /tmp/tunnel_agent* /tmp/opnsense_agent* /tmp/*agent* 2>/dev/null
rm -f /usr/local/bin/opnsense_agent* 2>/dev/null
rm -f /usr/local/opnsense_agent/* 2>/dev/null
rmdir /usr/local/opnsense_agent 2>/dev/null

# Clean up any old cron jobs
crontab -l 2>/dev/null | grep -v -E "(tunnel_agent|opnsense_agent)" | crontab - 2>/dev/null

# Download new agent with better error handling
log "Downloading new agent..."
if ! fetch -q -o /tmp/tunnel_agent_new.sh "$DOWNLOAD_URL"; then
    log "ERROR: Failed to download new agent from $DOWNLOAD_URL"
    exit 1
fi

# Verify download
if [ ! -f /tmp/tunnel_agent_new.sh ] || [ ! -s /tmp/tunnel_agent_new.sh ]; then
    log "ERROR: Downloaded file is empty or missing"
    rm -f /tmp/tunnel_agent_new.sh
    exit 1
fi

# Check if it's a valid shell script
if ! head -1 /tmp/tunnel_agent_new.sh | grep -q "^#!/bin/sh"; then
    log "ERROR: Downloaded file is not a valid shell script"
    rm -f /tmp/tunnel_agent_new.sh
    exit 1
fi

log "Download completed and verified"
chmod +x /tmp/tunnel_agent_new.sh

# Create directory if it doesn't exist
mkdir -p /usr/local/bin

# Replace the agent file
log "Installing new agent..."
if cp /tmp/tunnel_agent_new.sh "$AGENT_PATH"; then
    chmod +x "$AGENT_PATH"
    log "Agent file installed successfully"
else
    log "ERROR: Failed to install agent file"
    rm -f /tmp/tunnel_agent_new.sh
    exit 1
fi

# Clean up temporary file
rm -f /tmp/tunnel_agent_new.sh

# Start new agent
log "Starting new agent..."
if nohup "$AGENT_PATH" > /tmp/agent_main.log 2>&1 &; then
    NEW_PID=$!
    log "New agent started with PID: $NEW_PID"
    
    # Verify it's actually running
    sleep 3
    if kill -0 "$NEW_PID" 2>/dev/null; then
        log "Agent update completed successfully"
        log "Cleanup removed all old artifacts"
        exit 0
    else
        log "ERROR: New agent failed to start properly"
        exit 1
    fi
else
    log "ERROR: Failed to start new agent"
    exit 1
fi