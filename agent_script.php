<?php
require_once __DIR__ . '/inc/bootstrap_agent.php';

if (!isset($_GET['token'])) {
    header('HTTP/1.1 400 Bad Request');
    die('Missing enrollment token');
}

$token = $_GET['token'];

$stmt = db()->prepare("SELECT * FROM enrollment_tokens WHERE token = ? AND expires_at > NOW()");
$stmt->execute([$token]);
$tokenData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tokenData) {
    header('HTTP/1.1 404 Not Found');
    die('Invalid or expired token');
}

header('Content-Type: text/plain');
header('Content-Disposition: attachment; filename="opnsense_agent_install.sh"');
?>
#!/bin/sh
# OPNsense Monitoring Agent Installer v1.0.7
# Generated on <?php echo date('Y-m-d H:i:s'); ?>

set -e

echo "Starting OPNsense Agent Installation..."

# Update package database
echo "Updating package database..."
pkg update -f

# Install required packages
echo "Installing curl..."
pkg install -y curl

# Create agent directory
AGENT_DIR="/usr/local/opnsense-agent"
echo "Creating agent directory: $AGENT_DIR"
mkdir -p $AGENT_DIR

# Get system hostname
HOSTNAME=$(hostname)
echo "System hostname: $HOSTNAME"

# Get OPNsense version
echo "Detecting OPNsense version..."
OPNSENSE_VERSION="unknown"
if [ -f /usr/local/opnsense/version/opnsense ]; then
    OPNSENSE_VERSION=$(cat /usr/local/opnsense/version/opnsense 2>/dev/null || echo "unknown")
elif [ -f /usr/local/opnsense/version/core ]; then
    OPNSENSE_VERSION=$(cat /usr/local/opnsense/version/core 2>/dev/null || echo "unknown")
fi
echo "OPNsense version: $OPNSENSE_VERSION"

# Function to get external WAN IP
get_external_ip() {
    for service in "https://ipv4.icanhazip.com" "https://api.ipify.org"; do
        ip=$(curl -s --connect-timeout 10 --max-time 15 "$service" 2>/dev/null | tr -d " \t\n\r")
        if echo "$ip" | grep -E "^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$" > /dev/null; then
            echo "$ip"
            return 0
        fi
    done
    echo "unknown"
}

# Function to get LAN IP
get_lan_ip() {
    # Look for private IP ranges
    lan_ip=$(ifconfig | grep -E "inet (192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)" | grep -v "127\." | head -1 | awk '{print $2}')
    
    if echo "$lan_ip" | grep -E "^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)" > /dev/null 2>&1; then
        echo "$lan_ip"
        return 0
    fi
    
    # Try common LAN interfaces
    for iface in igb1 em1 vtnet1 re1 bge1; do
        lan_ip=$(ifconfig "$iface" 2>/dev/null | grep "inet " | grep -v "127\." | awk '{print $2}' | head -1)
        if echo "$lan_ip" | grep -E "^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)" > /dev/null 2>&1; then
            echo "$lan_ip"
            return 0
        fi
    done
    
    echo "unknown"
}

# Function to get IPv6 address
get_ipv6_address() {
    ipv6=$(curl -s --connect-timeout 10 --max-time 15 "https://ipv6.icanhazip.com" 2>/dev/null | tr -d " \t\n\r")
    if echo "$ipv6" | grep -E "^[0-9a-fA-F:]+$" > /dev/null && [ "$ipv6" != "::1" ]; then
        echo "$ipv6"
        return 0
    fi
    
    ipv6=$(ifconfig | grep "inet6 " | grep -v "fe80:" | grep -v "::1" | awk '{print $2}' | head -1)
    if [ ! -z "$ipv6" ]; then
        echo "$ipv6"
        return 0
    fi
    
    echo "unknown"
}

# Get network information
echo "=== Network Detection ==="
WAN_IP=$(get_external_ip)
LAN_IP=$(get_lan_ip)
IPV6_ADDRESS=$(get_ipv6_address)

echo "Network detection complete:"
echo "  WAN IP: $WAN_IP"
echo "  LAN IP: $LAN_IP"
echo "  IPv6: $IPV6_ADDRESS"

# Create monitoring script
echo "Creating monitoring script..."
cat > $AGENT_DIR/monitor.sh << 'MONITOR_EOF'
#!/bin/sh
# OPNsense Monitoring Agent v1.0.7

