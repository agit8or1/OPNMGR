<?php
header('Content-Type: text/plain');
echo '#!/bin/sh

echo "=== COMPREHENSIVE FIX SCRIPT ==="
echo "Current time: `date`"

echo "Step 1: Creating firewall ID file..."
echo "21" > /usr/local/etc/opnsense_firewall_id
echo "Firewall ID file created: `cat /usr/local/etc/opnsense_firewall_id`"

echo "Step 2: Testing network connectivity..."
fetch -q -o /tmp/test_response.txt --method=POST \
  --header="Content-Type: application/json" \
  --data="{\"test\": \"connectivity\"}" \
  https://opn.agit8or.net/agent_checkin.php
if [ $? -eq 0 ]; then
    echo "Network POST test: SUCCESS"
else
    echo "Network POST test: FAILED"
fi

echo "Step 3: Downloading working agent..."
fetch -q -o /tmp/working_agent.sh https://opn.agit8or.net/download_agent.php
if [ -f "/tmp/working_agent.sh" ]; then
    echo "Agent download: SUCCESS"
    echo "Agent size: `wc -c < /tmp/working_agent.sh` bytes"
else
    echo "Agent download: FAILED"
    exit 1
fi

echo "Step 4: Installing working agent..."
chmod +x /tmp/working_agent.sh
cp /tmp/working_agent.sh /usr/local/bin/opnsense_agent.sh
chmod +x /usr/local/bin/opnsense_agent.sh

echo "Step 5: Stopping any existing agents..."
pkill -f opnsense_agent
sleep 3

echo "Step 6: Testing agent manually..."
/usr/local/bin/opnsense_agent.sh checkin
if [ $? -eq 0 ]; then
    echo "Manual agent test: SUCCESS"
else
    echo "Manual agent test: FAILED"
fi

echo "Step 7: Starting agent in background..."
nohup /usr/local/bin/opnsense_agent.sh &
sleep 3

echo "Step 8: Verifying agent is running..."
if [ "`pgrep -f opnsense_agent`" != "" ]; then
    echo "Agent process: RUNNING (PID: `pgrep -f opnsense_agent`)"
else
    echo "Agent process: NOT RUNNING"
fi

echo "Step 9: Final connectivity test..."
/usr/local/bin/opnsense_agent.sh checkin
echo "Final test completed"

echo "=== FIX COMPLETE ==="
echo "Agent should now be working properly"
';
?>