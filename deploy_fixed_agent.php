#!/usr/bin/php
<?php
/**
 * Deploy Fixed Agent with Lockfile
 * 
 * This script:
 * 1. Kills all duplicate agents
 * 2. Deploys agent with lockfile protection
 * 3. Monitors for 5 minutes to verify single instance
 */

require_once __DIR__ . '/inc/db.php';

$firewall_id = $argv[1] ?? 21;

echo "=== AGENT FIX DEPLOYMENT ===\n";
echo "Firewall ID: $firewall_id\n\n";

// Create the comprehensive cleanup and deploy command
$deploy_command = <<<'BASH'
#!/bin/sh
# Agent Cleanup and Deploy Script
# This script ensures only ONE agent runs with lockfile protection

LOCKFILE="/var/run/opnsense_agent.lock"
AGENT_PATH="/usr/local/bin/opnsense_agent.sh"
LOG_FILE="/var/log/opnsense_agent_deploy.log"

echo "=== Agent Deployment Started: $(date) ===" | tee -a "$LOG_FILE"

# Step 1: Kill ALL existing agents
echo "Step 1: Killing all existing agent instances..." | tee -a "$LOG_FILE"
BEFORE_COUNT=$(ps aux | grep -c "[o]pnsense_agent.sh")
echo "Agents running before cleanup: $BEFORE_COUNT" | tee -a "$LOG_FILE"

pkill -9 -f "opnsense_agent.sh" 2>/dev/null
killall -9 sh 2>/dev/null
sleep 3

AFTER_COUNT=$(ps aux | grep -c "[o]pnsense_agent.sh")
echo "Agents running after cleanup: $AFTER_COUNT" | tee -a "$LOG_FILE"

# Step 2: Remove old lockfile
if [ -f "$LOCKFILE" ]; then
    echo "Step 2: Removing stale lockfile..." | tee -a "$LOG_FILE"
    rm -f "$LOCKFILE"
fi

# Step 3: Download fresh agent with lockfile
echo "Step 3: Downloading fresh agent with lockfile..." | tee -a "$LOG_FILE"
curl -k -o "$AGENT_PATH" "https://opn.agit8or.net/download_tunnel_agent.php?firewall_id=FIREWALL_ID_PLACEHOLDER" 2>&1 | tee -a "$LOG_FILE"

if [ ! -f "$AGENT_PATH" ]; then
    echo "ERROR: Failed to download agent!" | tee -a "$LOG_FILE"
    exit 1
fi

chmod +x "$AGENT_PATH"
echo "Agent downloaded and made executable" | tee -a "$LOG_FILE"

# Step 4: Verify agent has lockfile code
if grep -q "LOCKFILE=" "$AGENT_PATH"; then
    echo "✓ Agent contains lockfile protection" | tee -a "$LOG_FILE"
else
    echo "WARNING: Agent may not have lockfile protection!" | tee -a "$LOG_FILE"
fi

# Step 5: Start single agent instance
echo "Step 5: Starting single agent instance..." | tee -a "$LOG_FILE"
nohup "$AGENT_PATH" > /dev/null 2>&1 &
AGENT_PID=$!
echo "Agent started with PID: $AGENT_PID" | tee -a "$LOG_FILE"

# Step 6: Wait and verify only one instance
sleep 5
FINAL_COUNT=$(ps aux | grep -c "[o]pnsense_agent.sh")
echo "Final agent count: $FINAL_COUNT" | tee -a "$LOG_FILE"

if [ "$FINAL_COUNT" -eq 1 ]; then
    echo "SUCCESS: Single agent running!" | tee -a "$LOG_FILE"
    echo "Lockfile: $(ls -la $LOCKFILE 2>/dev/null || echo 'Not created yet')" | tee -a "$LOG_FILE"
    exit 0
else
    echo "WARNING: $FINAL_COUNT agents running (expected 1)" | tee -a "$LOG_FILE"
    exit 1
fi
BASH;

// Replace firewall ID placeholder
$deploy_command = str_replace('FIREWALL_ID_PLACEHOLDER', $firewall_id, $deploy_command);

// Queue the deployment command
$stmt = $DB->prepare('INSERT INTO firewall_commands (firewall_id, command, description, status, created_at) VALUES (?, ?, ?, "pending", NOW())');
$stmt->execute([
    $firewall_id,
    $deploy_command,
    'Deploy fixed agent with lockfile protection'
]);

$command_id = $DB->lastInsertId();
echo "✓ Deployment command queued (ID: $command_id)\n";
echo "✓ Agent will execute on next checkin\n\n";

echo "Monitoring deployment...\n";
echo "Waiting for command execution...\n\n";

// Monitor for 3 minutes
$start_time = time();
$executed = false;

while ((time() - $start_time) < 180) {
    $stmt = $DB->prepare('SELECT status, completed_at, LEFT(result, 500) as result FROM firewall_commands WHERE id = ?');
    $stmt->execute([$command_id]);
    $cmd = $stmt->fetch();
    
    if ($cmd['status'] === 'completed') {
        $executed = true;
        echo "✓ Command executed at {$cmd['completed_at']}\n";
        echo "Result:\n{$cmd['result']}\n\n";
        break;
    } elseif ($cmd['status'] === 'failed') {
        echo "✗ Command failed!\n";
        echo "Result:\n{$cmd['result']}\n\n";
        exit(1);
    }
    
    echo ".";
    sleep(5);
}

if (!$executed) {
    echo "\n⚠ Command not executed yet (agent may not be checking in)\n";
    exit(1);
}

// Monitor agent checkins for 2 minutes
echo "\nMonitoring agent checkins for 2 minutes...\n";
$checkin_start = time();
$checkins = [];

while ((time() - $checkin_start) < 120) {
    $stmt = $DB->prepare("SELECT COUNT(*) as cnt FROM system_logs WHERE category='agent' AND firewall_id=? AND timestamp > NOW() - INTERVAL 30 SECOND");
    $stmt->execute([$firewall_id]);
    $result = $stmt->fetch();
    $checkins[] = $result['cnt'];

    echo sprintf("[%s] Checkins last 30s: %d\n", date('H:i:s'), $result['cnt']);
    sleep(10);
}

// Analyze results
$avg_checkins = array_sum($checkins) / count($checkins);
$expected = 0.25; // Should be ~0.25 checkins per 30s (1 per 120s)

echo "\n=== ANALYSIS ===\n";
echo "Average checkins per 30s: " . round($avg_checkins, 2) . "\n";
echo "Expected: ~0.25 (1 checkin per 120s)\n";

if ($avg_checkins <= 0.5) {
    echo "✓ SUCCESS: Single agent appears to be running!\n";
    exit(0);
} else {
    $estimated_agents = round($avg_checkins * 4);
    echo "✗ WARNING: Still ~$estimated_agents agents running\n";
    exit(1);
}
