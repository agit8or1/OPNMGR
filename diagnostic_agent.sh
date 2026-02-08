#!/bin/sh

# Diagnostic script to test data collection methods
echo "=== OPNsense Data Collection Diagnostic ==="
echo "Current time: $(date)"
echo

# Test WAN IP detection
echo "--- WAN IP Detection ---"
WAN_IP=$(curl -s -k --connect-timeout 5 https://api.ipify.org || echo "unknown")
echo "WAN IP: $WAN_IP"
echo

# Test LAN IP detection methods
echo "--- LAN IP Detection Methods ---"
echo "Method 1: Interface scan"
for iface in $(ifconfig -l 2>/dev/null | tr ' ' '\n' | grep -E '^(em|igb|re|bge|vtnet)[0-9]+$'); do
    echo "  Checking interface $iface:"
    if ifconfig "$iface" 2>/dev/null | grep -q "status: active\|UP"; then
        IFACE_IP=$(ifconfig "$iface" 2>/dev/null | awk '/inet [0-9]/ && !/127\.0\.0\.1/ {print $2}' | head -1)
        echo "    Status: UP, IP: $IFACE_IP"
        if [ -n "$IFACE_IP" ] && [ "$IFACE_IP" != "$WAN_IP" ]; then
            if echo "$IFACE_IP" | grep -qE '^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.|169\.254\.)'; then
                echo "    -> This is a private IP and could be LAN IP"
            fi
        fi
    else
        echo "    Status: DOWN"
    fi
done
echo

echo "Method 2: All interfaces with IPs"
ifconfig 2>/dev/null | awk '
/^[a-z]/ { iface = $1 }
/inet [0-9]/ && !/127\.0\.0\.1/ { print iface " -> " $2 }'
echo

echo "Method 3: Default route interface"
DEFAULT_IFACE=$(route -n get default 2>/dev/null | awk '/interface:/ {print $2}')
echo "Default interface: $DEFAULT_IFACE"
if [ -n "$DEFAULT_IFACE" ]; then
    DEFAULT_IP=$(ifconfig "$DEFAULT_IFACE" 2>/dev/null | awk '/inet [0-9]/ && !/127\.0\.0\.1/ {print $2}' | head -1)
    echo "Default interface IP: $DEFAULT_IP"
fi
echo

# Test IPv6 detection
echo "--- IPv6 Detection Methods ---"
echo "Method 1: Global scope IPv6"
ifconfig 2>/dev/null | awk '/inet6 / && !/fe80:/ && !/::1/ && /scope global/ {print $2}' | head -1
echo

echo "Method 2: Any public IPv6"
ifconfig 2>/dev/null | awk '/inet6 [0-9a-f:]/ && !/fe80:/ && !/::1/ {print $2}' | grep -E '^[0-9a-f]*:' | head -1
echo

echo "Method 3: All IPv6 addresses"
ifconfig 2>/dev/null | sed -n '/inet6/p' | awk '{print $2}' | grep -vE '^(fe80:|::1)'
echo

# Test OPNsense version detection
echo "--- OPNsense Version Detection ---"
echo "Method 1: opnsense-version command"
opnsense-version 2>/dev/null | head -1
echo

echo "Method 2: Core version file"
if [ -f /usr/local/opnsense/version/core ]; then
    echo "Core version: $(cat /usr/local/opnsense/version/core 2>/dev/null)"
fi
echo

echo "Method 3: Available version files"
for version_file in /usr/local/opnsense/version/*; do
    if [ -f "$version_file" ]; then
        echo "$(basename $version_file): $(cat $version_file 2>/dev/null)"
    fi
done
echo

echo "Method 4: FreeBSD version"
uname -r
echo

echo "=== End Diagnostic ==="