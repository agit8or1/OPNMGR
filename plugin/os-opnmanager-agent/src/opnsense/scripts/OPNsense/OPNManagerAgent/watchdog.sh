#!/bin/sh

# OPNManager Agent Watchdog
# Ensures the agent service stays running and restarts it if crashed
# Run this from cron every 5 minutes: */5 * * * * /usr/local/opnsense/scripts/OPNsense/OPNManagerAgent/watchdog.sh

PIDFILE="/var/run/opnmanager_agent.pid"
LOG_FILE="/var/log/opnmanager_agent.log"
MAX_LOG_SIZE=10485760  # 10MB

# Rotate log if too large
if [ -f "$LOG_FILE" ]; then
    size=$(stat -f%z "$LOG_FILE" 2>/dev/null || echo 0)
    if [ "$size" -gt "$MAX_LOG_SIZE" ]; then
        mv "$LOG_FILE" "$LOG_FILE.old" 2>/dev/null
        touch "$LOG_FILE"
        chmod 600 "$LOG_FILE"
    fi
fi

# Function to log messages
log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') [WATCHDOG] $1" >> "$LOG_FILE"
}

# Check if service is enabled
if ! grep -q 'opnmanager_agent_enable="YES"' /etc/rc.conf 2>/dev/null; then
    # Service disabled, nothing to do
    exit 0
fi

# Check if agent is running
if [ -f "$PIDFILE" ]; then
    pid=$(cat "$PIDFILE")
    if kill -0 "$pid" 2>/dev/null; then
        # Agent is running, check if it's responding
        # Look for recent check-in in log (within last 5 minutes)
        if [ -f "$LOG_FILE" ]; then
            recent=$(tail -20 "$LOG_FILE" 2>/dev/null | grep -c "Check-in successful")
            if [ "$recent" -gt 0 ]; then
                # Agent is healthy
                exit 0
            fi
        fi
        # Agent running but not checking in - might be stuck
        log_message "Agent appears stuck (PID $pid), restarting..."
        /usr/sbin/service opnmanager_agent restart
        exit 0
    fi
fi

# Agent not running - restart it
log_message "Agent not running, starting service..."
/usr/sbin/service opnmanager_agent start

# Give it a moment to start
sleep 3

# Verify it started
if [ -f "$PIDFILE" ]; then
    pid=$(cat "$PIDFILE")
    if kill -0 "$pid" 2>/dev/null; then
        log_message "Agent successfully restarted (PID $pid)"
    else
        log_message "ERROR: Agent failed to start properly"
    fi
else
    log_message "ERROR: Agent start failed - no PID file created"
fi

exit 0
