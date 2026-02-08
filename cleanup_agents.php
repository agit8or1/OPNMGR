#!/usr/bin/php
<?php
/**
 * Agent Cleanup Script
 * 
 * This script safely kills duplicate agent instances and starts a single clean agent.
 * Can be run manually or queued as a command to the firewall.
 * 
 * Usage: php cleanup_agents.php [firewall_id]
 */

require_once __DIR__ . '/inc/db.php';

$firewall_id = $argv[1] ?? null;

if (!$firewall_id) {
    echo "Usage: php cleanup_agents.php [firewall_id]\n";
    echo "\nExample: php cleanup_agents.php 21\n";
    echo "\nTo queue as command to firewall:\n";
    echo "php cleanup_agents.php 21 --queue\n";
    exit(1);
}

$is_queue = in_array('--queue', $argv);

// Build the cleanup command
$cleanup_command = <<<'BASH'
#!/bin/bash
# Agent Cleanup Script - Safely restart agent with lockfile protection

LOCKFILE="/var/run/opnsense_agent.lock"
AGENT_PATH="/usr/local/bin/opnsense_agent.sh"
LOG_FILE="/var/log/opnsense_agent_cleanup.log"

echo "=== Agent Cleanup Started: $(date) ===" | tee -a "$LOG_FILE"

# Count current agents
AGENT_COUNT=$(ps aux | grep -c "[o]pnsense_agent.sh")
echo "Current agent processes: $AGENT_COUNT" | tee -a "$LOG_FILE"

if [ "$AGENT_COUNT" -gt 1 ]; then
    echo "Multiple agents detected! Killing all instances..." | tee -a "$LOG_FILE"
    
    # Kill all agent processes
    pkill -9 -f "opnsense_agent.sh"
    sleep 2
    
    # Verify they're dead
    REMAINING=$(ps aux | grep -c "[o]pnsense_agent.sh")
    echo "Remaining agents after kill: $REMAINING" | tee -a "$LOG_FILE"
    
    if [ "$REMAINING" -gt 0 ]; then
        echo "WARNING: Some agents survived! Trying harder..." | tee -a "$LOG_FILE"
        killall -9 sh 2>/dev/null
        sleep 1
    fi
fi

# Remove old lockfile if exists
if [ -f "$LOCKFILE" ]; then
    echo "Removing stale lockfile..." | tee -a "$LOG_FILE"
    rm -f "$LOCKFILE"
fi

# Download fresh agent with lockfile support
echo "Downloading fresh agent..." | tee -a "$LOG_FILE"
curl -k -o /tmp/agent_fresh.sh "https://opn.agit8or.net/download_tunnel_agent.php?firewall_id=FIREWALL_ID_PLACEHOLDER" 2>&1 | tee -a "$LOG_FILE"

if [ -f /tmp/agent_fresh.sh ]; then
    mv /tmp/agent_fresh.sh "$AGENT_PATH"
    chmod +x "$AGENT_PATH"
    echo "Fresh agent installed at $AGENT_PATH" | tee -a "$LOG_FILE"
    
    # Start single agent
    echo "Starting single agent instance..." | tee -a "$LOG_FILE"
    nohup "$AGENT_PATH" > /dev/null 2>&1 &
    AGENT_PID=$!
    echo "Agent started with PID: $AGENT_PID" | tee -a "$LOG_FILE"
    
    sleep 5
    
    # Verify only one is running
    FINAL_COUNT=$(ps aux | grep -c "[o]pnsense_agent.sh")
    echo "Final agent count: $FINAL_COUNT" | tee -a "$LOG_FILE"
    
    if [ "$FINAL_COUNT" -eq 1 ]; then
        echo "SUCCESS: Single agent running cleanly!" | tee -a "$LOG_FILE"
        exit 0
    else
        echo "ERROR: Still have $FINAL_COUNT agents running!" | tee -a "$LOG_FILE"
        exit 1
    fi
else
    echo "ERROR: Failed to download fresh agent!" | tee -a "$LOG_FILE"
    exit 1
fi
BASH;

// Replace firewall ID placeholder
$cleanup_command = str_replace('FIREWALL_ID_PLACEHOLDER', $firewall_id, $cleanup_command);

if ($is_queue) {
    // Queue the command
    $stmt = $DB->prepare('INSERT INTO firewall_commands (firewall_id, command, description, status, created_at) VALUES (?, ?, ?, "pending", NOW())');
    $stmt->execute([
        $firewall_id,
        $cleanup_command,
        'Agent Cleanup - Kill duplicates and restart single agent'
    ]);
    
    $command_id = $DB->lastInsertId();
    echo "✓ Cleanup command queued (ID: $command_id)\n";
    echo "✓ Firewall will execute on next checkin (within 2 minutes)\n";
    echo "✓ Check logs: SELECT * FROM firewall_commands WHERE id=$command_id;\n";
} else {
    // Display command for manual execution
    echo "=== Agent Cleanup Command ===\n\n";
    echo "Copy and run this on the firewall:\n\n";
    echo $cleanup_command . "\n";
}
