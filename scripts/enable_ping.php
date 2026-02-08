<?php
/**
 * Script to enable ICMP (ping) on OPNsense firewall
 * This script will be executed by the tunnel agent on the firewall
 */

// OPNsense configuration paths
$config_file = '/conf/config.xml';
$backup_dir = '/conf/backup';

echo "OPNsense Ping Enable Script\n";
echo "===========================\n";

// Check if we're running on OPNsense
if (!file_exists('/usr/local/opnsense/mvc/app/models')) {
    echo "ERROR: This script must run on an OPNsense firewall\n";
    exit(1);
}

// Create backup
$backup_file = $backup_dir . '/config-before-ping-enable-' . date('Y-m-d-H-i-s') . '.xml';
if (!copy($config_file, $backup_file)) {
    echo "ERROR: Failed to create configuration backup\n";
    exit(1);
}
echo "Configuration backed up to: $backup_file\n";

// Load current configuration
$config = simplexml_load_file($config_file);
if (!$config) {
    echo "ERROR: Failed to load configuration file\n";
    exit(1);
}

// Check if ICMP rule already exists
$icmp_rule_exists = false;
if (isset($config->filter->rule)) {
    foreach ($config->filter->rule as $rule) {
        if (isset($rule->protocol) && $rule->protocol == 'icmp' && 
            isset($rule->descr) && strpos($rule->descr, 'Allow ICMP ping') !== false) {
            $icmp_rule_exists = true;
            break;
        }
    }
}

if ($icmp_rule_exists) {
    echo "ICMP ping rule already exists, no changes needed\n";
    exit(0);
}

// Create new ICMP rule
$new_rule = $config->filter->addChild('rule');
$new_rule->addChild('type', 'pass');
$new_rule->addChild('interface', 'wan');
$new_rule->addChild('ipprotocol', 'inet');
$new_rule->addChild('protocol', 'icmp');
$new_rule->addChild('source')->addChild('any');
$new_rule->addChild('destination')->addChild('any');
$new_rule->addChild('descr', 'Allow ICMP ping - Added by management agent');
$new_rule->addChild('created')->addChild('time', time());
$new_rule->addChild('created')->addChild('username', 'management-agent');

// Save the updated configuration
$dom = new DOMDocument('1.0', 'UTF-8');
$dom->formatOutput = true;
$dom->loadXML($config->asXML());

if (!$dom->save($config_file)) {
    echo "ERROR: Failed to save configuration file\n";
    exit(1);
}

echo "ICMP ping rule added successfully\n";

// Reload firewall configuration
echo "Reloading firewall configuration...\n";
$reload_output = shell_exec('/usr/local/etc/rc.filter_configure 2>&1');
if ($reload_output === null) {
    echo "WARNING: Failed to reload firewall configuration\n";
    echo "You may need to manually reload from the web interface\n";
} else {
    echo "Firewall configuration reloaded\n";
}

// Test the rule by pinging a known host
echo "Testing ICMP connectivity...\n";
$ping_result = shell_exec('ping -c 1 8.8.8.8 2>&1');
if (strpos($ping_result, '1 packets transmitted, 1 received') !== false) {
    echo "SUCCESS: ICMP ping is working\n";
} else {
    echo "WARNING: ICMP test failed, rule may need manual verification\n";
}

echo "\nFirewall rule summary:\n";
echo "- Type: Pass\n";
echo "- Interface: WAN\n";
echo "- Protocol: ICMP\n";
echo "- Source: Any\n";
echo "- Destination: Any\n";
echo "- Description: Allow ICMP ping - Added by management agent\n";
echo "\nPing should now be enabled on this firewall\n";
?>