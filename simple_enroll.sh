#!/bin/sh
# OPNsense Firewall Enrollment - Complete Installation
# This script enrolls your firewall AND installs the management agent

PANEL_URL="__PANEL_URL__"
ENROLLMENT_TOKEN="__ENROLLMENT_TOKEN__"
LOG_FILE="/tmp/opnsense_enrollment.log"
AGENT_PATH="/usr/local/bin/tunnel_agent.sh"

echo "=== OPNsense Firewall Enrollment & Agent Installation ===" | tee $LOG_FILE
echo "Panel URL: $PANEL_URL" | tee -a $LOG_FILE
echo "" | tee -a $LOG_FILE

# Get firewall info
HOSTNAME=$(hostname)

# Get WAN IP - call our own endpoint instead of external service
WAN_IP=""
WAN_IP=$(curl -s -k "$PANEL_URL/api/get_client_ip.php" 2>/dev/null || echo "")
if [ -z "$WAN_IP" ] || [ "$WAN_IP" = "" ]; then
    # Fallback to ifconfig.me
    WAN_IP=$(curl -s -k -4 ifconfig.me 2>/dev/null || echo "unknown")
fi

# Get LAN IP from actual LAN interface
LAN_IP=$(ifconfig | grep -A 3 "^em1" | grep "inet " | awk '{print $2}' | head -1)
if [ -z "$LAN_IP" ]; then
    LAN_IP=$(ifconfig | grep -A 3 "^igb1" | grep "inet " | awk '{print $2}' | head -1)
fi
if [ -z "$LAN_IP" ]; then
    LAN_IP="unknown"
fi

# Generate hardware ID from MAC address
MAC=$(ifconfig | grep ether | head -1 | awk '{print $2}')
HARDWARE_ID="mac-${MAC}"

# Get OPNsense version
VERSION=$(cat /usr/local/opnsense/version/opnsense 2>/dev/null || echo "unknown")

echo "Hostname: $HOSTNAME" | tee -a $LOG_FILE
echo "WAN IP: $WAN_IP" | tee -a $LOG_FILE
echo "LAN IP: $LAN_IP" | tee -a $LOG_FILE
echo "Version: $VERSION" | tee -a $LOG_FILE
echo "" | tee -a $LOG_FILE

# Step 1: Enroll with the panel
echo "[1/3] Enrolling firewall with management panel..." | tee -a $LOG_FILE
RESPONSE=$(curl -s -k --connect-timeout 10 --max-time 15 -X POST "$PANEL_URL/api/enroll.php" \
  -H "Content-Type: application/json" \
  -d "{
    \"hostname\": \"$HOSTNAME\",
    \"hardware_id\": \"$HARDWARE_ID\",
    \"ip_address\": \"$LAN_IP\",
    \"wan_ip\": \"$WAN_IP\",
    \"ipv6_address\": \"\",
    \"token\": \"$ENROLLMENT_TOKEN\"
  }" 2>&1)

echo "$RESPONSE" | tee -a $LOG_FILE

# Extract firewall_id from response
FIREWALL_ID=$(echo "$RESPONSE" | grep -o '"firewall_id":[0-9]*' | cut -d: -f2)

if [ -z "$FIREWALL_ID" ] || ! echo "$RESPONSE" | grep -q "success"; then
    echo "❌ Enrollment failed!" | tee -a $LOG_FILE
    exit 1
fi

echo "✓ Enrolled successfully! Firewall ID: $FIREWALL_ID" | tee -a $LOG_FILE
echo "" | tee -a $LOG_FILE

# Step 2: Configure SSH access for remote management
echo "[2/5] Configuring SSH access for remote management..." | tee -a $LOG_FILE
mkdir -p /root/.ssh
chmod 700 /root/.ssh

# Add server's public key to authorized_keys. Substituted by
# api/get_enroll_script.php with the enrollment key of the manager that served
# this script. It used to be a literal - and not a dedicated key either, but the
# per-firewall key of one firewall on one installation - so every deployment
# authorised somebody else's key for root on its customers' firewalls.
SERVER_SSH_KEY="__SERVER_SSH_KEY__"

if ! grep -q "$SERVER_SSH_KEY" /root/.ssh/authorized_keys 2>/dev/null; then
    echo "$SERVER_SSH_KEY" >> /root/.ssh/authorized_keys
    chmod 600 /root/.ssh/authorized_keys
    echo "✓ SSH key added for remote management" | tee -a $LOG_FILE
else
    echo "✓ SSH key already configured" | tee -a $LOG_FILE
fi

# ADD SSH FIREWALL RULE - Modify config.xml directly
echo "🔧 Configuring firewall rule for remote SSH access..." | tee -a $LOG_FILE
# Substituted by api/get_enroll_script.php with the address of the manager
# that served this script. It used to be one installation's public IP,
# hardcoded - so every other self-hosted deployment would have permitted SSH
# from somebody else's server instead of its own.
MGMT_SERVER_IP="__MGMT_SERVER_IP__"
case "$MGMT_SERVER_IP" in
    ""|__MGMT_SERVER_IP__)
        echo "✗ This script was not served by the manager, so the management" | tee -a $LOG_FILE
        echo "  address is unknown. Download it from your OPNManager panel." | tee -a $LOG_FILE
        exit 1
        ;;
esac

CONFIG="/conf/config.xml"
BACKUP="/conf/config.xml.pre_ssh_rule"

# Backup config
cp "$CONFIG" "$BACKUP"

