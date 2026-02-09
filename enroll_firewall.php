<?php
require_once __DIR__ . '/inc/db.php';

// Handle script download requests
if (isset($_GET['action']) && $_GET['action'] === 'download' && isset($_GET['token'])) {
    $token = $_GET['token'];
    
    // Generate the enrollment script
    $panel_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    
    $script = '#!/bin/bash

# OPNsense Firewall Enrollment Script
# This script will enroll your OPNsense firewall with the management panel

set -e

# Configuration
PANEL_URL="' . $panel_url . '"
ENROLLMENT_TOKEN="' . $token . '"
FIREWALL_HOSTNAME=$(hostname)
FIREWALL_IP=$(curl -s -4 ifconfig.me 2>/dev/null || curl -s -4 icanhazip.com 2>/dev/null || ip route get 8.8.8.8 | grep -oP \'src \\K[^ ]+\' | head -1 || hostname -I | awk \'{for(i=1;i<=NF;i++) if($i ~ /^[0-9]+\\.[0-9]+\\.[0-9]+\\.[0-9]+$/) {print $i; exit}}\' || hostname -I | awk \'{print $1}\')

echo "=== OPNsense Firewall Enrollment ==="
echo "Panel URL: $PANEL_URL"
echo "Firewall Hostname: $FIREWALL_HOSTNAME"
echo "Firewall IP: $FIREWALL_IP"
echo ""

# Check if we are running on OPNsense (multiple methods)
if [ ! -f "/usr/local/etc/opnsense-rc" ] && [ ! -f "/usr/local/opnsense/version/opnsense" ] && [ ! -f "/conf/config.xml" ] && ! command -v opnsense-version >/dev/null 2>&1; then
    echo "WARNING: Cannot verify this is an OPNsense firewall, but continuing anyway..."
    echo "If this fails, please ensure you are running this on an OPNsense system."
fi

echo "Installing required packages..."
pkg update
pkg install -y curl jq

echo "Generating unique hardware ID..."

# Generate hardware ID using multiple methods for reliability
HARDWARE_ID=""

# Method 1: Try SMBIOS UUID (most reliable)
if command -v dmidecode >/dev/null 2>&1; then
    UUID=$(dmidecode -s system-uuid 2>/dev/null | tr "[:upper:]" "[:lower:]")
    if [[ $UUID && $UUID != "not available" && $UUID != "not present" ]]; then
        HARDWARE_ID="uuid-$UUID"
    fi
fi

# Method 2: Try primary network interface MAC
if [[ -z "$HARDWARE_ID" ]]; then
    # Get the primary interface (usually the one with default route)
    PRIMARY_IF=$(route -n get default 2>/dev/null | grep interface: | awk "{print \$2}")
    if [[ -n "$PRIMARY_IF" ]]; then
        MAC=$(ifconfig "$PRIMARY_IF" 2>/dev/null | grep ether | awk "{print \$2}" | tr "[:upper:]" "[:lower:]")
        if [[ -n "$MAC" ]]; then
            HARDWARE_ID="mac-$MAC"
        fi
    fi
fi

# Method 3: Try motherboard serial
if [[ -z "$HARDWARE_ID" ]] && command -v dmidecode >/dev/null 2>&1; then
    SERIAL=$(dmidecode -s baseboard-serial-number 2>/dev/null)
    if [[ $SERIAL && $SERIAL != "Not Available" && $SERIAL != "Not Specified" ]]; then
        HARDWARE_ID="mb-$SERIAL"
    fi
fi

# Method 4: Fallback to hostname + MAC
if [[ -z "$HARDWARE_ID" ]]; then
    FIRST_MAC=$(ifconfig | grep ether | head -1 | awk "{print \$2}" | tr "[:upper:]" "[:lower:]")
    if [[ -n "$FIREWALL_HOSTNAME" && -n "$FIRST_MAC" ]]; then
        HARDWARE_ID="host-${FIREWALL_HOSTNAME}-${FIRST_MAC}"
    fi
fi

# Final fallback
if [[ -z "$HARDWARE_ID" ]]; then
    HARDWARE_ID="fallback-$(hostname)-$(date +%s)"
fi

echo "Hardware ID: $HARDWARE_ID"
echo "Enrolling firewall with management panel..."

# Create JSON payload
JSON_PAYLOAD=$(cat <<EOF
{
    "hostname": "$FIREWALL_HOSTNAME",
    "hardware_id": "$HARDWARE_ID",
    "ip_address": "$FIREWALL_IP",
    "wan_ip": "$FIREWALL_IP",
    "token": "$ENROLLMENT_TOKEN"
}
EOF
)

# Send enrollment request
response=$(curl -s -X POST "$PANEL_URL/enroll_firewall.php" \
    -H "Content-Type: application/json" \
    -d "$JSON_PAYLOAD")

# Save response for later use
echo "$response" > /tmp/enrollment_response.json

if echo "$response" | jq -e .success >/dev/null 2>&1; then
    echo "✅ Firewall enrolled successfully!"
    echo "You can now manage this firewall from the panel."
else
    echo "❌ Enrollment failed:"
    echo "$response"
    exit 1
fi

echo ""
echo "=== Enrollment Complete ==="
echo "Your firewall has been enrolled and is now being monitored."

# Download and install the separated monitoring architecture
echo ""
echo "Installing separated monitoring architecture..."
echo "📦 Downloading Main Agent v3.0..."
fetch -o /tmp/opnsense_agent_v3.sh "$PANEL_URL/downloads/opnsense_agent_v3.sh"

echo "📦 Downloading Independent Updater v2.0..."
fetch -o /tmp/opnsense_updater_v2.sh "$PANEL_URL/downloads/opnsense_updater_v2.sh"

if [ -f /tmp/opnsense_agent_v3.sh ] && [ -f /tmp/opnsense_updater_v2.sh ]; then
    chmod +x /tmp/opnsense_agent_v3.sh
    chmod +x /tmp/opnsense_updater_v2.sh
    
    echo "🚀 Installing Main Agent v3.0 (with cleanup)..."
    /tmp/opnsense_agent_v3.sh install
    
    echo "🛡️  Installing Independent Updater v2.0..."
    /tmp/opnsense_updater_v2.sh install
    
    echo "✅ Separated monitoring architecture installed successfully!"
    echo "🔄 Main Agent v3.0: Handles monitoring and check-ins (every 5 minutes)"
    echo "🛡️  Independent Updater v2.0: Monitors for updates and fixes (every 60 seconds)"
    echo "� Automatic agent replacement if main agent fails"
    echo "📊 Complete system resilience and self-healing"
    
    # Show installed services
    echo ""
    echo "Monitoring services:"
    crontab -l | grep opnsense_agent || echo "⚠️  No agent cron job found"
    if pgrep -f "opnsense_updater" > /dev/null; then
        echo "✅ Independent updater daemon running (PID: $(pgrep -f opnsense_updater))"
    else
        echo "⚠️  Independent updater daemon not running"
    fi
    
    # Clean up
    rm -f /tmp/opnsense_agent_v3.sh /tmp/opnsense_updater_v2.sh
else
    echo "⚠️  Warning: Could not download separated monitoring architecture"
    echo "Your firewall is enrolled but monitoring may not function correctly"
fi

# Download and install the tunnel agent
echo ""
echo "Installing tunnel agent for reverse proxy access..."

# Get the firewall ID from the enrollment response
FIREWALL_ID=""
if [ -f /tmp/enrollment_response.json ]; then
    FIREWALL_ID=$(cat /tmp/enrollment_response.json | grep -o \'"firewall_id":[0-9]*\' | cut -d: -f2)
fi

if [ -n "$FIREWALL_ID" ]; then
    fetch -o /tmp/tunnel_agent.sh "$PANEL_URL/download_tunnel_agent.php?firewall_id=$FIREWALL_ID"
    
    if [ -f /tmp/tunnel_agent.sh ]; then
        chmod +x /tmp/tunnel_agent.sh
        
        # Run the tunnel agent installer
        /tmp/tunnel_agent.sh install
        
        echo "✅ Tunnel agent installed successfully!"
        echo "Tunnel agent will maintain reverse connection for web UI access."
        
        # Clean up
        rm -f /tmp/tunnel_agent.sh
    else
        echo "⚠️  Warning: Could not download tunnel agent"
        echo "Reverse proxy access may not be available"
    fi
else
    echo "⚠️  Warning: Could not determine firewall ID for tunnel agent"
fi

echo ""
echo "Return to the management panel to see your firewall status."

# Clean up temporary files
rm -f /tmp/enrollment_response.json
';

    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="opnsense_enroll.sh"');
    echo $script;
    exit;
}

header('Content-Type: application/json');

// Get the JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit;
}

$hostname = trim($input['hostname'] ?? '');
$hardware_id = trim($input['hardware_id'] ?? '');
$ip_address = trim($input['ip_address'] ?? '');
$wan_ip = trim($input['wan_ip'] ?? '');
$token = trim($input['token'] ?? '');

// Validate inputs
if (empty($hostname) || empty($ip_address) || empty($token)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Validate IP address
if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
    echo json_encode(['success' => false, 'message' => 'Invalid IP address']);
    exit;
}

// Check enrollment token
$stmt = $DB->prepare("SELECT id, expires_at FROM enrollment_tokens WHERE token = ? AND expires_at > NOW() AND used = FALSE");
$stmt->execute([$token]);
$token_record = $stmt->fetch();

if (!$token_record) {
    echo json_encode(['success' => false, 'message' => 'Invalid or expired enrollment token']);
    exit;
}

try {
    // Check if firewall already exists by hardware_id (preferred) or hostname/ip
    $existing = null;
    
    if (!empty($hardware_id)) {
        $stmt = $DB->prepare('SELECT id FROM firewalls WHERE hardware_id = ?');
        $stmt->execute([$hardware_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$existing) {
        $stmt = $DB->prepare('SELECT id FROM firewalls WHERE hostname = ? OR ip_address = ?');
        $stmt->execute([$hostname, $ip_address]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($existing) {
        // Update existing firewall instead of failing
        $customer_name = !empty($existing['customer_name']) ? $existing['customer_name'] : 'AGIT8OR';
        $stmt = $DB->prepare('UPDATE firewalls SET hostname = ?, hardware_id = ?, ip_address = ?, wan_ip = ?, customer_name = ?, last_checkin = NOW(), status = ? WHERE id = ?');
        $stmt->execute([$hostname, $hardware_id, $ip_address, $wan_ip, $customer_name, 'online', $existing['id']]);
        
        // Mark the enrollment token as used
        $stmt = $DB->prepare("UPDATE enrollment_tokens SET used = TRUE WHERE token = ?");
        $stmt->execute([$token]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Firewall re-enrolled successfully (updated existing record)',
            'firewall_id' => $existing['id']
        ]);
    } else {
        // Add new firewall
        $customer_name = 'AGIT8OR'; // Default customer name
        $stmt = $DB->prepare('INSERT INTO firewalls (hostname, hardware_id, ip_address, wan_ip, customer_name, status, last_checkin) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$hostname, $hardware_id, $ip_address, $wan_ip, $customer_name, 'online']);
        
        // Mark the enrollment token as used
        $stmt = $DB->prepare("UPDATE enrollment_tokens SET used = TRUE WHERE token = ?");
        $stmt->execute([$token]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Firewall enrolled successfully',
            'firewall_id' => $DB->lastInsertId()
        ]);
    }

} catch (Exception $e) {
    error_log("enroll_firewall.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
?>
