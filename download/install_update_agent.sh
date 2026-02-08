#!/bin/sh

###############################################################################
# OPNsense Update Agent Installer
# Run this on your OPNsense firewall AFTER enrolling the primary agent
###############################################################################

echo "=== OPNsense Update Agent Installer ==="

# Check if running on OPNsense/FreeBSD
if [ ! -f "/usr/local/etc/rc.d/opnsense_agent" ]; then
    echo "ERROR: Primary agent not found. Please enroll your firewall first."
    exit 1
fi

# Get firewall ID from primary agent
FIREWALL_ID=$(grep 'FIREWALL_ID=' /usr/local/bin/opnsense_agent.sh | head -1 | cut -d'"' -f2)

if [ -z "$FIREWALL_ID" ]; then
    echo "ERROR: Could not determine firewall ID from primary agent"
    exit 1
fi

echo "Firewall ID: $FIREWALL_ID"
echo ""

# Download update agent
echo "Downloading update agent..."
/usr/local/bin/curl -k -s -o /tmp/opnsense_update_agent.sh "https://opn.agit8or.net/download_update_agent.php?firewall_id=${FIREWALL_ID}"

if [ $? -ne 0 ] || [ ! -s /tmp/opnsense_update_agent.sh ]; then
    echo "ERROR: Failed to download update agent"
    exit 1
fi

# Verify it's a valid shell script
if ! head -1 /tmp/opnsense_update_agent.sh | grep -q "^#!/"; then
    echo "ERROR: Downloaded file is not a valid shell script"
    rm -f /tmp/opnsense_update_agent.sh
    exit 1
fi

echo "Installing update agent..."

# Install to /usr/local/bin/
mv /tmp/opnsense_update_agent.sh /usr/local/bin/opnsense_update_agent.sh
chmod +x /usr/local/bin/opnsense_update_agent.sh

# Create rc.d service script
cat > /usr/local/etc/rc.d/opnsense_update_agent << 'EOFRC'
#!/bin/sh
# PROVIDE: opnsense_update_agent
# REQUIRE: NETWORKING
# KEYWORD: shutdown

. /etc/rc.subr

name="opnsense_update_agent"
rcvar="opnsense_update_agent_enable"
pidfile="/var/run/opnsense_update_agent.pid"
command="/usr/local/bin/opnsense_update_agent.sh"
command_interpreter="/bin/sh"

load_rc_config $name
: ${opnsense_update_agent_enable:="NO"}

run_rc_command "$1"
EOFRC

chmod +x /usr/local/etc/rc.d/opnsense_update_agent

echo "Enabling update agent service..."
sysrc opnsense_update_agent_enable="YES"

echo "Starting update agent..."
service opnsense_update_agent start

if [ $? -eq 0 ]; then
    echo ""
    echo "✅ SUCCESS: Update agent installed and started!"
    echo ""
    echo "The update agent will:"
    echo "  • Check in every 30 minutes"
    echo "  • Monitor primary agent health"
    echo "  • Auto-restart primary agent if it crashes"
    echo "  • Handle primary agent updates"
    echo ""
    echo "You can check the log: tail -f /var/log/opnsense_update_agent.log"
else
    echo ""
    echo "ERROR: Failed to start update agent"
    exit 1
fi
