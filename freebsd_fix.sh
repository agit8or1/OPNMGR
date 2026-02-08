#!/bin/sh

echo "=== Emergency Agent Fix ==="
echo "Starting at `date`"

if [ -f "/usr/local/bin/opnsense_agent.sh" ]; then
    echo "Backing up current agent..."
    cp /usr/local/bin/opnsense_agent.sh /tmp/opnsense_agent.sh.bak.`date +%s`
fi

echo "Downloading fixed agent..."
fetch -q -o /tmp/opnsense_agent_fixed.sh https://opn.agit8or.net/complete_agent_v4.sh

if [ ! -f "/tmp/opnsense_agent_fixed.sh" ]; then
    echo "ERROR: Could not download fixed agent"
    exit 1
fi

chmod +x /tmp/opnsense_agent_fixed.sh

echo "Stopping current agent..."
pkill -f opnsense_agent
sleep 2

echo "Installing fixed agent..."
cp /tmp/opnsense_agent_fixed.sh /usr/local/bin/opnsense_agent.sh
chmod +x /usr/local/bin/opnsense_agent.sh

echo "Starting fixed agent..."
nohup /usr/local/bin/opnsense_agent.sh &

sleep 3
if [ "`pgrep -f opnsense_agent`" != "" ]; then
    echo "SUCCESS: Fixed agent is running"
    echo "The agent should now process the pending reboot command"
    echo "Firewall will reboot within 2 minutes to restart services"
else
    echo "ERROR: Agent may not be running"
fi

echo "Testing agent checkin..."
/usr/local/bin/opnsense_agent.sh checkin

echo "=== Fix Complete ==="
echo "Agent has been replaced. System will reboot automatically."