#!/bin/bash

# Deployment script for OPNsense Agent v3.4.0

AGENT_FILE="scripts/opnsense_agent_v3.4.0.sh"
DOWNLOAD_DIR="download"
DOWNLOADS_DIR="downloads"

echo "========================================="
echo "OPNsense Agent v3.4.0 Deployment"
echo "========================================="
echo ""

# Check if agent file exists
if [ ! -f "$AGENT_FILE" ]; then
    echo "ERROR: Agent file not found: $AGENT_FILE"
    exit 1
fi

echo "[1/4] Copying agent to download directory..."
cp "$AGENT_FILE" "$DOWNLOAD_DIR/opnsense_agent_v3.4.0.sh"
if [ $? -eq 0 ]; then
    echo "  ✓ Copied to $DOWNLOAD_DIR/"
else
    echo "  ✗ Failed to copy to $DOWNLOAD_DIR/"
fi

echo ""
echo "[2/4] Copying agent to downloads directory..."
cp "$AGENT_FILE" "$DOWNLOADS_DIR/opnsense_agent_v3.4.0.sh"
if [ $? -eq 0 ]; then
    echo "  ✓ Copied to $DOWNLOADS_DIR/"
else
    echo "  ✗ Failed to copy to $DOWNLOADS_DIR/"
fi

echo ""
echo "[3/4] Setting executable permissions..."
chmod +x "$DOWNLOAD_DIR/opnsense_agent_v3.4.0.sh" 2>/dev/null
chmod +x "$DOWNLOADS_DIR/opnsense_agent_v3.4.0.sh" 2>/dev/null
echo "  ✓ Permissions set"

echo ""
echo "[4/4] Verifying deployment..."
if [ -f "$DOWNLOAD_DIR/opnsense_agent_v3.4.0.sh" ]; then
    SIZE=$(wc -c < "$DOWNLOAD_DIR/opnsense_agent_v3.4.0.sh")
    echo "  ✓ Agent deployed: $DOWNLOAD_DIR/opnsense_agent_v3.4.0.sh ($SIZE bytes)"
else
    echo "  ✗ Deployment verification failed"
fi

if [ -f "$DOWNLOADS_DIR/opnsense_agent_v3.4.0.sh" ]; then
    SIZE=$(wc -c < "$DOWNLOADS_DIR/opnsense_agent_v3.4.0.sh")
    echo "  ✓ Agent deployed: $DOWNLOADS_DIR/opnsense_agent_v3.4.0.sh ($SIZE bytes)"
else
    echo "  ✗ Deployment verification failed"
fi

echo ""
echo "========================================="
echo "Deployment Complete!"
echo "========================================="
echo ""
echo "Download URLs:"
echo "  https://opn.agit8or.net/download/opnsense_agent_v3.4.0.sh"
echo "  https://opn.agit8or.net/downloads/opnsense_agent_v3.4.0.sh"
echo ""
echo "Installation command for firewalls:"
echo "  fetch -o /tmp/agent_v3.4.0.sh https://opn.agit8or.net/download/opnsense_agent_v3.4.0.sh && chmod +x /tmp/agent_v3.4.0.sh"
echo ""
echo "IMPORTANT: Don't forget to:"
echo "  1. Run the database migration (database/migrate_v3.4.0.sql)"
echo "  2. Update agent_checkin.php to handle new fields"
echo "  3. Update firewall_details.php to display WAN interface stats"
echo ""
