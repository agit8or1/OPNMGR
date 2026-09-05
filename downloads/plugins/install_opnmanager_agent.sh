#!/bin/sh

# OPNManager Agent Plugin Installer
# Downloads and installs the OPNManager agent plugin for OPNsense

PLUGIN_URL="https://opn.agit8or.net/downloads/plugins/os-opnmanager-agent-1.6.2.tar.gz"
PLUGIN_VERSION="1.6.2"
INSTALL_DIR="/usr/local"

echo "=========================================="
echo "OPNManager Agent Plugin Installer v${PLUGIN_VERSION}"
echo "=========================================="
echo ""

# Check if running as root
if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: This script must be run as root"
    exit 1
fi

# Check if OPNsense
if [ ! -f /usr/local/etc/inc/config.inc ]; then
    echo "ERROR: This script must be run on OPNsense"
    exit 1
fi

echo "Downloading plugin..."
cd /tmp
fetch -q "$PLUGIN_URL" -o opnmanager-agent.tar.gz

if [ $? -ne 0 ]; then
    echo "ERROR: Failed to download plugin"
    exit 1
fi

echo "Extracting plugin..."
tar -xzf opnmanager-agent.tar.gz

if [ $? -ne 0 ]; then
    echo "ERROR: Failed to extract plugin"
    exit 1
fi

echo "Installing plugin files..."

# Create directories if they don't exist
mkdir -p ${INSTALL_DIR}/opnsense/mvc/app/controllers/OPNsense/OPNManagerAgent/Api
mkdir -p ${INSTALL_DIR}/opnsense/mvc/app/controllers/OPNsense/OPNManagerAgent/forms
mkdir -p ${INSTALL_DIR}/opnsense/mvc/app/models/OPNsense/OPNManagerAgent/Menu
mkdir -p ${INSTALL_DIR}/opnsense/mvc/app/models/OPNsense/OPNManagerAgent/ACL
mkdir -p ${INSTALL_DIR}/opnsense/mvc/app/views/OPNsense/OPNManagerAgent
mkdir -p ${INSTALL_DIR}/opnsense/scripts/OPNsense/OPNManagerAgent
mkdir -p ${INSTALL_DIR}/opnsense/service/conf/actions.d
mkdir -p /usr/local/etc/inc/plugins.inc.d
mkdir -p /usr/local/etc/rc.d

