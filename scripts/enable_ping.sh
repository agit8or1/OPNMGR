#!/bin/sh

# Script to enable ICMP ping on OPNsense firewall
# This will be executed by the tunnel agent

echo "$(date): Enabling ICMP ping on OPNsense firewall..."

# Create the PHP script on the firewall
cat > /tmp/enable_ping.php << 'EOF'
<?php
/**
 * Enable ICMP ping on OPNsense firewall
 */

echo "Enabling ICMP ping on OPNsense...\n";

// Method 1: Try using OPNsense API if available
$api_result = shell_exec('configctl firewall reload 2>&1');

// Method 2: Use pfctl to add ICMP rule directly
echo "Adding ICMP rule using pfctl...\n";

// Create temporary rule file
$rule_content = "pass in on \$wan_if inet proto icmp from any to any keep state label \"Allow ICMP ping\"\n";
file_put_contents('/tmp/icmp_rule.conf', $rule_content);

// Load the rule
$pfctl_result = shell_exec('pfctl -f /tmp/icmp_rule.conf 2>&1');
echo "pfctl result: $pfctl_result\n";

// Method 3: Add permanent rule to config if possible
if (file_exists('/conf/config.xml')) {
    echo "Adding permanent ICMP rule to configuration...\n";
    
    // Load and modify config
    $config = file_get_contents('/conf/config.xml');
    
    // Check if ICMP rule already exists
    if (strpos($config, 'Allow ICMP ping') === false) {
        // Add ICMP rule before closing filter tag
        $icmp_rule = '
  <rule>
    <type>pass</type>
    <interface>wan</interface>
    <ipprotocol>inet</ipprotocol>
    <protocol>icmp</protocol>
    <source><any/></source>
    <destination><any/></destination>
    <descr>Allow ICMP ping - Added by management agent</descr>
  </rule>';
        
        $config = str_replace('</filter>', $icmp_rule . '</filter>', $config);
        
        // Backup original
        copy('/conf/config.xml', '/conf/config-backup-' . date('Y-m-d-H-i-s') . '.xml');
        
        // Save new config
        file_put_contents('/conf/config.xml', $config);
        
        // Reload configuration
        shell_exec('/usr/local/etc/rc.filter_configure 2>&1');
        
        echo "ICMP rule added to permanent configuration\n";
    } else {
        echo "ICMP rule already exists in configuration\n";
    }
}

// Test ping
echo "Testing ICMP...\n";
$ping_test = shell_exec('ping -c 1 8.8.8.8 2>&1');
if (strpos($ping_test, '1 packets transmitted, 1 received') !== false) {
    echo "SUCCESS: ICMP ping is working\n";
} else {
    echo "ICMP test result: $ping_test\n";
}

echo "ICMP ping configuration completed\n";
?>
EOF

# Execute the PHP script
php /tmp/enable_ping.php

# Clean up
rm -f /tmp/enable_ping.php /tmp/icmp_rule.conf

echo "$(date): ICMP ping enable script completed"