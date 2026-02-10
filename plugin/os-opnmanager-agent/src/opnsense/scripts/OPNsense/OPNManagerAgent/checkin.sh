#!/bin/sh

# Copyright (C) 2024 OPNManager
# All rights reserved.
#
# Redistribution and use in source and binary forms, with or without
# modification, are permitted provided that the following conditions are met:
#
# 1. Redistributions of source code must retain the above copyright notice,
#    this list of conditions and the following disclaimer.
#
# 2. Redistributions in binary form must reproduce the above copyright
#    notice, this list of conditions and the following disclaimer in the
#    documentation and/or other materials provided with the distribution.
#
# THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
# INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
# AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
# AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
# OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
# SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
# INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
# CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
# ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
# POSSIBILITY OF SUCH DAMAGE.

# Force immediate check-in (one-shot command for testing)

CONFIG_FILE="/conf/config.xml"
AGENT_VERSION="1.1.7"

# Read configuration value from config.xml
get_config() {
    local xpath="$1"
    if command -v xml >/dev/null 2>&1; then
        xml sel -t -v "$xpath" "$CONFIG_FILE" 2>/dev/null
    elif command -v xmllint >/dev/null 2>&1; then
        xmllint --xpath "string($xpath)" "$CONFIG_FILE" 2>/dev/null
    fi
}

# Load configuration
SERVER_URL=$(get_config "//OPNsense/OPNManagerAgent/general/serverUrl")
FIREWALL_ID=$(get_config "//OPNsense/OPNManagerAgent/general/firewallId")
VERIFY_SSL=$(get_config "//OPNsense/OPNManagerAgent/general/verifySSL")

# Validate configuration
if [ -z "$SERVER_URL" ] || [ -z "$FIREWALL_ID" ]; then
    echo "ERROR: OPNManager Agent not configured"
    echo "Please configure the agent in Services > OPNManager Agent"
    exit 1
fi

CHECKIN_URL="${SERVER_URL}/agent_checkin.php"

# Set up curl options
CURL_OPTS="-s -m 30"
if [ "$VERIFY_SSL" != "1" ]; then
    CURL_OPTS="$CURL_OPTS -k"
fi

# Gather system information
wan_ip=$(ifconfig | grep 'inet ' | grep -v '127.0.0.1' | head -1 | awk '{print $2}')
version=$(opnsense-version 2>/dev/null | awk '{print $2}' | head -1)
hostname=$(hostname)

# Build payload
PAYLOAD="{\"firewall_id\":$FIREWALL_ID,\"agent_version\":\"$AGENT_VERSION\",\"wan_ip\":\"$wan_ip\",\"opnsense_version\":\"$version\",\"hostname\":\"$hostname\"}"

echo "Checking in to $SERVER_URL..."
echo "Firewall ID: $FIREWALL_ID"
echo ""

# Perform check-in
RESPONSE=$(/usr/local/bin/curl $CURL_OPTS -X POST \
    -H "Content-Type: application/json" \
    -d "$PAYLOAD" \
    "$CHECKIN_URL" 2>&1)

if [ $? -eq 0 ]; then
    if echo "$RESPONSE" | grep -q '"success"'; then
        echo "Check-in successful!"
        echo ""
        echo "Response:"
        echo "$RESPONSE" | /usr/local/bin/python3 -m json.tool 2>/dev/null || echo "$RESPONSE"
    else
        echo "Check-in failed: Server returned error"
        echo "$RESPONSE"
        exit 1
    fi
else
    echo "Check-in failed: Could not connect to server"
    exit 1
fi
