<?php
/**
 * Agent Update Check
 * Returns update information if new version available
 */

require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json');

// Verify agent key
$agent_key = $_SERVER['HTTP_X_AGENT_KEY'] ?? '';
if ($agent_key !== 'opnsense_agent_2024_secure') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$firewall_id = (int)($data['firewall_id'] ?? 0);
$current_version = $data['agent_version'] ?? '0.0.0';

if (!$firewall_id) {
    echo json_encode(['error' => 'Missing firewall_id']);
    exit;
}

// Check if update available
$latest_version = '2.5.0';
$update_available = version_compare($current_version, $latest_version, '<');

if (!$update_available) {
    echo json_encode([
        'update_available' => false,
        'current_version' => $current_version,
        'latest_version' => $latest_version
    ]);
    exit;
}

// Return update instructions
echo json_encode([
    'update_available' => true,
    'current_version' => $current_version,
    'latest_version' => $latest_version,
    'download_url' => 'https://opn.agit8or.net/download_agent.php?version=2.5.0&firewall_id=' . $firewall_id,
    'install_path' => '/usr/local/bin/opnsense_agent_v2.5.0.sh',
    'restart_required' => true,
    'instructions' => [
        'Download new agent',
        'Generate SSH key if not exists',
        'Register public key with management server',
        'Stop old agent',
        'Start new agent'
    ],
    'auto_update_script' => base64_encode('#!/bin/bash
# Auto-update script for agent v2.5.0
set -e

FIREWALL_ID=' . $firewall_id . '
MGMT_SERVER="opn.agit8or.net"
AGENT_KEY="opnsense_agent_2024_secure"

echo "[$(date "+%Y-%m-%d %H:%M:%S")] Starting auto-update to v2.5.0..."

# Download new agent
echo "[$(date "+%Y-%m-%d %H:%M:%S")] Downloading agent v2.5.0..."
curl -k -H "X-Agent-Key: $AGENT_KEY" \
  "https://$MGMT_SERVER/download_agent.php?version=2.5.0&firewall_id=$FIREWALL_ID" \
  -o /usr/local/bin/opnsense_agent_v2.5.0.sh

chmod +x /usr/local/bin/opnsense_agent_v2.5.0.sh

# Generate SSH key if needed
if [ ! -f /root/.ssh/opnsense_proxy_key ]; then
    echo "[$(date "+%Y-%m-%d %H:%M:%S")] Generating SSH key..."
    ssh-keygen -t ed25519 -f /root/.ssh/opnsense_proxy_key -N ""
    
    # Register public key
    echo "[$(date "+%Y-%m-%d %H:%M:%S")] Registering public key..."
    PUBLIC_KEY=$(cat /root/.ssh/opnsense_proxy_key.pub)
    curl -k -X POST \
      -H "X-Agent-Key: $AGENT_KEY" \
      -d "firewall_id=$FIREWALL_ID" \
      --data-urlencode "public_key=$PUBLIC_KEY" \
      "https://$MGMT_SERVER/add_ssh_key.php"
fi

# Stop old agent
echo "[$(date "+%Y-%m-%d %H:%M:%S")] Stopping old agent..."
crontab -r 2>/dev/null || true
pkill -f "opnsense_agent.sh" 2>/dev/null || true

# Wait a moment
sleep 2

# Start new agent
echo "[$(date "+%Y-%m-%d %H:%M:%S")] Starting agent v2.5.0..."
nohup /usr/local/bin/opnsense_agent_v2.5.0.sh > /var/log/opnsense_agent.log 2>&1 &

echo "[$(date "+%Y-%m-%d %H:%M:%S")] Update complete! Agent v2.5.0 is now running."
echo "[$(date "+%Y-%m-%d %H:%M:%S")] Check logs: tail -f /var/log/opnsense_agent.log"
')
]);