# Install files - Controllers
cp -f opnsense/mvc/app/controllers/OPNsense/OPNManagerAgent/*.php ${INSTALL_DIR}/opnsense/mvc/app/controllers/OPNsense/OPNManagerAgent/ 2>/dev/null
cp -f opnsense/mvc/app/controllers/OPNsense/OPNManagerAgent/Api/*.php ${INSTALL_DIR}/opnsense/mvc/app/controllers/OPNsense/OPNManagerAgent/Api/ 2>/dev/null
cp -f opnsense/mvc/app/controllers/OPNsense/OPNManagerAgent/forms/*.xml ${INSTALL_DIR}/opnsense/mvc/app/controllers/OPNsense/OPNManagerAgent/forms/ 2>/dev/null

# Install files - Models (including Menu and ACL - critical for UI)
cp -f opnsense/mvc/app/models/OPNsense/OPNManagerAgent/*.php ${INSTALL_DIR}/opnsense/mvc/app/models/OPNsense/OPNManagerAgent/ 2>/dev/null
cp -f opnsense/mvc/app/models/OPNsense/OPNManagerAgent/*.xml ${INSTALL_DIR}/opnsense/mvc/app/models/OPNsense/OPNManagerAgent/ 2>/dev/null
cp -f opnsense/mvc/app/models/OPNsense/OPNManagerAgent/Menu/*.xml ${INSTALL_DIR}/opnsense/mvc/app/models/OPNsense/OPNManagerAgent/Menu/
cp -f opnsense/mvc/app/models/OPNsense/OPNManagerAgent/ACL/*.xml ${INSTALL_DIR}/opnsense/mvc/app/models/OPNsense/OPNManagerAgent/ACL/

# Install files - Views
cp -f opnsense/mvc/app/views/OPNsense/OPNManagerAgent/*.volt ${INSTALL_DIR}/opnsense/mvc/app/views/OPNsense/OPNManagerAgent/ 2>/dev/null

# Install files - Scripts
cp -f opnsense/scripts/OPNsense/OPNManagerAgent/*.sh ${INSTALL_DIR}/opnsense/scripts/OPNsense/OPNManagerAgent/
# Health collector (agent 1.6.0+). Without it the agent sends no health section
# and the firewall shows as "not reporting health" in the fleet view.
cp -f opnsense/scripts/OPNsense/OPNManagerAgent/*.py ${INSTALL_DIR}/opnsense/scripts/OPNsense/OPNManagerAgent/ 2>/dev/null

# Install files - Service config
cp -f opnsense/service/conf/actions.d/actions_opnmanager_agent.conf ${INSTALL_DIR}/opnsense/service/conf/actions.d/

# Install files - Plugin include and rc.d
cp -f etc/inc/plugins.inc.d/opnmanageragent.inc /usr/local/etc/inc/plugins.inc.d/
cp -f etc/rc.d/opnmanager_agent /usr/local/etc/rc.d/

# Set permissions - CRITICAL: web server needs to read these files
echo "Setting permissions..."
chmod 755 ${INSTALL_DIR}/opnsense/mvc/app/controllers/OPNsense/OPNManagerAgent
chmod 755 ${INSTALL_DIR}/opnsense/mvc/app/controllers/OPNsense/OPNManagerAgent/Api
chmod 755 ${INSTALL_DIR}/opnsense/mvc/app/controllers/OPNsense/OPNManagerAgent/forms
chmod 755 ${INSTALL_DIR}/opnsense/mvc/app/models/OPNsense/OPNManagerAgent
chmod 755 ${INSTALL_DIR}/opnsense/mvc/app/models/OPNsense/OPNManagerAgent/Menu
chmod 755 ${INSTALL_DIR}/opnsense/mvc/app/models/OPNsense/OPNManagerAgent/ACL
chmod 755 ${INSTALL_DIR}/opnsense/mvc/app/views/OPNsense/OPNManagerAgent
chmod 755 ${INSTALL_DIR}/opnsense/scripts/OPNsense/OPNManagerAgent

chmod 644 ${INSTALL_DIR}/opnsense/mvc/app/controllers/OPNsense/OPNManagerAgent/*.php
chmod 644 ${INSTALL_DIR}/opnsense/mvc/app/controllers/OPNsense/OPNManagerAgent/Api/*.php
chmod 644 ${INSTALL_DIR}/opnsense/mvc/app/controllers/OPNsense/OPNManagerAgent/forms/*.xml
chmod 644 ${INSTALL_DIR}/opnsense/mvc/app/models/OPNsense/OPNManagerAgent/*.php
chmod 644 ${INSTALL_DIR}/opnsense/mvc/app/models/OPNsense/OPNManagerAgent/*.xml
chmod 644 ${INSTALL_DIR}/opnsense/mvc/app/models/OPNsense/OPNManagerAgent/Menu/*.xml
chmod 644 ${INSTALL_DIR}/opnsense/mvc/app/models/OPNsense/OPNManagerAgent/ACL/*.xml
chmod 644 ${INSTALL_DIR}/opnsense/mvc/app/views/OPNsense/OPNManagerAgent/*.volt
chmod 755 ${INSTALL_DIR}/opnsense/scripts/OPNsense/OPNManagerAgent/*.sh
chmod 755 ${INSTALL_DIR}/opnsense/scripts/OPNsense/OPNManagerAgent/*.py 2>/dev/null
chmod 644 ${INSTALL_DIR}/opnsense/service/conf/actions.d/actions_opnmanager_agent.conf
chmod 644 /usr/local/etc/inc/plugins.inc.d/opnmanageragent.inc
chmod 755 /usr/local/etc/rc.d/opnmanager_agent

# Verify critical files
echo "Verifying installation..."
MISSING=""
[ ! -f "${INSTALL_DIR}/opnsense/mvc/app/models/OPNsense/OPNManagerAgent/Menu/Menu.xml" ] && MISSING="${MISSING} Menu.xml"
[ ! -f "${INSTALL_DIR}/opnsense/mvc/app/controllers/OPNsense/OPNManagerAgent/IndexController.php" ] && MISSING="${MISSING} IndexController.php"
[ ! -f "/usr/local/etc/inc/plugins.inc.d/opnmanageragent.inc" ] && MISSING="${MISSING} opnmanageragent.inc"
[ ! -f "${INSTALL_DIR}/opnsense/scripts/OPNsense/OPNManagerAgent/health_collect.py" ] && MISSING="${MISSING} health_collect.py"

if [ -n "$MISSING" ]; then
    echo "WARNING: Missing files:${MISSING}"
fi

# Enable service in rc.conf
sysrc opnmanager_agent_enable="YES"

echo "Reloading services and flushing caches..."

# Clear OPNsense menu cache - THIS IS THE KEY
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
rm -rf /var/lib/php/cache/*

# Restart configd to pick up new actions
service configd restart

# Restart agent service if it was already running (to pick up new version)
if service opnmanager_agent status >/dev/null 2>&1; then
    echo "Restarting agent service to load new version..."
    service opnmanager_agent restart >/dev/null 2>&1 &
    sleep 2
fi

# Restart web GUI to pick up new menu
echo "Restarting web GUI..."
/usr/local/etc/rc.restart_webgui >/dev/null 2>&1 || true

echo ""
echo "=========================================="
echo "Installation complete!"
echo "=========================================="
echo ""
echo "IMPORTANT: Clear browser cache (Ctrl+Shift+R) or log out/in"
echo ""
echo "Then go to: Services > OPNManager Agent"
echo ""
echo "Quick Setup (Recommended):"
echo "  1. Get enrollment key from OPNManager dashboard"
echo "  2. Paste into 'Quick Enrollment' field and click Enroll"
echo "  3. Done! Agent will auto-configure and start."
echo ""
echo "Manual Setup (Alternative):"
echo "  1. Enter Server URL and configure settings"
echo "  2. Click Save, then copy the Hardware ID"
echo "  3. Add firewall in OPNManager using Hardware ID"
echo ""

# Cleanup
rm -rf /tmp/opnmanager-agent.tar.gz /tmp/opnsense /tmp/etc

exit 0
