#!/bin/sh

# Force agent update for debugging
echo "Force updating tunnel agent..."

# Download the latest agent
curl -s -k -o /tmp/new_tunnel_agent.sh "https://opn.agit8or.net/download/tunnel_agent.sh"

if [ $? -eq 0 ] && [ -s /tmp/new_tunnel_agent.sh ]; then
    echo "Downloaded new agent successfully"
    
    # Stop current agent
    pkill -f tunnel_agent
    sleep 2
    
    # Install new agent
    chmod +x /tmp/new_tunnel_agent.sh
    cp /tmp/new_tunnel_agent.sh /usr/local/bin/tunnel_agent.sh
    
    echo "Starting new agent..."
    nohup /usr/local/bin/tunnel_agent.sh > /dev/null 2>&1 &
    
    echo "Agent update completed"
else
    echo "Failed to download new agent"
    exit 1
fi