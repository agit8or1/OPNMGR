<?php
/**
 * SSH-Based Agent Repair API
 * Uses SSH to directly connect to firewall and fix/update the agent
 */
require_once __DIR__ . '/../inc/bootstrap.php';

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
$stmt = db()->prepare("SELECT id, hostname, wan_ip FROM firewalls WHERE id = ?");
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$firewall) {
    echo json_encode(['success' => false, 'error' => 'Firewall not found']);
    exit;
}

$ssh_key = "/etc/opnmgr/keys/id_firewall_{$firewall_id}";

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
// uniqid(..., true) returns something like "repair_6aac18540585c6.65557273".
// api/repair_status.php validates the session id against ^[A-Za-z0-9_-]+$, which
// that dot fails - so every repair ever started was rejected by its own status
// endpoint, and the progress modal sat at "Initializing... 0%" forever. Hex only.
$session_id = 'repair_' . bin2hex(random_bytes(8));
$log_file = "/tmp/agent_repair_{$session_id}.log";

// Start the repair process in the background
$repair_script = <<<'SCRIPT'
#!/bin/bash
#
# Reinstall the OPNManager agent on a firewall over SSH.
#
# This script used to do something else entirely: it downloaded
# downloads/tunnel_agent.sh - the legacy standalone agent, last touched in
# October 2025 - and added a cron entry running it every two minutes. On a fleet
# running the 1.6.x plugin agent that is not a repair; it is a second, obsolete
# agent checking in alongside the real one. The button could not have done that
# because sudo blocked it, which is the only reason it never happened.
#
# It now runs the same installer as every other install path, so "repair" means
# the firewall ends up on the published agent version.
LOG_FILE="$1"
FIREWALL_ID="$2"
SSH_KEY="$3"
WAN_IP="$4"
BASE_URL="$5"

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $1" >> "$LOG_FILE"; }

SSH_OPTS="-i $SSH_KEY -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/etc/opnmgr/known_hosts -o BatchMode=yes -o ConnectTimeout=10"

log "[INFO] Starting agent repair..."
log "[INFO] Firewall ID: $FIREWALL_ID"
log "[INFO] Target: $WAN_IP"

# Test SSH connectivity.
#
# stderr goes to the log, never into the stream being matched. It used to be
# folded in with 2>&1 and matched with grep -q "Connected" - and sudo's denial
# message quotes the command it refused, which contains echo "Connected". The
# check therefore matched the text of its own failure and reported success while
# nothing had connected at all. That is what "[SUCCESS] SSH connection
# successful" meant in every log this ever wrote.
log "[STEP] Testing SSH connection..."
if ssh $SSH_OPTS root@"$WAN_IP" 'echo OPNMGR_SSH_OK' 2>>"$LOG_FILE" | grep -qx 'OPNMGR_SSH_OK'; then
    log "[SUCCESS] SSH connection successful"
else
    log "[ERROR] Cannot connect via SSH"
    log "[ERROR] The key may not be authorized on the firewall, or SSH may be blocked"
    exit 1
fi

# No scp, no temp file on either side: the installer is a single command, which
# is also the command an operator would run at the console. Nothing to transfer
# means nothing to go stale between here and the firewall.
log "[STEP] Installing the published agent..."
ssh $SSH_OPTS root@"$WAN_IP" \
    "fetch -o - ${BASE_URL}/downloads/plugins/install_opnmanager_agent.sh | env OPNMGR_BASE_URL=${BASE_URL} sh" \
    >> "$LOG_FILE" 2>&1
RC=$?

if [ $RC -eq 0 ]; then
    log "[SUCCESS] Agent installed"
    log "[INFO] Waiting for agent check-in..."
    sleep 10
    log "[COMPLETE] Repair operation finished"
else
    log "[ERROR] Install failed (ssh exit $RC)"
    exit 1
fi
SCRIPT;

// Write repair script to temp file
// The download URL was the maintainer's own host with an endpoint
// (download_tunnel_agent.php) that has never existed in this codebase, so this
// repair could not have worked for anyone and pointed every install at a third
// party. It now comes from this installation's configured address.
$agent_url = opnmgr_server_url();
if ($agent_url === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => "This manager's URL is not configured, so the repair script cannot "
                   . 'tell the firewall where to download the agent. Set the server_url '
                   . 'setting or APP_URL in .env.',
    ]);
    exit;
}

$script_file = "/tmp/repair_script_{$session_id}.sh";
file_put_contents($script_file, $repair_script);
chmod($script_file, 0755);

// Execute in background
$cmd = sprintf(
    '%s %s %d %s %s %s > /dev/null 2>&1 &',
    escapeshellarg($script_file),
    escapeshellarg($log_file),
    $firewall_id,
    escapeshellarg($ssh_key),
    escapeshellarg($firewall['wan_ip']),
    escapeshellarg($agent_url)
);

exec($cmd);

// Log the operation.
//
// This wrote to activity_log, a table that does not exist in this schema and
// appears in no migration - so the request died here with a 500 after having
// already launched the repair. audit_log is the table this project actually
// keeps, and it is where every other privileged action is recorded.
audit_log('agent.repair.ssh', [
    'object_type' => 'firewall',
    'object_id'   => (string) $firewall_id,
    'firewall_id' => $firewall_id,
    'message'     => "SSH agent repair initiated for {$firewall['hostname']}",
    'metadata'    => ['session_id' => $session_id, 'wan_ip' => $firewall['wan_ip']],
]);

echo json_encode([
    'success' => true,
    'session_id' => $session_id,
    'message' => 'Agent repair started via SSH',
    'firewall' => $firewall['hostname']
]);
