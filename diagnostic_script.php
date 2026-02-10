<?php
header('Content-Type: text/plain');
echo '#!/bin/sh

echo "=== DIAGNOSTIC SCRIPT ==="
echo "Current time: `date`"
echo ""

echo "=== PROCESS CHECK ==="
echo "Looking for agent processes:"
ps aux | grep opnsense_agent | grep -v grep
echo ""

echo "=== AGENT FILE CHECK ==="
if [ -f "/usr/local/bin/opnsense_agent.sh" ]; then
    echo "Agent file exists: /usr/local/bin/opnsense_agent.sh"
    echo "File permissions: `ls -la /usr/local/bin/opnsense_agent.sh`"
    echo "First few lines:"
    head -5 /usr/local/bin/opnsense_agent.sh
else
    echo "ERROR: Agent file not found at /usr/local/bin/opnsense_agent.sh"
fi
echo ""

echo "=== LOG FILE CHECK ==="
if [ -f "/var/log/opnsense_agent.log" ]; then
    echo "Recent log entries:"
    tail -10 /var/log/opnsense_agent.log
else
    echo "No log file found"
fi
echo ""

echo "=== MANUAL AGENT TEST ==="
echo "Attempting to run agent manually:"
if [ -f "/usr/local/bin/opnsense_agent.sh" ]; then
    /usr/local/bin/opnsense_agent.sh checkin
else
    echo "Cannot test - agent file missing"
fi
echo ""

echo "=== NETWORK TEST ==="
echo "Testing connectivity to management server:"
fetch -o /dev/null -q https://opn.agit8or.net/agent_checkin.php && echo "Connectivity OK" || echo "Connectivity FAILED"
echo ""

echo "=== RESTART ATTEMPT ==="
echo "Attempting to restart agent:"
pkill -f opnsense_agent
sleep 2
if [ -f "/usr/local/bin/opnsense_agent.sh" ]; then
    nohup /usr/local/bin/opnsense_agent.sh &
    echo "Agent restart attempted"
    sleep 3
    echo "Agent processes after restart:"
    ps aux | grep opnsense_agent | grep -v grep
else
    echo "Cannot restart - agent file missing"
fi

echo "=== DIAGNOSTIC COMPLETE ==="
';
?>