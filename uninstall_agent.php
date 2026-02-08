<?php
header('Content-Type: text/plain');

// Check if firewall_id is provided
$firewall_id = $_GET['firewall_id'] ?? null;

if (!$firewall_id) {
    echo "ERROR: Missing firewall_id parameter\n";
    exit(1);
}

// Return uninstall script
echo '#!/bin/sh
# Agent Uninstall Script
echo "$(date): Starting agent uninstall..."

# Kill all agent processes
echo "$(date): Stopping agent processes..."
pkill -f tunnel_agent 2>/dev/null
pkill -f opnsense_agent 2>/dev/null
pkill -f "socat.*:81" 2>/dev/null

# Wait for processes to stop
sleep 3

# Force kill any remaining processes
ps aux | grep -E "(tunnel_agent|opnsense_agent)" | grep -v grep | awk \'{print $2}\' | xargs kill -9 2>/dev/null

# Remove agent files
echo "$(date): Removing agent files..."
rm -f /usr/local/bin/tunnel_agent* 2>/dev/null
rm -f /usr/local/bin/opnsense_agent* 2>/dev/null
rm -f /tmp/tunnel_agent* /tmp/opnsense_agent* /tmp/*agent* 2>/dev/null
rm -rf /usr/local/opnsense_agent 2>/dev/null

# Remove any cron jobs
crontab -l 2>/dev/null | grep -v -E "(tunnel_agent|opnsense_agent)" | crontab - 2>/dev/null

# Remove log files
rm -f /tmp/agent*.log 2>/dev/null

echo "$(date): Agent uninstall completed"
echo "$(date): Firewall entry remains in management system"
echo "$(date): Use the web interface to re-enroll if needed"
';
?>