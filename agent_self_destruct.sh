#!/bin/sh
# This script will be downloaded and executed by ALL agents
# It makes each agent check if it's a duplicate and kill itself

PIDFILE="/var/run/opnsense_agent.pid"

# Get my own PID
MY_PID=$$

# Check if PID file exists
if [ -f "$PIDFILE" ]; then
    LOCK_PID=$(cat "$PIDFILE")
    
    # If PID file contains a DIFFERENT PID that's still alive, I'm a duplicate - KILL MYSELF
    if [ "$LOCK_PID" != "$MY_PID" ] && ps -p "$LOCK_PID" > /dev/null 2>&1; then
        echo "I am PID $MY_PID - duplicate of $LOCK_PID - killing myself"
        kill -9 $$
        exit 1
    fi
else
    # No PID file exists, I'm the first one - claim it
    echo $$ > "$PIDFILE"
fi

echo "I am PID $MY_PID - I am the primary agent"
