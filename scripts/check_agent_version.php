<?php
require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/manage_ssh_keys.php';

$firewall_id = 21;
$command = 'grep "AGENT_VERSION=" /usr/local/bin/opnsense_agent_v2.sh | head -1';

$cmd_id = queue_command($firewall_id, $command, 'Check agent version');
echo "Queued command ID: $cmd_id\n";
sleep(35);

$result = wait_for_command($cmd_id, 5);
if ($result === false) {
    echo "Timeout\n";
} else {
    echo "Result: " . $result['result'] . "\n";
}
