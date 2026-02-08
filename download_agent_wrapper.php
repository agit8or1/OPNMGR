<?php
// This file can be downloaded by agents to get a PID-safe wrapper

header('Content-Type: text/plain');
header('Content-Disposition: attachment; filename="opnsense_agent_wrapper.sh"');

echo <<<'WRAPPER'
#!/bin/sh
# OPNsense Agent PID-Safe Wrapper
# Prevents multiple instances from running

PIDFILE="/var/run/opnsense_agent.pid"
AGENT_SCRIPT="/usr/local/bin/opnsense_agent.sh"

# Check if another instance is running
if [ -f "$PIDFILE" ]; then
    OLD_PID=$(cat "$PIDFILE")
    # Check if process with that PID exists and is our agent
    if ps -p "$OLD_PID" -o command= 2>/dev/null | grep -q "opnsense_agent"; then
        # Agent already running, exit silently
        exit 0
    fi
    # Stale PID file, remove it
    rm -f "$PIDFILE"
fi

# Create PID file with our PID
echo $$ > "$PIDFILE"

# Ensure PID file is removed on exit
trap 'rm -f "$PIDFILE"' EXIT INT TERM

# Execute the actual agent
exec "$AGENT_SCRIPT"
WRAPPER;
