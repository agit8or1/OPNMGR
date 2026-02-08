<?php
header('Content-Type: text/plain');
?>#!/bin/sh

echo "=== AGENT DIAGNOSTIC ==="
echo "Time: `date`"
echo ""

echo "1. Agent file check:"
if [ -f "/usr/local/bin/opnsense_agent.sh" ]; then
    echo "✅ Agent exists: `ls -la /usr/local/bin/opnsense_agent.sh`"
else
    echo "❌ Agent missing"
fi

echo ""
echo "2. Firewall ID check:"
if [ -f "/usr/local/etc/opnsense_firewall_id" ]; then
    echo "✅ ID file exists: `cat /usr/local/etc/opnsense_firewall_id`"
else
    echo "❌ ID file missing"
fi

echo ""
echo "3. Network connectivity:"
WAN_TEST=`fetch -q -o - -T 5 http://ipv4.icanhazip.com 2>/dev/null`
if [ -n "$WAN_TEST" ]; then
    echo "✅ Internet: $WAN_TEST"
else
    echo "❌ Internet failed"
fi

SERVER_TEST=`fetch -q -o - -T 5 https://opn.agit8or.net/test.php 2>/dev/null`
if [ -n "$SERVER_TEST" ]; then
    echo "✅ Management server: OK"
else
    echo "❌ Management server failed"
fi

echo ""
echo "4. Manual agent test:"
/usr/local/bin/opnsense_agent.sh test 2>&1

echo ""
echo "5. Manual checkin test:"
/usr/local/bin/opnsense_agent.sh checkin 2>&1

echo ""
echo "6. Cron status:"
crontab -l | grep opnsense_agent

echo ""
echo "7. Process check:"
ps aux | grep opnsense_agent | grep -v grep

echo ""
echo "=== DIAGNOSTIC COMPLETE ==="