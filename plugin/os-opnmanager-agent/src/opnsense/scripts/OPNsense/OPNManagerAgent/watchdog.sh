#!/bin/sh

# OPNManager Agent Watchdog
# Ensures the agent service stays running and restarts it if crashed.
#
# The installer schedules this in root's crontab every 5 minutes. It used to say
# so only in this comment, and nothing ever read the comment: the watchdog was
# copied onto every firewall and never once ran, so an agent that died stayed
# dead until someone reached the console. daemon(8) now supervises the agent as
# well, which covers a process that exits; this covers a process that is alive
# but no longer checking in.

PIDFILE="/var/run/opnmanager_agent.pid"
SUPERVISOR_PIDFILE="/var/run/opnmanager_agent.supervisor.pid"
LOG_FILE="/var/log/opnmanager_agent.log"
CONFIG_FILE="/conf/config.xml"
MAX_LOG_SIZE=10485760  # 10MB

PATH=/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin
export PATH

# Rotate log if too large
if [ -f "$LOG_FILE" ]; then
    size=$(stat -f%z "$LOG_FILE" 2>/dev/null || echo 0)
    if [ "$size" -gt "$MAX_LOG_SIZE" ]; then
        mv "$LOG_FILE" "$LOG_FILE.old" 2>/dev/null
        touch "$LOG_FILE"
        chmod 600 "$LOG_FILE"
    fi
fi

log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') [WATCHDOG] $1" >> "$LOG_FILE"
}

# Check if service is enabled
if ! grep -q 'opnmanager_agent_enable="YES"' /etc/rc.conf 2>/dev/null; then
    # Service disabled, nothing to do
    exit 0
fi

# An agent that is disabled or unconfigured in the GUI is not a fault: it waits
# on purpose and logs no check-ins, so silence must not be read as stuck.
enabled=$(grep -o '<enabled>[^<]*</enabled>' "$CONFIG_FILE" 2>/dev/null | head -1 \
          | sed 's|<enabled>||;s|</enabled>||')
if [ "$enabled" = "0" ]; then
    exit 0
fi

restart_agent() {
    log_message "$1"
    /usr/sbin/service opnmanager_agent restart >/dev/null 2>&1
    sleep 3
    if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
        log_message "Agent restarted (PID $(cat "$PIDFILE"))"
    else
        log_message "ERROR: Agent failed to start properly"
    fi
    exit 0
}

# The supervisor is what makes a crash recoverable, so its absence is itself a
# fault even when the agent process happens to be alive.
if [ ! -f "$SUPERVISOR_PIDFILE" ] || ! kill -0 "$(cat "$SUPERVISOR_PIDFILE" 2>/dev/null)" 2>/dev/null; then
    restart_agent "Agent supervisor not running, restarting service..."
fi

if [ ! -f "$PIDFILE" ] || ! kill -0 "$(cat "$PIDFILE" 2>/dev/null)" 2>/dev/null; then
    restart_agent "Agent not running, restarting service..."
fi

# The agent is alive. Decide whether it is still working by the age of its last
# successful check-in, not by whether one appears in the last 20 log lines: a
# busy agent can push its last success out of that window while perfectly
# healthy, and this now runs every five minutes, so a false verdict would mean
# restarting a working agent forever.
last_ok=$(grep 'Check-in successful' "$LOG_FILE" 2>/dev/null | tail -1 | cut -c1-19)
if [ -z "$last_ok" ]; then
    # Nothing to compare against yet - a freshly started agent has not had time
    # to check in. Leave it alone; the next run will have a timestamp.
    exit 0
fi

last_epoch=$(date -j -f '%Y-%m-%d %H:%M:%S' "$last_ok" '+%s' 2>/dev/null)
[ -z "$last_epoch" ] && exit 0
age=$(( $(date '+%s') - last_epoch ))

# Threshold: five check-in intervals, and never less than 15 minutes. The agent
# backs off up to 300s per check-in on repeated errors, so a firewall that has
# merely lost its uplink must not be restarted in a loop.
interval=$(grep -o '<checkinInterval>[^<]*</checkinInterval>' "$CONFIG_FILE" 2>/dev/null | head -1 \
           | sed 's|<checkinInterval>||;s|</checkinInterval>||')
case "$interval" in ''|*[!0-9]*) interval=120 ;; esac
threshold=$(( interval * 5 ))
[ "$threshold" -lt 900 ] && threshold=900

if [ "$age" -gt "$threshold" ]; then
    restart_agent "No successful check-in for ${age}s (threshold ${threshold}s), restarting service..."
fi

exit 0
