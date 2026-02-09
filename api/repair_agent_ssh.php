<?php
/**
 * SSH-Based Agent Repair API
 * Uses SSH to directly connect to firewall and fix/update the agent
 */

session_start();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/csrf.php';

header('Content-Type: application/json');

// Verify authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!csrf_verify($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$firewall_id = (int)($_POST['firewall_id'] ?? 0);

if ($firewall_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid firewall ID']);
    exit;
}

// Get firewall info
$stmt = $DB->prepare("SELECT id, hostname, wan_ip FROM firewalls WHERE id = ?");
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$firewall) {
    echo json_encode(['success' => false, 'error' => 'Firewall not found']);
    exit;
}

$ssh_key = "/var/www/opnsense/keys/id_firewall_{$firewall_id}";

// Check if SSH key exists
if (!file_exists($ssh_key)) {
    echo json_encode([
        'success' => false,
        'error' => 'SSH key not found. Firewall must be configured for SSH access.',
        'note' => 'SSH keys are automatically created for new firewalls. Older firewalls may need manual SSH key setup.'
    ]);
    exit;
}

// Create a unique session ID for tracking this repair operation
$session_id = uniqid('repair_', true);
$log_file = "/tmp/agent_repair_{$session_id}.log";

// Start the repair process in the background
$repair_script = <<<'SCRIPT'
#!/bin/bash
LOG_FILE="$1"
FIREWALL_ID="$2"
SSH_KEY="$3"
WAN_IP="$4"

echo "$(date '+%Y-%m-%d %H:%M:%S') [INFO] Starting agent repair..." >> "$LOG_FILE"
echo "$(date '+%Y-%m-%d %H:%M:%S') [INFO] Firewall ID: $FIREWALL_ID" >> "$LOG_FILE"
echo "$(date '+%Y-%m-%d %H:%M:%S') [INFO] Target: $WAN_IP" >> "$LOG_FILE"

# Test SSH connectivity
echo "$(date '+%Y-%m-%d %H:%M:%S') [STEP] Testing SSH connection..." >> "$LOG_FILE"
if sudo -u www-data ssh -i "$SSH_KEY" -o StrictHostKeyChecking=no -o ConnectTimeout=10 root@"$WAN_IP" 'echo "Connected"' 2>&1 | grep -q "Connected"; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') [SUCCESS] SSH connection successful" >> "$LOG_FILE"
else
    echo "$(date '+%Y-%m-%d %H:%M:%S') [ERROR] Cannot connect via SSH" >> "$LOG_FILE"
    echo "$(date '+%Y-%m-%d %H:%M:%S') [ERROR] Firewall may be blocking SSH or key not authorized" >> "$LOG_FILE"
    exit 1
fi

# Create repair script on remote system
echo "$(date '+%Y-%m-%d %H:%M:%S') [STEP] Creating repair script on firewall..." >> "$LOG_FILE"
cat > /tmp/remote_repair_${FIREWALL_ID}.sh << 'REMOTE'
#!/bin/sh
echo "Stopping old agents..."
pkill -f tunnel_agent 2>/dev/null
pkill -f opnsense_agent 2>/dev/null
sleep 2

echo "Downloading latest agent (v3.8.5)..."
curl -s -k -o /usr/local/bin/tunnel_agent.sh "https://opn.agit8or.net/download_tunnel_agent.php?firewall_id=FIREWALL_ID_PLACEHOLDER"

if [ ! -f /usr/local/bin/tunnel_agent.sh ]; then
    echo "ERROR: Failed to download agent"
    exit 1
fi

chmod +x /usr/local/bin/tunnel_agent.sh

echo "Setting up cron job..."
(crontab -l 2>/dev/null | grep -v tunnel_agent; echo "*/2 * * * * /usr/local/bin/tunnel_agent.sh") | crontab -

echo "Starting agent..."
nohup /usr/local/bin/tunnel_agent.sh > /tmp/agent_repair.log 2>&1 &
sleep 3

echo "Verifying installation..."
NEW_VERSION=$(grep "AGENT_VERSION=" /usr/local/bin/tunnel_agent.sh | head -1 | cut -d'"' -f2)
echo "Installed version: $NEW_VERSION"

if pgrep -f tunnel_agent > /dev/null; then
    echo "Agent is running"
else
    echo "WARNING: Agent may not be running"
fi
REMOTE

# Replace firewall ID placeholder
sed "s/FIREWALL_ID_PLACEHOLDER/$FIREWALL_ID/g" /tmp/remote_repair_${FIREWALL_ID}.sh > /tmp/remote_repair_${FIREWALL_ID}_final.sh
mv /tmp/remote_repair_${FIREWALL_ID}_final.sh /tmp/remote_repair_${FIREWALL_ID}.sh

# Transfer and execute
echo "$(date '+%Y-%m-%d %H:%M:%S') [STEP] Transferring repair script to firewall..." >> "$LOG_FILE"
if sudo -u www-data scp -i "$SSH_KEY" -o StrictHostKeyChecking=no /tmp/remote_repair_${FIREWALL_ID}.sh root@"$WAN_IP":/tmp/repair_agent.sh >> "$LOG_FILE" 2>&1; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') [SUCCESS] Script transferred" >> "$LOG_FILE"
else
    echo "$(date '+%Y-%m-%d %H:%M:%S') [ERROR] Failed to transfer script" >> "$LOG_FILE"
    exit 1
fi

echo "$(date '+%Y-%m-%d %H:%M:%S') [STEP] Executing repair on firewall..." >> "$LOG_FILE"
sudo -u www-data ssh -i "$SSH_KEY" -o StrictHostKeyChecking=no root@"$WAN_IP" 'chmod +x /tmp/repair_agent.sh && /tmp/repair_agent.sh' >> "$LOG_FILE" 2>&1

if [ $? -eq 0 ]; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') [SUCCESS] Agent repair completed successfully" >> "$LOG_FILE"
    echo "$(date '+%Y-%m-%d %H:%M:%S') [INFO] Waiting for agent check-in..." >> "$LOG_FILE"
    sleep 10
    echo "$(date '+%Y-%m-%d %H:%M:%S') [COMPLETE] Repair operation finished" >> "$LOG_FILE"
else
    echo "$(date '+%Y-%m-%d %H:%M:%S') [ERROR] Repair execution failed" >> "$LOG_FILE"
    exit 1
fi

# Cleanup
rm -f /tmp/remote_repair_${FIREWALL_ID}.sh

exit 0
SCRIPT;

// Write repair script to temp file
$script_file = "/tmp/repair_script_{$session_id}.sh";
file_put_contents($script_file, $repair_script);
chmod($script_file, 0755);

// Execute in background
$cmd = sprintf(
    '%s %s %d %s %s > /dev/null 2>&1 &',
    escapeshellarg($script_file),
    escapeshellarg($log_file),
    $firewall_id,
    escapeshellarg($ssh_key),
    escapeshellarg($firewall['wan_ip'])
);

exec($cmd);

// Log the operation
$stmt = $DB->prepare("INSERT INTO activity_log (user_id, firewall_id, action, details, created_at) VALUES (?, ?, 'repair_agent_ssh', ?, NOW())");
$stmt->execute([
    $_SESSION['user_id'],
    $firewall_id,
    "SSH-based agent repair initiated for {$firewall['hostname']}"
]);

echo json_encode([
    'success' => true,
    'session_id' => $session_id,
    'message' => 'Agent repair started via SSH',
    'firewall' => $firewall['hostname']
]);
