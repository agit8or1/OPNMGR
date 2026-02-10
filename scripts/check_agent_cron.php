<?php
require_once __DIR__ . '/../inc/bootstrap_agent.php';
require_once __DIR__ . '/manage_ssh_keys.php';

$firewall_id = 21;
$command = 'crontab -l | grep -E "opnsense|agent"';

$cmd_id = queue_command($firewall_id, $command, 'Check cron schedule');
echo "Queued command ID: $cmd_id\n";
sleep(35);

$result = wait_for_command($cmd_id, 5);
if ($result === false) {
    echo "Timeout\n";
} else {
    echo "Result: " . $result['result'] . "\n";
}