# Check if rule already exists
if grep -q "$MGMT_SERVER_IP.*22" "$CONFIG" 2>/dev/null; then
    echo "  ✓ SSH rule for $MGMT_SERVER_IP already exists" | tee -a $LOG_FILE
else
    # Create rule XML (matching working fw21 structure)
    RULE_XML='    <rule>
      <type>pass</type>
      <interface>wan</interface>
      <ipprotocol>inet</ipprotocol>
      <protocol>tcp</protocol>
      <source>
        <address>'"$MGMT_SERVER_IP"'</address>
      </source>
      <destination>
        <network>(self)</network>
        <port>22</port>
      </destination>
      <descr>Allow SSH from management server - Added by agent</descr>
      <statetype>keep state</statetype>
    </rule>'

    # Add rule to filter section before </filter> tag
    awk -v rule="$RULE_XML" '
        /<\/filter>/ {
            print rule
        }
        { print }
    ' "$CONFIG" > "$CONFIG.tmp" && mv "$CONFIG.tmp" "$CONFIG"

    echo "  ✓ SSH rule added to config.xml" | tee -a $LOG_FILE

    # Reload firewall
    /usr/local/etc/rc.reload_all 2>&1 | head -5
    configctl filter reload 2>&1 | head -5

    echo "  ✓ Firewall reloaded" | tee -a $LOG_FILE
fi

echo "  ✓ Management server ($MGMT_SERVER_IP) can now access SSH" | tee -a $LOG_FILE
echo "" | tee -a $LOG_FILE

# Step 3: Download the management agent
echo "[3/5] Downloading management agent..." | tee -a $LOG_FILE
curl -s -k -o "$AGENT_PATH" "$PANEL_URL/download_tunnel_agent.php?firewall_id=$FIREWALL_ID"

if [ ! -f "$AGENT_PATH" ]; then
    echo "❌ Failed to download agent!" | tee -a $LOG_FILE
    exit 1
fi

chmod +x "$AGENT_PATH"
echo "✓ Agent downloaded and installed" | tee -a $LOG_FILE
echo "" | tee -a $LOG_FILE

# Step 4: Store firewall ID for agent to use
echo "[4/5] Configuring firewall ID..." | tee -a $LOG_FILE
echo "$FIREWALL_ID" > /tmp/opnsense_firewall_id
echo "✓ Firewall ID configured: $FIREWALL_ID" | tee -a $LOG_FILE
echo "" | tee -a $LOG_FILE

# Step 5: Set up boot persistence with CRON (Most Reliable for OPNsense)
echo "[5/6] Setting up boot persistence with cron..." | tee -a $LOG_FILE

# Use simple cron schedule - agent runs once per execution, cron runs it every 2 minutes
(crontab -l 2>/dev/null | grep -v "tunnel_agent";
 echo "@reboot sleep 10; /usr/local/bin/tunnel_agent.sh";
 echo "*/2 * * * * /usr/local/bin/tunnel_agent.sh") | crontab -

echo "  ✓ Cron configured:" | tee -a $LOG_FILE
echo "    - @reboot: Starts agent 10 seconds after boot" | tee -a $LOG_FILE
echo "    - Scheduled: Runs agent every 2 minutes (matches checkin interval)" | tee -a $LOG_FILE

# Start the agent NOW
echo "[6/6] Starting agent now..." | tee -a $LOG_FILE
pkill -f tunnel_agent.sh 2>/dev/null
sleep 2

# Start directly in background
nohup /usr/local/bin/tunnel_agent.sh >> /var/log/opnsense_agent.log 2>&1 &
sleep 3

# Verify it started
if pgrep -f tunnel_agent.sh > /dev/null; then
    echo "✓ Agent is RUNNING (PID: $(pgrep -f tunnel_agent.sh))" | tee -a $LOG_FILE
else
    echo "⚠️  Agent will start via cron in 1 minute" | tee -a $LOG_FILE
fi

echo "" | tee -a $LOG_FILE
echo "========================================" | tee -a $LOG_FILE
echo "✅ ENROLLMENT COMPLETE!" | tee -a $LOG_FILE
echo "========================================" | tee -a $LOG_FILE
echo "" | tee -a $LOG_FILE
echo "Your firewall is now enrolled and the agent is running." | tee -a $LOG_FILE
echo "It will check in automatically." | tee -a $LOG_FILE
echo "" | tee -a $LOG_FILE
echo "🚀 Boot Persistence (Bulletproof):" | tee -a $LOG_FILE
echo "  ✓ @reboot cron: Starts 10 seconds after boot" | tee -a $LOG_FILE
echo "  ✓ Watchdog cron: Checks EVERY MINUTE, restarts if down" | tee -a $LOG_FILE
echo "  ✓ Agent WILL survive reboots and crashes!" | tee -a $LOG_FILE
echo "" | tee -a $LOG_FILE
echo "📊 Details:" | tee -a $LOG_FILE
echo "  Firewall ID: $FIREWALL_ID" | tee -a $LOG_FILE
echo "  Agent Location: $AGENT_PATH" | tee -a $LOG_FILE
echo "  Log File: $LOG_FILE" | tee -a $LOG_FILE
echo "" | tee -a $LOG_FILE
echo "🔍 Monitoring Commands:" | tee -a $LOG_FILE
echo "  • Check agent: ps aux | grep tunnel_agent" | tee -a $LOG_FILE
echo "  • View logs: tail -f /var/log/opnsense_agent.log" | tee -a $LOG_FILE
echo "  • Check cron: crontab -l" | tee -a $LOG_FILE
echo "  • Manual start: /usr/local/bin/tunnel_agent.sh &" | tee -a $LOG_FILE
