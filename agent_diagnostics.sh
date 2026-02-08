#!/bin/bash

# OPNsense Agent Diagnostics
# Run this on your OPNsense firewall to diagnose agent issues

echo "=== OPNsense Agent Diagnostics ==="
echo "Current time: $(date)"
echo ""

echo "1. Checking if agent script exists..."
if [ -f /usr/local/bin/opnsense_agent_v2.sh ]; then
    echo "✓ Agent script found at /usr/local/bin/opnsense_agent_v2.sh"
    ls -la /usr/local/bin/opnsense_agent_v2.sh
else
    echo "✗ Agent script NOT found at /usr/local/bin/opnsense_agent_v2.sh"
fi
echo ""

echo "2. Checking cron jobs..."
if crontab -l 2>/dev/null | grep -q opnsense_agent; then
    echo "✓ Cron job found:"
    crontab -l | grep opnsense_agent
else
    echo "✗ No cron job found for opnsense_agent"
fi
echo ""

echo "3. Checking agent config..."
if [ -f /usr/local/etc/opnsense_agent.conf ]; then
    echo "✓ Config file found:"
    cat /usr/local/etc/opnsense_agent.conf
else
    echo "✗ Config file NOT found at /usr/local/etc/opnsense_agent.conf"
fi
echo ""

echo "4. Checking agent log..."
if [ -f /var/log/opnsense_agent.log ]; then
    echo "✓ Log file found, last 10 entries:"
    tail -10 /var/log/opnsense_agent.log
else
    echo "✗ Log file NOT found at /var/log/opnsense_agent.log"
fi
echo ""

echo "5. Testing manual agent run..."
if [ -f /usr/local/bin/opnsense_agent_v2.sh ]; then
    echo "Running agent manually..."
    /usr/local/bin/opnsense_agent_v2.sh checkin
    echo "Manual run completed"
else
    echo "Cannot test - agent script not found"
fi
echo ""

echo "6. Checking network connectivity..."
if ping -c 1 opn.agit8or.net >/dev/null 2>&1; then
    echo "✓ Can ping opn.agit8or.net"
else
    echo "✗ Cannot ping opn.agit8or.net"
fi

if fetch -qo /dev/null --timeout=5 https://opn.agit8or.net 2>/dev/null; then
    echo "✓ Can connect to https://opn.agit8or.net"
else
    echo "✗ Cannot connect to https://opn.agit8or.net"
fi
echo ""

echo "=== Diagnostics Complete ==="
echo "If cron job is missing, run: /usr/local/bin/opnsense_agent_v2.sh install"