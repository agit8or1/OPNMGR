#!/usr/bin/env php
<?php
/**
 * Setup Permanent SSH Rule
 * Creates ONE permanent rule allowing SSH from manager server
 * Removes all temporary SSH rules
 */

require_once __DIR__ . '/../inc/bootstrap_agent.php';

$MANAGER_IP = '184.175.206.229'; // opn.agit8or.net
$RULE_DESCRIPTION = 'Allow SSH from OPNManager - PERMANENT';

echo "Setting up permanent SSH rule...\n";

// Get firewall info
$stmt = db()->query("SELECT id, hostname, ip_address FROM firewalls WHERE status = 'online' LIMIT 1");
$firewall = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$firewall) {
    die("No online firewalls found\n");
}

$firewall_id = $firewall['id'];
$hostname = $firewall['hostname'];

echo "Target firewall: #{$firewall_id} ({$hostname})\n";

// Step 1: Create the permanent SSH rule via OPNsense API
$setup_rule_script = <<<'SCRIPT'
#!/bin/sh
# Setup permanent SSH rule for OPNManager access

MANAGER_IP="184.175.206.229"
RULE_DESC="Allow SSH from OPNManager - PERMANENT"

# Use OPNsense API to create the rule
# Note: This needs to be done via the OPNsense web interface or configd
# For now, we'll create a simple pf rule that persists

# Add to /conf/config.xml via PHP script on firewall
cat > /tmp/add_ssh_rule.php << 'EOF'
<?php
require_once("config.inc");
require_once("filter.inc");

$manager_ip = "184.175.206.229";
$rule_desc = "Allow SSH from OPNManager - PERMANENT";

// Check if rule already exists
$rule_exists = false;
if (isset($config['filter']['rule'])) {
    foreach ($config['filter']['rule'] as $rule) {
        if (isset($rule['descr']) && $rule['descr'] == $rule_desc) {
            $rule_exists = true;
            echo "Rule already exists\n";
            break;
        }
    }
}

if (!$rule_exists) {
    // Create new rule
    $new_rule = array(
        'type' => 'pass',
        'interface' => 'wan',
        'ipprotocol' => 'inet',
        'protocol' => 'tcp',
        'source' => array(
            'address' => $manager_ip
        ),
        'destination' => array(
            'address' => '(self)',
            'port' => '22'
        ),
        'descr' => $rule_desc,
        'created' => array(
            'time' => time(),
            'username' => 'opnmanager@184.175.206.229'
        )
    );
    
    if (!isset($config['filter']['rule'])) {
        $config['filter']['rule'] = array();
    }
    
    // Add at beginning of rules for priority
    array_unshift($config['filter']['rule'], $new_rule);
    
    write_config("Added permanent SSH rule for OPNManager");
    filter_configure();
    
    echo "Permanent SSH rule created successfully\n";
} else {
    echo "Permanent rule already exists - no action needed\n";
}
EOF

php /tmp/add_ssh_rule.php
rm /tmp/add_ssh_rule.php

echo "Permanent SSH rule setup complete"
SCRIPT;

// Queue the setup command
$stmt = db()->prepare("
    INSERT INTO firewall_commands (firewall_id, command, description, command_type)
    VALUES (?, ?, ?, 'shell')
");
$stmt->execute([
    $firewall_id,
    $setup_rule_script,
    'Setup permanent SSH rule for OPNManager access'
]);
$command_id = db()->lastInsertId();

echo "Queued permanent rule setup command: #{$command_id}\n";
echo "Waiting for agent to execute...\n";

// Wait for command to complete
$max_wait = 180; // 3 minutes
$waited = 0;
while ($waited < $max_wait) {
    sleep(5);
    $waited += 5;
    
    $stmt = db()->prepare("SELECT status, result FROM firewall_commands WHERE id = ?");
    $stmt->execute([$command_id]);
    $cmd = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($cmd['status'] == 'completed') {
        echo "✓ Permanent rule setup completed!\n";
        echo "Result: " . $cmd['result'] . "\n";
        break;
    } elseif ($cmd['status'] == 'failed') {
        echo "✗ Setup failed: " . $cmd['result'] . "\n";
        exit(1);
    }
    
    echo "  Still waiting... ({$waited}s)\n";
}

if ($waited >= $max_wait) {
    echo "⚠ Timeout waiting for setup command\n";
    exit(1);
}

// Step 2: Clean up all temporary SSH rules
echo "\nCleaning up temporary SSH rules...\n";

$cleanup_script = <<<'SCRIPT'
#!/bin/sh
# Remove all temporary SSH rules

cat > /tmp/cleanup_temp_rules.php << 'EOF'
<?php
require_once("config.inc");
require_once("filter.inc");

$removed = 0;

if (isset($config['filter']['rule'])) {
    $new_rules = array();
    foreach ($config['filter']['rule'] as $rule) {
        // Keep rule if it's NOT a temporary SSH access rule
        if (!isset($rule['descr']) || 
            !preg_match('/^Temporary SSH access/', $rule['descr'])) {
            $new_rules[] = $rule;
        } else {
            $removed++;
            echo "Removing: " . $rule['descr'] . "\n";
        }
    }
    
    if ($removed > 0) {
        $config['filter']['rule'] = $new_rules;
        write_config("Removed {$removed} temporary SSH rules - using permanent rule now");
        filter_configure();
        echo "Removed {$removed} temporary SSH rules\n";
    } else {
        echo "No temporary rules found to remove\n";
    }
}
EOF

php /tmp/cleanup_temp_rules.php
rm /tmp/cleanup_temp_rules.php
SCRIPT;

$stmt = db()->prepare("
    INSERT INTO firewall_commands (firewall_id, command, description, command_type)
    VALUES (?, ?, ?, 'shell')
");
$stmt->execute([
    $firewall_id,
    $cleanup_script,
    'Clean up temporary SSH rules'
]);
$cleanup_id = db()->lastInsertId();

echo "Queued cleanup command: #{$cleanup_id}\n";
echo "Waiting for cleanup...\n";

$waited = 0;
while ($waited < $max_wait) {
    sleep(5);
    $waited += 5;
    
    $stmt = db()->prepare("SELECT status, result FROM firewall_commands WHERE id = ?");
    $stmt->execute([$cleanup_id]);
    $cmd = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($cmd['status'] == 'completed') {
        echo "✓ Cleanup completed!\n";
        echo "Result: " . $cmd['result'] . "\n";
        break;
    } elseif ($cmd['status'] == 'failed') {
        echo "✗ Cleanup failed: " . $cmd['result'] . "\n";
        exit(1);
    }
    
    echo "  Still waiting... ({$waited}s)\n";
}

echo "\n✓ Permanent SSH rule setup complete!\n";
echo "  - Permanent rule created allowing SSH from {$MANAGER_IP}\n";
echo "  - All temporary rules removed\n";
echo "  - SSH tunnels can now stay open permanently\n";
echo "  - Commands can be sent directly via SSH\n";
