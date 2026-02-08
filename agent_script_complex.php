<?php
require_once __DIR__ . '/inc/db.php';

if (!isset($_GET['token'])) {
    header('HTTP/1.1 400 Bad Request');
    die('Missing enrollment token');
}

$token = $_GET['token'];

// Verify the token exists and is valid
$stmt = $DB->prepare("SELECT * FROM enrollment_tokens WHERE token = ? AND expires_at > NOW()");
$stmt->execute([$token]);
$tokenData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tokenData) {
    header('HTTP/1.1 404 Not Found');
    die('Invalid or expired token');
}

header('Content-Type: text/plain');
header('Content-Disposition: attachment; filename="opnsense_agent_install.sh"');

echo "#!/bin/bash
# OPNsense Monitoring Agent Installer v1.0.4
# Generated on " . date('Y-m-d H:i:s') . "

set -e

echo \"Starting OPNsense Agent Installation...\"

# Update package database
echo \"Updating package database...\"
pkg update -f

# Install required packages (try jq, fallback to alternatives)
echo \"Installing required packages...\"
pkg install -y curl
if pkg install -y jq 2>/dev/null; then
    echo \"jq installed successfully\"
    JQ_AVAILABLE=true
else
    echo \"jq not available, will use alternative JSON parsing\"
    JQ_AVAILABLE=false
fi

# Create agent directory
AGENT_DIR=\"/usr/local/opnsense-agent\"
echo \"Creating agent directory: \$AGENT_DIR\"
mkdir -p \$AGENT_DIR

# Get system hostname
HOSTNAME=\$(hostname)
echo \"System hostname: \$HOSTNAME\"

