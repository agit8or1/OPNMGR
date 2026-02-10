<?php
require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/manage_ssh_keys.php';

$firewall_id = 21;
$command = 'curl -k -o /usr/local/bin/opnsense_agent_v2.sh https://opn.agit8or.net/downloads/opnsense_agent_v3.4.9.sh && chmod +x /usr/local/bin/opnsense_agent_v2.sh && echo "Upgraded to v3.4.9"';

$cmd_id = queue_command($firewall_id, $command, 'Upgrade to agent v3.4.9');
echo "Queued command ID: $cmd_id\n";
echo "Waiting for execution...\n";

sleep(40);

$result = wait_for_command($cmd_id, 5);
if ($result === false) {
    echo "Timeout waiting for command\n";
    exit(1);
}

echo "Command result: " . $result['result'] . "\n";
if (!empty($result['output'])) {
    echo "Output: " . $result['output'] . "\n";
}
