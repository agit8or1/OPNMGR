<?php
/**
 * Deploy Update Agent
 * Uses the existing primary agent to deploy the update agent via command queue
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/logging.php';
requireLogin();

header('Content-Type: application/json');

$firewall_id = (int)($_POST['firewall_id'] ?? 0);

if (!$firewall_id) {
    echo json_encode(['success' => false, 'error' => 'Missing firewall_id']);
    exit;
}

// Verify firewall exists and primary agent is online
$stmt = $DB->prepare("
    SELECT f.id, f.hostname, fa.status, fa.agent_version
    FROM firewalls f
    LEFT JOIN firewall_agents fa ON f.id = fa.firewall_id AND fa.agent_type = 'primary'
    WHERE f.id = ?
");
$stmt->execute([$firewall_id]);
$firewall = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$firewall) {
    echo json_encode(['success' => false, 'error' => 'Firewall not found']);
    exit;
}

if ($firewall['status'] !== 'online') {
    echo json_encode(['success' => false, 'error' => 'Primary agent is not online. Cannot deploy update agent.']);
    exit;
}

// Check if update agent already exists
$stmt = $DB->prepare("SELECT id FROM firewall_agents WHERE firewall_id = ? AND agent_type = 'update'");
$stmt->execute([$firewall_id]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Update agent already deployed']);
    exit;
}

try {
    // Build the deployment script
    $deploy_script = '#!/bin/sh' . "\n\n";
    $deploy_script .= '# Download update agent' . "\n";
    $deploy_script .= '/usr/local/bin/curl -k -s -o /tmp/opnsense_update_agent.sh "https://opn.agit8or.net/download_update_agent.php?firewall_id=' . $firewall_id . '"' . "\n\n";
    
    $deploy_script .= 'if [ $? -ne 0 ] || [ ! -s /tmp/opnsense_update_agent.sh ]; then' . "\n";
    $deploy_script .= '    echo "ERROR: Failed to download update agent"' . "\n";
    $deploy_script .= '    exit 1' . "\n";
    $deploy_script .= 'fi' . "\n\n";
    
    $deploy_script .= '# Verify it is a valid shell script' . "\n";
    $deploy_script .= 'if ! head -1 /tmp/opnsense_update_agent.sh | grep -q "^#!/"; then' . "\n";
    $deploy_script .= '    echo "ERROR: Downloaded file is not a valid shell script"' . "\n";
    $deploy_script .= '    rm -f /tmp/opnsense_update_agent.sh' . "\n";
    $deploy_script .= '    exit 1' . "\n";
    $deploy_script .= 'fi' . "\n\n";
    
    $deploy_script .= '# Install to /usr/local/bin/' . "\n";
    $deploy_script .= 'mv /tmp/opnsense_update_agent.sh /usr/local/bin/opnsense_update_agent.sh' . "\n";
    $deploy_script .= 'chmod +x /usr/local/bin/opnsense_update_agent.sh' . "\n\n";
    
    $deploy_script .= '# Create rc.d service script' . "\n";
    $deploy_script .= 'cat > /usr/local/etc/rc.d/opnsense_update_agent << \'EOFRC\'' . "\n";
    $deploy_script .= '#!/bin/sh' . "\n";
    $deploy_script .= '# PROVIDE: opnsense_update_agent' . "\n";
    $deploy_script .= '# REQUIRE: NETWORKING' . "\n";
    $deploy_script .= '# KEYWORD: shutdown' . "\n\n";
    $deploy_script .= '. /etc/rc.subr' . "\n\n";
    $deploy_script .= 'name="opnsense_update_agent"' . "\n";
    $deploy_script .= 'rcvar="opnsense_update_agent_enable"' . "\n";
    $deploy_script .= 'pidfile="/var/run/opnsense_update_agent.pid"' . "\n";
    $deploy_script .= 'command="/usr/local/bin/opnsense_update_agent.sh"' . "\n";
    $deploy_script .= 'command_interpreter="/bin/sh"' . "\n\n";
    $deploy_script .= 'load_rc_config $name' . "\n";
    $deploy_script .= ': ${opnsense_update_agent_enable:="NO"}' . "\n\n";
    $deploy_script .= 'run_rc_command "$1"' . "\n";
    $deploy_script .= 'EOFRC' . "\n\n";
    
    $deploy_script .= 'chmod +x /usr/local/etc/rc.d/opnsense_update_agent' . "\n\n";
    
    $deploy_script .= '# Enable service' . "\n";
    $deploy_script .= 'sysrc opnsense_update_agent_enable="YES"' . "\n\n";
    
    $deploy_script .= '# Start the update agent' . "\n";
    $deploy_script .= 'service opnsense_update_agent start' . "\n\n";
    
    $deploy_script .= 'if [ $? -eq 0 ]; then' . "\n";
    $deploy_script .= '    echo "SUCCESS: Update agent deployed and started"' . "\n";
    $deploy_script .= 'else' . "\n";
    $deploy_script .= '    echo "ERROR: Failed to start update agent"' . "\n";
    $deploy_script .= '    exit 1' . "\n";
    $deploy_script .= 'fi' . "\n";
    
    // Queue the deployment command
    $stmt = $DB->prepare("
        INSERT INTO firewall_commands (firewall_id, command, description, status, created_at)
        VALUES (?, ?, 'Deploy Update Agent v1.1.0', 'pending', NOW())
    ");
    $stmt->execute([$firewall_id, $deploy_script]);
    $command_id = $DB->lastInsertId();
    
    // Log the deployment
    log_info('update_agent', "Update agent deployment queued for {$firewall['hostname']}", [
        'command_id' => $command_id,
        'firewall_id' => $firewall_id
    ], $firewall_id);
    
    echo json_encode([
        'success' => true,
        'message' => 'Update agent deployment queued',
        'command_id' => $command_id,
        'note' => 'The primary agent will execute the deployment on next check-in (within 120 seconds)'
    ]);
    
} catch (Exception $e) {
    log_error('update_agent', "Failed to queue update agent deployment: " . $e->getMessage(), null, $firewall_id);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