# Get OPNsense version with better error handling
echo \"Detecting OPNsense version...\"
OPNSENSE_VERSION=\"unknown\"
if [ -f /usr/local/opnsense/version/opnsense ]; then
    OPNSENSE_VERSION=\$(cat /usr/local/opnsense/version/opnsense 2>/dev/null || echo \"unknown\")
elif [ -f /usr/local/opnsense/version/core ]; then
    OPNSENSE_VERSION=\$(cat /usr/local/opnsense/version/core 2>/dev/null || echo \"unknown\")
fi
echo \"OPNsense version: \$OPNSENSE_VERSION\"

# Function to get external WAN IP with multiple methods
get_external_ip() {
    local ip
    
    # Try multiple external IP services
    for service in \"https://ipv4.icanhazip.com\" \"https://api.ipify.org\" \"https://checkip.amazonaws.com\"; do
        echo \"Trying external IP service: \$service\"
        ip=\$(curl -s --connect-timeout 10 --max-time 15 \"\$service\" 2>/dev/null | tr -d '[:space:]')
        if [[ \$ip =~ ^[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}\$ ]]; then
            echo \"External WAN IP detected: \$ip\"
            echo \"\$ip\"
            return 0
        fi
    done
    
    echo \"Could not detect external IP\"
    echo \"unknown\"
    return 1
}

# Function to get primary LAN IP (internal network interface)
get_lan_ip() {
    local lan_ip
    
    echo \"Detecting LAN IP address...\"
    
    # Method 1: Try to find the primary internal interface
    # Look for interfaces with private IP ranges and exclude loopback
    lan_ip=\$(ifconfig | grep -E 'inet (192\\.168\\.|10\\.|172\\.(1[6-9]|2[0-9]|3[01])\\.)' | grep -v '127\\.' | head -1 | awk '{print \$2}')
    
    if [[ \$lan_ip =~ ^(192\\.168\\.|10\\.|172\\.(1[6-9]|2[0-9]|3[01])\\.) ]]; then
        echo \"LAN IP detected via private range scan: \$lan_ip\"
        echo \"\$lan_ip\"
        return 0
    fi
    
    # Method 2: Try to get the default gateway interface IP
    echo \"Trying default gateway interface method...\"
    local gateway_if=\$(route -n get default 2>/dev/null | grep 'interface:' | awk '{print \$2}')
    if [ ! -z \"\$gateway_if\" ]; then
        lan_ip=\$(ifconfig \"\$gateway_if\" 2>/dev/null | grep 'inet ' | grep -v '127\\.' | awk '{print \$2}' | head -1)
        if [[ \$lan_ip =~ ^(192\\.168\\.|10\\.|172\\.(1[6-9]|2[0-9]|3[01])\\.) ]]; then
            echo \"LAN IP detected via gateway interface \$gateway_if: \$lan_ip\"
            echo \"\$lan_ip\"
            return 0
        fi
    fi
    
    # Method 3: Try common OPNsense LAN interfaces
    echo \"Trying common OPNsense interface names...\"
    for iface in igb1 em1 vtnet1 bce1 re1 bge1; do
        lan_ip=\$(ifconfig \"\$iface\" 2>/dev/null | grep 'inet ' | grep -v '127\\.' | awk '{print \$2}' | head -1)
        if [[ \$lan_ip =~ ^(192\\.168\\.|10\\.|172\\.(1[6-9]|2[0-9]|3[01])\\.) ]]; then
            echo \"LAN IP detected on interface \$iface: \$lan_ip\"
            echo \"\$lan_ip\"
            return 0
        fi
    done
    
    # Method 4: Last resort - get first non-loopback IP that's not external
    echo \"Using fallback method for LAN IP detection...\"
    lan_ip=\$(ifconfig | grep 'inet ' | grep -v '127\\.' | awk '{print \$2}' | head -1)
    if [ ! -z \"\$lan_ip\" ]; then
        echo \"LAN IP detected via fallback: \$lan_ip\"
        echo \"\$lan_ip\"
        return 0
    fi
    
    echo \"Could not detect LAN IP\"
    echo \"unknown\"
    return 1
}

# Function to get IPv6 address
get_ipv6_address() {
    local ipv6
    
    echo \"Detecting IPv6 address...\"
    
    # Try external IPv6 service first
    ipv6=\$(curl -s --connect-timeout 10 --max-time 15 \"https://ipv6.icanhazip.com\" 2>/dev/null | tr -d '[:space:]')
    if [[ \$ipv6 =~ ^[0-9a-fA-F:]+\$ ]] && [[ \$ipv6 != *\"::1\"* ]]; then
        echo \"IPv6 address detected externally: \$ipv6\"
        echo \"\$ipv6\"
        return 0
    fi
    
    # Fallback to local interface IPv6
    ipv6=\$(ifconfig | grep 'inet6 ' | grep -v 'fe80:' | grep -v '::1' | awk '{print \$2}' | head -1)
    if [ ! -z \"\$ipv6\" ]; then
        echo \"IPv6 address detected locally: \$ipv6\"
        echo \"\$ipv6\"
        return 0
    fi
    
    echo \"Could not detect IPv6 address\"
    echo \"unknown\"
    return 1
}

# Get network information
echo \"=== Network Detection ===\"
WAN_IP=\$(get_external_ip)
LAN_IP=\$(get_lan_ip)
IPV6_ADDRESS=\$(get_ipv6_address)

echo \"Network detection complete:\"
echo \"  WAN IP: \$WAN_IP\"
echo \"  LAN IP: \$LAN_IP\"
echo \"  IPv6: \$IPV6_ADDRESS\"

# Create monitoring script
echo \"Creating monitoring script...\"
cat > \$AGENT_DIR/monitor.sh << 'MONITOR_EOF'
#!/bin/bash
# OPNsense Monitoring Agent v1.0.4

# Configuration
CHECKIN_URL=\"https://opn.agit8or.net/agent_checkin.php\"
AGENT_VERSION=\"1.0.4\"
HOSTNAME=\$(hostname)

# Function to get system info
get_system_info() {
    local cpu_usage memory_usage disk_usage uptime
    
    # CPU usage (1 minute average)
    cpu_usage=\$(uptime | awk -F'load averages: ' '{print \$2}' | awk '{print \$1}' | tr -d ',')
    
    # Memory usage
    memory_usage=\$(vmstat | tail -1 | awk '{printf \"%.1f\", (\$4+\$5)/(\$4+\$5+\$6)*100}')
    
    # Disk usage for root filesystem
    disk_usage=\$(df / | tail -1 | awk '{print \$5}' | tr -d '%')
    
    # Uptime in seconds
    uptime=\$(sysctl -n kern.boottime | awk '{print \$4}' | tr -d ',')
    current_time=\$(date +%s)
    uptime=\$((current_time - uptime))
    
    echo \"{\
\\\"cpu_usage\\\": \\\"\$cpu_usage\\\", \
\\\"memory_usage\\\": \\\"\$memory_usage\\\", \
\\\"disk_usage\\\": \\\"\$disk_usage\\\", \
\\\"uptime\\\": \\\"\$uptime\\\"\
}\"
}

# Network detection functions (same as installer)
get_external_ip() {
    local ip
    for service in \"https://ipv4.icanhazip.com\" \"https://api.ipify.org\" \"https://checkip.amazonaws.com\"; do
        ip=\$(curl -s --connect-timeout 5 --max-time 10 \"\$service\" 2>/dev/null | tr -d '[:space:]')
        if [[ \$ip =~ ^[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}\$ ]]; then
            echo \"\$ip\"
            return 0
        fi
    done
    echo \"unknown\"
}

get_lan_ip() {
    local lan_ip
    
    # Method 1: Private IP ranges
    lan_ip=\$(ifconfig | grep -E 'inet (192\\.168\\.|10\\.|172\\.(1[6-9]|2[0-9]|3[01])\\.)' | grep -v '127\\.' | head -1 | awk '{print \$2}')
    if [[ \$lan_ip =~ ^(192\\.168\\.|10\\.|172\\.(1[6-9]|2[0-9]|3[01])\\.) ]]; then
        echo \"\$lan_ip\"
        return 0
    fi
    
    # Method 2: Gateway interface
    local gateway_if=\$(route -n get default 2>/dev/null | grep 'interface:' | awk '{print \$2}')
    if [ ! -z \"\$gateway_if\" ]; then
        lan_ip=\$(ifconfig \"\$gateway_if\" 2>/dev/null | grep 'inet ' | grep -v '127\\.' | awk '{print \$2}' | head -1)
        if [[ \$lan_ip =~ ^(192\\.168\\.|10\\.|172\\.(1[6-9]|2[0-9]|3[01])\\.) ]]; then
            echo \"\$lan_ip\"
            return 0
        fi
    fi
    
    # Method 3: Common interfaces
    for iface in igb1 em1 vtnet1 bce1 re1 bge1; do
        lan_ip=\$(ifconfig \"\$iface\" 2>/dev/null | grep 'inet ' | grep -v '127\\.' | awk '{print \$2}' | head -1)
        if [[ \$lan_ip =~ ^(192\\.168\\.|10\\.|172\\.(1[6-9]|2[0-9]|3[01])\\.) ]]; then
            echo \"\$lan_ip\"
            return 0
        fi
    done
    
    echo \"unknown\"
}

get_ipv6_address() {
    local ipv6
    ipv6=\$(curl -s --connect-timeout 5 --max-time 10 \"https://ipv6.icanhazip.com\" 2>/dev/null | tr -d '[:space:]')
    if [[ \$ipv6 =~ ^[0-9a-fA-F:]+\$ ]] && [[ \$ipv6 != *\"::1\"* ]]; then
        echo \"\$ipv6\"
        return 0
    fi
    ipv6=\$(ifconfig | grep 'inet6 ' | grep -v 'fe80:' | grep -v '::1' | awk '{print \$2}' | head -1)
    if [ ! -z \"\$ipv6\" ]; then
        echo \"\$ipv6\"
        return 0
    fi
    echo \"unknown\"
}

# Main monitoring function
run_check() {
    # Get current network info
    WAN_IP=\$(get_external_ip)
    LAN_IP=\$(get_lan_ip)
    IPV6_ADDRESS=\$(get_ipv6_address)
    
    # Get OPNsense version
    OPNSENSE_VERSION=\"unknown\"
    if [ -f /usr/local/opnsense/version/opnsense ]; then
        OPNSENSE_VERSION=\$(cat /usr/local/opnsense/version/opnsense 2>/dev/null || echo \"unknown\")
    elif [ -f /usr/local/opnsense/version/core ]; then
        OPNSENSE_VERSION=\$(cat /usr/local/opnsense/version/core 2>/dev/null || echo \"unknown\")
    fi
    
    # Get system info
    SYSTEM_INFO=\$(get_system_info)
    
    # Prepare data for checkin
    DATA=\"hostname=\$HOSTNAME&agent_version=\$AGENT_VERSION&wan_ip=\$WAN_IP&lan_ip=\$LAN_IP&ipv6_address=\$IPV6_ADDRESS&opnsense_version=\$OPNSENSE_VERSION&system_info=\$SYSTEM_INFO\"
    
    # Send checkin
    curl -s -X POST -d \"\$DATA\" \"\$CHECKIN_URL\" > /dev/null 2>&1
}

# Run the check
run_check
MONITOR_EOF

chmod +x \$AGENT_DIR/monitor.sh

# Test the monitoring script
echo \"Testing monitoring script...\"
\$AGENT_DIR/monitor.sh

# Set up cron job for monitoring (every 5 minutes)
echo \"Setting up cron job...\"
(crontab -l 2>/dev/null | grep -v 'opnsense-agent'; echo \"*/5 * * * * /usr/local/opnsense-agent/monitor.sh\") | crontab -

echo \"\"
echo \"=== Installation Complete ===\"
echo \"Agent installed successfully!\"
echo \"Hostname: \$HOSTNAME\"
echo \"WAN IP: \$WAN_IP\"
echo \"LAN IP: \$LAN_IP\"
echo \"IPv6: \$IPV6_ADDRESS\"
echo \"OPNsense Version: \$OPNSENSE_VERSION\"
echo \"\"
echo \"The agent will check in every 5 minutes.\"
echo \"You can manually run a check with: /usr/local/opnsense-agent/monitor.sh\"
echo \"\"

# Enroll with the server (using simple POST without requiring jq)
echo \"Enrolling with management server...\"
curl -s -X POST -d \"token=$token&hostname=\$HOSTNAME&wan_ip=\$WAN_IP&lan_ip=\$LAN_IP&ipv6_address=\$IPV6_ADDRESS&opnsense_version=\$OPNSENSE_VERSION\" \"https://opn.agit8or.net/api/enroll.php\" > /dev/null 2>&1

echo \"Enrollment completed successfully!\"
";