CHECKIN_URL="https://opn.agit8or.net/agent_checkin.php"
AGENT_VERSION="1.0.7"
HOSTNAME=$(hostname)

get_external_ip() {
    for service in "https://ipv4.icanhazip.com" "https://api.ipify.org"; do
        ip=$(curl -s --connect-timeout 5 --max-time 10 "$service" 2>/dev/null | tr -d " \t\n\r")
        if echo "$ip" | grep -E "^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$" > /dev/null; then
            echo "$ip"
            return 0
        fi
    done
    echo "unknown"
}

get_lan_ip() {
    lan_ip=$(ifconfig | grep -E "inet (192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)" | grep -v "127\." | head -1 | awk '{print $2}')
    if echo "$lan_ip" | grep -E "^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)" > /dev/null 2>&1; then
        echo "$lan_ip"
        return 0
    fi
    
    for iface in igb1 em1 vtnet1 re1 bge1; do
        lan_ip=$(ifconfig "$iface" 2>/dev/null | grep "inet " | grep -v "127\." | awk '{print $2}' | head -1)
        if echo "$lan_ip" | grep -E "^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)" > /dev/null 2>&1; then
            echo "$lan_ip"
            return 0
        fi
    done
    echo "unknown"
}

get_ipv6_address() {
    ipv6=$(curl -s --connect-timeout 5 --max-time 10 "https://ipv6.icanhazip.com" 2>/dev/null | tr -d " \t\n\r")
    if echo "$ipv6" | grep -E "^[0-9a-fA-F:]+$" > /dev/null && [ "$ipv6" != "::1" ]; then
        echo "$ipv6"
        return 0
    fi
    ipv6=$(ifconfig | grep "inet6 " | grep -v "fe80:" | grep -v "::1" | awk '{print $2}' | head -1)
    if [ ! -z "$ipv6" ]; then
        echo "$ipv6"
        return 0
    fi
    echo "unknown"
}

run_check() {
    WAN_IP=$(get_external_ip)
    LAN_IP=$(get_lan_ip)
    IPV6_ADDRESS=$(get_ipv6_address)
    
    OPNSENSE_VERSION="unknown"
    if [ -f /usr/local/opnsense/version/opnsense ]; then
        OPNSENSE_VERSION=$(cat /usr/local/opnsense/version/opnsense 2>/dev/null || echo "unknown")
    elif [ -f /usr/local/opnsense/version/core ]; then
        OPNSENSE_VERSION=$(cat /usr/local/opnsense/version/core 2>/dev/null || echo "unknown")
    fi
    
    CPU_LOAD=$(uptime | awk -F"load averages: " '{print $2}' | awk '{print $1}' | tr -d ",")
    DISK_USAGE=$(df / | tail -1 | awk '{print $5}' | tr -d "%")
    
    DATA="hostname=$HOSTNAME&agent_version=$AGENT_VERSION&wan_ip=$WAN_IP&lan_ip=$LAN_IP&ipv6_address=$IPV6_ADDRESS&opnsense_version=$OPNSENSE_VERSION&cpu_load=$CPU_LOAD&disk_usage=$DISK_USAGE"
    
    curl -s -X POST -d "$DATA" "$CHECKIN_URL" > /dev/null 2>&1
}

run_check
MONITOR_EOF

chmod +x $AGENT_DIR/monitor.sh

# Test the monitoring script
echo "Testing monitoring script..."
$AGENT_DIR/monitor.sh

# Set up cron job
echo "Setting up cron job..."
(crontab -l 2>/dev/null | grep -v "opnsense-agent"; echo "*/5 * * * * /usr/local/opnsense-agent/monitor.sh") | crontab -

echo ""
echo "=== Installation Complete ==="
echo "Agent installed successfully!"
echo "Hostname: $HOSTNAME"
echo "WAN IP: $WAN_IP"
echo "LAN IP: $LAN_IP" 
echo "IPv6: $IPV6_ADDRESS"
echo "OPNsense Version: $OPNSENSE_VERSION"
echo ""

# Enroll with the server
echo "Enrolling with management server..."
curl -s -X POST -d "token=<?php echo $token; ?>&hostname=$HOSTNAME&wan_ip=$WAN_IP&lan_ip=$LAN_IP&ipv6_address=$IPV6_ADDRESS&opnsense_version=$OPNSENSE_VERSION" "https://opn.agit8or.net/api/enroll.php" > /dev/null 2>&1

echo "Enrollment completed successfully!"
