#!/bin/sh

# OPNsense Management Agent v2.3
# Enhanced with automated backup functionality

# Configuration
MANAGEMENT_SERVER="https://opn.agit8or.net"
AGENT_VERSION="2.4.0"
FIREWALL_ID="21"  # This firewall's ID in the management system
LOG_FILE="/var/log/opnsense_agent.log"
BACKUP_TRACKER="/tmp/opnsense_last_backup"

# Hardware ID generation (persistent)
HARDWARE_ID_FILE="/tmp/opnsense_hardware_id"
if [ ! -f "$HARDWARE_ID_FILE" ]; then
    openssl rand -hex 16 > "$HARDWARE_ID_FILE"
fi
HARDWARE_ID=$(cat "$HARDWARE_ID_FILE")

# Get system information
HOSTNAME=$(hostname)
WAN_IP=$(curl -s -m 5 https://ipinfo.io/ip 2>/dev/null || echo "unknown")

# Get LAN IP (look for private network ranges, exclude WAN IP)
LAN_IP=$(ifconfig | grep 'inet ' | grep -v '127.0.0.1' | awk '{print $2}' | grep -E '^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)' | head -1)
if [ -z "$LAN_IP" ]; then
    # Fallback: get first non-localhost, non-WAN IP
    LAN_IP=$(ifconfig | grep 'inet ' | grep -v '127.0.0.1' | awk '{print $2}' | grep -v "$WAN_IP" | head -1)
fi
if [ -z "$LAN_IP" ]; then
    LAN_IP="unknown"
fi

# Get IPv6 address (prefer global unicast, avoid link-local)
IPV6_ADDRESS=$(ifconfig | grep 'inet6' | grep -v '::1' | grep -v 'fe80:' | grep -v '%' | awk '{print $2}' | head -1)
if [ -z "$IPV6_ADDRESS" ]; then
    IPV6_ADDRESS="unknown"
fi

# Get system uptime in a clean format
UPTIME=$(uptime | sed 's/.*up \([^,]*\).*/\1/' | sed 's/^ *//' | cut -c1-90)

# Logging function

log_message() {

