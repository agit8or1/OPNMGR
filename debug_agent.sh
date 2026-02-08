#!/bin/sh

echo "=== OPNsense Agent Debugging ==="
echo "Current time: $(date)"
echo ""

if [ -f "/usr/local/bin/opnsense_agent_v2.sh" ]; then
    echo "Agent script found at /usr/local/bin/opnsense_agent_v2.sh"
else
    echo "Agent script NOT found"
fi

if [ -f "/usr/local/etc/opnsense_agent.conf" ]; then
    echo "Config file found:"
    cat /usr/local/etc/opnsense_agent.conf
    echo ""
else
    echo "Config file NOT found"
fi

echo "Checking cron jobs:"
crontab -l 2>/dev/null | grep opnsense || echo "No opnsense cron jobs found"
echo ""

if [ -f "/var/log/opnsense_agent.log" ]; then
    echo "Agent log found. Last 10 lines:"
    tail -10 /var/log/opnsense_agent.log
else
    echo "Agent log NOT found"
fi
echo ""

echo "Testing agent execution manually..."
if [ -f "/usr/local/bin/opnsense_agent_v2.sh" ]; then
    echo "Running agent manually:"
    /usr/local/bin/opnsense_agent_v2.sh 2>&1 | head -20
else
    echo "Cannot test - agent script not found"
fi

echo ""
echo "=== Debug Complete ==="
