#!/bin/sh
# This script overwrites the agent to make it exit immediately
cat > /usr/local/bin/opnsense_agent.sh << 'ENDAGENT'
#!/bin/sh
# Agent disabled - exiting
exit 0
ENDAGENT
chmod +x /usr/local/bin/opnsense_agent.sh
echo "Agent script replaced with exit-only version"

# Kill any running instances
pkill -9 -f opnsense_agent
sleep 2

# Download new v3.0 agent
curl -k -s -o /usr/local/bin/opnsense_agent.sh 'https://opn.agit8or.net/download_new_agent.php?firewall_id=21'
chmod +x /usr/local/bin/opnsense_agent.sh

# Clean PID
rm -f /var/run/opnsense_agent.pid

# Start new agent - using explicit sh and proper backgrounding
/bin/sh /usr/local/bin/opnsense_agent.sh < /dev/null > /dev/null 2>&1 &

sleep 2
ps aux | grep '[o]pnsense_agent.sh' | wc -l
