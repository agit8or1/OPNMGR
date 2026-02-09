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

# OPNManager Agent - Centralized firewall management agent for OPNsense
AGENT_VERSION="1.5.4"
CONFIG_FILE="/conf/config.xml"
LOG_FILE="/var/log/opnmanager_agent.log"
PID_FILE="/var/run/opnmanager_agent.pid"
HARDWARE_ID_FILE="/usr/local/etc/opnmanager_hardware_id"
MAX_LOG_SIZE=10485760  # 10MB

# Set PATH for daemon environment
PATH=/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin
export PATH

# Log rotation
rotate_log() {
    if [ -f "$LOG_FILE" ]; then
        local size=$(stat -f%z "$LOG_FILE" 2>/dev/null || echo 0)
        if [ "$size" -gt "$MAX_LOG_SIZE" ]; then
            mv "$LOG_FILE" "$LOG_FILE.old" 2>/dev/null
            touch "$LOG_FILE"
            chmod 600 "$LOG_FILE"
        fi
    fi
}

# Logging with timestamp
log_message() {
    rotate_log
    echo "$(date '+%Y-%m-%d %H:%M:%S') [$AGENT_VERSION] $1" >> "$LOG_FILE"
}

# Generate hardware ID based on system identifiers
generate_hardware_id() {
    local hw_id=""

    # Try to get DMI system UUID (most reliable)
    if [ -f /etc/hostid ]; then
        hw_id=$(cat /etc/hostid 2>/dev/null)
    fi

    # Fallback: use smbios UUID
    if [ -z "$hw_id" ] || [ "$hw_id" = "00000000" ]; then
        hw_id=$(kenv -q smbios.system.uuid 2>/dev/null)
    fi

    # Fallback: use MAC address of first interface
    if [ -z "$hw_id" ] || [ "$hw_id" = "Not Settable" ] || [ "$hw_id" = "Not Present" ]; then
        hw_id=$(ifconfig | grep -o 'ether [0-9a-f:]*' | head -1 | awk '{print $2}' | tr -d ':')
    fi

    # Last resort: generate random ID
    if [ -z "$hw_id" ]; then
        hw_id=$(head -c 16 /dev/urandom | md5)
    fi

    # Create a consistent hash
    echo "$hw_id" | md5 | cut -c1-32
}

# Get or create hardware ID
get_hardware_id() {
    # Check if hardware ID exists in file
    if [ -f "$HARDWARE_ID_FILE" ]; then
        cat "$HARDWARE_ID_FILE"
        return
    fi

    # Check config.xml
    local config_hw_id=$(get_config "//OPNsense/opnmanageragent/general/hardwareId")
    if [ -n "$config_hw_id" ]; then
        echo "$config_hw_id" > "$HARDWARE_ID_FILE"
        echo "$config_hw_id"
        return
    fi

    # Generate new hardware ID
    local new_id=$(generate_hardware_id)
    echo "$new_id" > "$HARDWARE_ID_FILE"
    chmod 600 "$HARDWARE_ID_FILE"

    log_message "Generated new hardware ID: $new_id"
    echo "$new_id"
}

# Read configuration from OPNsense config.xml using xmllint
get_config() {
    local xpath="$1"
    local value=""

    # Try xml command first (OPNsense includes this)
    if command -v xml >/dev/null 2>&1; then
        value=$(xml sel -t -v "$xpath" "$CONFIG_FILE" 2>/dev/null)
    # Fallback to xmllint
    elif command -v xmllint >/dev/null 2>&1; then
        value=$(xmllint --xpath "string($xpath)" "$CONFIG_FILE" 2>/dev/null)
    # Last resort: grep-based extraction
    else
        local tag=$(echo "$xpath" | sed 's|.*/||')
        value=$(grep -o "<$tag>[^<]*</$tag>" "$CONFIG_FILE" 2>/dev/null | sed "s/<$tag>//;s/<\/$tag>//" | head -1)
    fi

    echo "$value"
}

# Load configuration
load_config() {
    ENABLED=$(get_config "//OPNsense/opnmanageragent/general/enabled")
    SERVER_URL=$(get_config "//OPNsense/opnmanageragent/general/serverUrl")
    CHECKIN_INTERVAL=$(get_config "//OPNsense/opnmanageragent/general/checkinInterval")
    SSH_KEY_MGMT=$(get_config "//OPNsense/opnmanageragent/general/sshKeyManagement")
    VERIFY_SSL=$(get_config "//OPNsense/opnmanageragent/general/verifySSL")

    # Get hardware ID (auto-generated if not set)
    HARDWARE_ID=$(get_hardware_id)

    # Apply defaults
    CHECKIN_INTERVAL=${CHECKIN_INTERVAL:-120}
    SSH_KEY_MGMT=${SSH_KEY_MGMT:-1}
    VERIFY_SSL=${VERIFY_SSL:-1}
}

# Get OPNsense version
get_opnsense_version() {
    if command -v opnsense-version >/dev/null 2>&1; then
        opnsense-version 2>/dev/null | awk '{print $2}' | head -1
    else
        echo "unknown"
    fi
}

# Get system statistics (CPU, memory, disk) for dashboard graphs
get_system_stats() {
    # CPU load averages
    local load_avg=$(sysctl -n vm.loadavg 2>/dev/null)
    local cpu_1min=$(echo "$load_avg" | awk '{print $2}')
    local cpu_5min=$(echo "$load_avg" | awk '{print $3}')
    local cpu_15min=$(echo "$load_avg" | awk '{print $4}')

    # Memory stats - use sysctl for accurate FreeBSD memory info
    local mem_total=$(sysctl -n hw.physmem 2>/dev/null)
    local mem_total_mb=$((mem_total / 1024 / 1024))

    # Get memory page counts from sysctl
    # Try: Active + Inactive + Laundry (user-space memory, excluding kernel wired and buffers/cache)
    local mem_active=$(sysctl -n vm.stats.vm.v_active_count 2>/dev/null)
    local mem_inactive=$(sysctl -n vm.stats.vm.v_inactive_count 2>/dev/null)
    local mem_laundry=$(sysctl -n vm.stats.vm.v_laundry_count 2>/dev/null)
    local page_size=$(sysctl -n hw.pagesize 2>/dev/null)
    [ -z "$page_size" ] && page_size=4096
    [ -z "$mem_active" ] && mem_active=0
    [ -z "$mem_inactive" ] && mem_inactive=0
    [ -z "$mem_laundry" ] && mem_laundry=0

    # Calculate used memory: active + inactive + laundry
    local mem_used_pages=$((mem_active + mem_inactive + mem_laundry))
    local mem_used_mb=$(( (mem_used_pages * page_size) / 1024 / 1024 ))
    [ -z "$mem_used_mb" ] && mem_used_mb=0
    [ $mem_used_mb -lt 0 ] && mem_used_mb=0

    local mem_percent=0
    [ "$mem_total_mb" -gt 0 ] && mem_percent=$((mem_used_mb * 100 / mem_total_mb))

    # Disk stats (root filesystem)
    local disk_info=$(df -k / 2>/dev/null | tail -1)
    local disk_total_gb=$(echo "$disk_info" | awk '{print int($2/1024/1024)}')
    local disk_used_gb=$(echo "$disk_info" | awk '{print int($3/1024/1024)}')
    local disk_percent=$(echo "$disk_info" | awk '{gsub(/%/,""); print int($5)}')

    cat <<EOF
{"cpu_load_1min":$cpu_1min,"cpu_load_5min":$cpu_5min,"cpu_load_15min":$cpu_15min,"memory_total_mb":$mem_total_mb,"memory_used_mb":$mem_used_mb,"memory_percent":$mem_percent,"disk_total_gb":$disk_total_gb,"disk_used_gb":$disk_used_gb,"disk_percent":$disk_percent}
EOF
}

# Get traffic statistics for WAN interface
get_traffic_stats() {
    # Find WAN interface (usually first non-loopback with gateway)
    local wan_iface=$(netstat -rn 2>/dev/null | grep default | head -1 | awk '{print $NF}')
    [ -z "$wan_iface" ] && wan_iface="em0"

    # Use netstat -ibn for both bytes and packets (most reliable on FreeBSD)
    # Format: Name Mtu Network Address Ipkts Ierrs Idrop Ibytes Opkts Oerrs Obytes Coll
    local stats=$(netstat -ibn 2>/dev/null | grep "^${wan_iface}" | grep '<Link' | head -1)

    if [ -n "$stats" ]; then
        # Parse from Link layer line for accurate byte/packet counters
        local packets_in=$(echo "$stats" | awk '{print $5}')
        local bytes_in=$(echo "$stats" | awk '{print $8}')
        local packets_out=$(echo "$stats" | awk '{print $9}')
        local bytes_out=$(echo "$stats" | awk '{print $11}')
    else
        # Fallback without Link filter
        stats=$(netstat -ibn 2>/dev/null | grep "^${wan_iface}" | head -1)
        if [ -n "$stats" ]; then
            local packets_in=$(echo "$stats" | awk '{print $5}')
            local bytes_in=$(echo "$stats" | awk '{print $8}')
            local packets_out=$(echo "$stats" | awk '{print $9}')
            local bytes_out=$(echo "$stats" | awk '{print $11}')
        fi
    fi

    # Convert dashes and empty values to zero
    if [ -z "$bytes_in" ] || [ "$bytes_in" = "-" ]; then
        bytes_in=0
    fi
    if [ -z "$bytes_out" ] || [ "$bytes_out" = "-" ]; then
        bytes_out=0
    fi
    if [ -z "$packets_in" ] || [ "$packets_in" = "-" ]; then
        packets_in=0
    fi
    if [ -z "$packets_out" ] || [ "$packets_out" = "-" ]; then
        packets_out=0
    fi

    cat <<EOF
{"interface":"$wan_iface","bytes_in":$bytes_in,"bytes_out":$bytes_out,"packets_in":$packets_in,"packets_out":$packets_out}
EOF
}

# Get latency statistics by pinging multiple targets
get_latency_stats() {
    local target1="8.8.8.8"      # Google DNS
    local target2="1.1.1.1"      # Cloudflare DNS
    local target3="${SERVER_URL}" # OPNManager server

    # Extract hostname from SERVER_URL
    local server_host=$(echo "$SERVER_URL" | sed 's|https\?://||' | sed 's|/.*||' | sed 's|:.*||')
    [ -n "$server_host" ] && target3="$server_host"

    # Ping each target (3 packets, 2 second timeout)
    local ping1=$(ping -c 3 -W 2000 "$target1" 2>/dev/null | grep 'round-trip' | awk -F'/' '{print $5}')
    local ping2=$(ping -c 3 -W 2000 "$target2" 2>/dev/null | grep 'round-trip' | awk -F'/' '{print $5}')
    local ping3=$(ping -c 3 -W 2000 "$target3" 2>/dev/null | grep 'round-trip' | awk -F'/' '{print $5}')

    # Default to 0 if ping failed
    [ -z "$ping1" ] && ping1="0"
    [ -z "$ping2" ] && ping2="0"
    [ -z "$ping3" ] && ping3="0"

    # Calculate average (if we have valid pings)
    local avg_latency=0
    local count=0
    for lat in $ping1 $ping2 $ping3; do
        if [ "$lat" != "0" ]; then
            avg_latency=$(echo "$avg_latency + $lat" | bc 2>/dev/null || echo 0)
            count=$((count + 1))
        fi
    done

    if [ $count -gt 0 ] && command -v bc >/dev/null 2>&1; then
        avg_latency=$(echo "scale=2; $avg_latency / $count" | bc)
    else
        avg_latency=0
    fi

    cat <<EOF
{"target1":"$target1","latency1":$ping1,"target2":"$target2","latency2":$ping2,"target3":"$target3","latency3":$ping3,"average_latency":$avg_latency}
EOF
}

# Run speedtest (only when requested by server)
run_speedtest() {
    log_message "Running speedtest..."

    # Check if speedtest-cli is installed
    if ! command -v speedtest-cli >/dev/null 2>&1; then
        log_message "speedtest-cli not installed, attempting to install..."
        if command -v pkg >/dev/null 2>&1; then
            # Try multiple package versions
            pkg install -y py311-speedtest-cli >/dev/null 2>&1 || \
            pkg install -y py310-speedtest-cli >/dev/null 2>&1 || \
            pkg install -y py39-speedtest-cli >/dev/null 2>&1 || \
            pkg install -y py38-speedtest-cli >/dev/null 2>&1
        fi
    fi

    if command -v speedtest-cli >/dev/null 2>&1; then
        # Run speedtest with JSON output
        local result=$(speedtest-cli --json 2>/dev/null)

        if [ $? -eq 0 ] && [ -n "$result" ]; then
            # Extract values from JSON
            local download=$(echo "$result" | /usr/local/bin/python3 -c "import json,sys; data=json.load(sys.stdin); print(int(data.get('download',0)/1000000))" 2>/dev/null)
            local upload=$(echo "$result" | /usr/local/bin/python3 -c "import json,sys; data=json.load(sys.stdin); print(int(data.get('upload',0)/1000000))" 2>/dev/null)
            local ping=$(echo "$result" | /usr/local/bin/python3 -c "import json,sys; data=json.load(sys.stdin); print(data.get('ping',0))" 2>/dev/null)
            local server=$(echo "$result" | /usr/local/bin/python3 -c "import json,sys; data=json.load(sys.stdin); print(data.get('server',{}).get('name','Unknown'))" 2>/dev/null)

            [ -z "$download" ] && download=0
            [ -z "$upload" ] && upload=0
            [ -z "$ping" ] && ping=0
            [ -z "$server" ] && server="Unknown"

            log_message "Speedtest complete: Down=${download}Mbps, Up=${upload}Mbps, Ping=${ping}ms"

            cat <<EOF
{"download_mbps":$download,"upload_mbps":$upload,"ping_ms":$ping,"server":"$server","timestamp":"$(date '+%Y-%m-%d %H:%M:%S')"}
EOF
        else
            log_message "Speedtest failed to run"
            echo '{"error":"Speedtest execution failed"}'
        fi
    else
        log_message "speedtest-cli not available"
        echo '{"error":"speedtest-cli not installed"}'
    fi
}

# Get WAN interface name from default route
get_wan_interface() {
    netstat -rn 2>/dev/null | grep '^default' | head -1 | awk '{print $NF}'
}

# Get network configuration details for WAN and LAN
get_network_config() {
    local wan_iface=$(get_wan_interface)
    [ -z "$wan_iface" ] && wan_iface="em0"

    # WAN netmask - extract from ifconfig
    local wan_netmask=$(ifconfig "$wan_iface" 2>/dev/null | grep 'inet ' | head -1 | awk '{print $4}')
    # Convert hex netmask to dotted decimal if needed
    if echo "$wan_netmask" | grep -q '^0x'; then
        wan_netmask=$(printf "%d.%d.%d.%d" \
            $(( $(printf '%d' "$wan_netmask") >> 24 & 255 )) \
            $(( $(printf '%d' "$wan_netmask") >> 16 & 255 )) \
            $(( $(printf '%d' "$wan_netmask") >> 8 & 255 )) \
            $(( $(printf '%d' "$wan_netmask") & 255 )))
    fi

    # WAN gateway from default route
    local wan_gateway=$(netstat -rn 2>/dev/null | grep '^default' | head -1 | awk '{print $2}')

    # DNS servers from resolv.conf
    local wan_dns_primary=$(awk '/^nameserver/{print $2; exit}' /etc/resolv.conf 2>/dev/null)
    local wan_dns_secondary=$(awk '/^nameserver/{n++; if(n==2) print $2}' /etc/resolv.conf 2>/dev/null)

    # LAN - find first RFC1918 interface
    local lan_ip=$(ifconfig | grep 'inet ' | awk '{print $2}' | grep -E '^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)' | head -1)
    local lan_netmask=""
    local lan_network=""
    if [ -n "$lan_ip" ]; then
        # Find which interface has this IP to get its netmask
        local lan_iface=$(ifconfig | grep -B5 "inet $lan_ip " | grep '^[a-z]' | awk -F: '{print $1}' | tail -1)
        if [ -n "$lan_iface" ]; then
            lan_netmask=$(ifconfig "$lan_iface" 2>/dev/null | grep "inet $lan_ip " | awk '{print $4}')
            if echo "$lan_netmask" | grep -q '^0x'; then
                lan_netmask=$(printf "%d.%d.%d.%d" \
                    $(( $(printf '%d' "$lan_netmask") >> 24 & 255 )) \
                    $(( $(printf '%d' "$lan_netmask") >> 16 & 255 )) \
                    $(( $(printf '%d' "$lan_netmask") >> 8 & 255 )) \
                    $(( $(printf '%d' "$lan_netmask") & 255 )))
            fi
            # Calculate network address
            if [ -n "$lan_netmask" ]; then
                local IFS_OLD="$IFS"
                IFS='.'
                set -- $lan_ip
                local ip1=$1 ip2=$2 ip3=$3 ip4=$4
                set -- $lan_netmask
                local nm1=$1 nm2=$2 nm3=$3 nm4=$4
                IFS="$IFS_OLD"
                lan_network="$((ip1 & nm1)).$((ip2 & nm2)).$((ip3 & nm3)).$((ip4 & nm4))"
            fi
        fi
    fi

    # List all WAN-facing interfaces (non-loopback, non-tunnel with IPs)
    local wan_interfaces=$(ifconfig -l 2>/dev/null | tr ' ' '\n' | grep -v '^$')
    local wan_iface_list=""
    for iface in $wan_interfaces; do
        # Skip pure loopback
        case "$iface" in pflog*|pfsync*|enc*) continue ;; esac
        if ifconfig "$iface" 2>/dev/null | grep -q 'inet \|flags.*UP'; then
            [ -n "$wan_iface_list" ] && wan_iface_list="${wan_iface_list},"
            wan_iface_list="${wan_iface_list}${iface}"
        fi
    done

    echo "${wan_netmask:-}|${wan_gateway:-}|${wan_dns_primary:-}|${wan_dns_secondary:-}|${lan_netmask:-}|${lan_network:-}|${wan_iface_list:-}"
}

# Get per-interface WAN statistics as JSON array
get_wan_interface_stats() {
    local interfaces=$(ifconfig -l 2>/dev/null | tr ' ' '\n')
    local first=1
    echo -n "["
    for iface in $interfaces; do
        case "$iface" in pflog*|pfsync*|enc*|lo*) continue ;; esac

        local iface_data=$(ifconfig "$iface" 2>/dev/null)
        [ -z "$iface_data" ] && continue

        # Check if interface is UP
        local status="down"
        echo "$iface_data" | grep -q 'status: active' && status="active"
        echo "$iface_data" | grep -q 'status: associated' && status="active"
        # If no status line but UP flag, treat as active
        if [ "$status" = "down" ]; then
            echo "$iface_data" | grep -q 'flags=.*UP' && ! echo "$iface_data" | grep -q 'status: no carrier' && status="active"
        fi

        local ip=$(echo "$iface_data" | grep 'inet ' | head -1 | awk '{print $2}')
        local mask=$(echo "$iface_data" | grep 'inet ' | head -1 | awk '{print $4}')
        local media=$(echo "$iface_data" | grep 'media:' | head -1 | sed 's/.*media: //' | awk '{print $1}')
        [ -z "$media" ] && media="none"

        # Get gateway if this is the default route interface
        local gw=""
        local def_iface=$(netstat -rn 2>/dev/null | grep '^default' | head -1 | awk '{print $NF}')
        [ "$iface" = "$def_iface" ] && gw=$(netstat -rn 2>/dev/null | grep '^default' | head -1 | awk '{print $2}')

        # Traffic counters from netstat -ibn
        local link_stats=$(netstat -ibn 2>/dev/null | grep "^${iface}" | grep '<Link' | head -1)
        local rx_packets=0 rx_errors=0 rx_bytes=0 tx_packets=0 tx_errors=0 tx_bytes=0
        if [ -n "$link_stats" ]; then
            rx_packets=$(echo "$link_stats" | awk '{print $5}')
            rx_errors=$(echo "$link_stats" | awk '{print $6}')
            rx_bytes=$(echo "$link_stats" | awk '{print $8}')
            tx_packets=$(echo "$link_stats" | awk '{print $9}')
            tx_errors=$(echo "$link_stats" | awk '{print $10}')
            tx_bytes=$(echo "$link_stats" | awk '{print $11}')
        fi

        [ $first -eq 0 ] && echo -n ","
        first=0
        echo -n "{\"interface\":\"$iface\",\"status\":\"$status\",\"ip\":\"${ip:-}\",\"netmask\":\"${mask:-}\",\"gateway\":\"${gw:-}\",\"media\":\"${media:-}\",\"rx_packets\":${rx_packets:-0},\"rx_errors\":${rx_errors:-0},\"rx_bytes\":${rx_bytes:-0},\"tx_packets\":${tx_packets:-0},\"tx_errors\":${tx_errors:-0},\"tx_bytes\":${tx_bytes:-0}}"
    done
    echo "]"
}

# Get system information
get_system_info() {
    local wan_ip=$(ifconfig | grep 'inet ' | grep -v '127.0.0.1' | head -1 | awk '{print $2}')
    local lan_ip=""
    local version=$(get_opnsense_version)
    local uptime_info=$(uptime | sed 's/.*up //' | sed 's/,.*//')
    local hostname=$(hostname)

    # Get IPv6 address (prefer global unicast over link-local)
    local ipv6_address=""
    # First try to get global unicast IPv6 (2000::/3)
    ipv6_address=$(ifconfig | grep 'inet6 ' | grep -v 'fe80:' | grep -v '::1' | head -1 | awk '{print $2}' | sed 's/%.*//')
    # If no global IPv6, fall back to link-local
    if [ -z "$ipv6_address" ]; then
        ipv6_address=$(ifconfig | grep 'inet6 fe80:' | head -1 | awk '{print $2}' | sed 's/%.*//')
    fi

    # Get LAN IP (first RFC1918 private IP: 10.x, 172.16-31.x, 192.168.x)
    lan_ip=$(ifconfig | grep 'inet ' | awk '{print $2}' | grep -E '^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)' | head -1)

    # Get network config details
    local net_config=$(get_network_config)
    local wan_netmask=$(echo "$net_config" | cut -d'|' -f1)
    local wan_gateway=$(echo "$net_config" | cut -d'|' -f2)
    local wan_dns_primary=$(echo "$net_config" | cut -d'|' -f3)
    local wan_dns_secondary=$(echo "$net_config" | cut -d'|' -f4)
    local lan_netmask=$(echo "$net_config" | cut -d'|' -f5)
    local lan_network=$(echo "$net_config" | cut -d'|' -f6)
    local wan_interfaces=$(echo "$net_config" | cut -d'|' -f7)

    cat <<EOF
{"hardware_id":"$HARDWARE_ID","agent_version":"$AGENT_VERSION","wan_ip":"$wan_ip","lan_ip":"$lan_ip","ipv6_address":"$ipv6_address","opnsense_version":"$version","uptime":"$uptime_info","hostname":"$hostname","wan_netmask":"$wan_netmask","wan_gateway":"$wan_gateway","wan_dns_primary":"$wan_dns_primary","wan_dns_secondary":"$wan_dns_secondary","lan_netmask":"$lan_netmask","lan_network":"$lan_network","wan_interfaces":"$wan_interfaces"}
EOF
}

# Check for available updates
check_updates() {
    local updates_available="false"
    local new_version=""

    # Method 1: Use opnsense-update -c (most reliable for OPNsense updates)
    if command -v opnsense-update >/dev/null 2>&1; then
        local update_output=$(opnsense-update -c 2>&1)
        local update_exit=$?
        # Exit code 0 means updates available, non-zero means up to date
        if [ $update_exit -eq 0 ] && [ -n "$update_output" ]; then
            updates_available="true"
            # Try to extract version from output
            new_version=$(echo "$update_output" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+[_0-9]*' | tail -1)
        fi
    fi

    # Method 2: Fallback to pkg upgrade check
    if [ "$updates_available" = "false" ] && command -v pkg >/dev/null 2>&1; then
        local pkg_output=$(pkg upgrade -n 2>&1)
        if echo "$pkg_output" | grep -q "opnsense.*->"; then
            updates_available="true"
            new_version=$(echo "$pkg_output" | grep "opnsense:" | sed -n 's/.*-> \([0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*[_0-9]*\).*/\1/p' | head -1)
        fi
    fi

    echo "{\"updates_available\":$updates_available,\"new_version\":\"$new_version\"}"
}

# Process commands from server response
process_response() {
    local response="$1"

    # Check for OPNsense update request
    local update_requested=$(echo "$response" | /usr/local/bin/python3 -c "
import json, sys
try:
    data = json.load(sys.stdin)
    print('true' if data.get('opnsense_update_requested', False) else 'false')
except:
    print('false')
" 2>/dev/null)

    if [ "$update_requested" = "true" ]; then
        log_message "Update requested by server"
        local update_cmd=$(echo "$response" | /usr/local/bin/python3 -c "
import json, sys
try:
    data = json.load(sys.stdin)
    print(data.get('opnsense_update_command', '/usr/local/sbin/opnsense-update -bkpf'))
except:
    print('/usr/local/sbin/opnsense-update -bkpf')
" 2>/dev/null)

        log_message "Executing update command: $update_cmd"
        nohup sh -c "$update_cmd >> $LOG_FILE 2>&1" > /dev/null 2>&1 &
    fi

    # Check for SSH key deployment
    if [ "$SSH_KEY_MGMT" = "1" ]; then
        local ssh_key=$(echo "$response" | /usr/local/bin/python3 -c "
import json, sys
try:
    data = json.load(sys.stdin)
    print(data.get('deploy_ssh_key', ''))
except:
    print('')
" 2>/dev/null)

        if [ -n "$ssh_key" ]; then
            log_message "Deploying SSH key from server"
            mkdir -p /root/.ssh
            chmod 700 /root/.ssh
            # Only append if key not already present
            if ! grep -qF "$ssh_key" /root/.ssh/authorized_keys 2>/dev/null; then
                echo "$ssh_key" >> /root/.ssh/authorized_keys
                chmod 600 /root/.ssh/authorized_keys
                log_message "SSH key deployed successfully"
            else
                log_message "SSH key already present"
            fi
        fi
    fi

    # Check for agent restart/update command
    local restart_agent=$(echo "$response" | /usr/local/bin/python3 -c "
import json, sys
try:
    data = json.load(sys.stdin)
    print('true' if data.get('restart_agent', False) else 'false')
except:
    print('false')
" 2>/dev/null)

    if [ "$restart_agent" = "true" ]; then
        log_message "Agent restart requested by server"
        /usr/sbin/service opnmanager_agent restart >/dev/null 2>&1 &
        exit 0
    fi

    # Process queued commands
    echo "$response" | /usr/local/bin/python3 -c "
import json, sys, base64
try:
    data = json.load(sys.stdin)
    if 'queued_commands' in data:
        for cmd in data['queued_commands']:
            cmd_b64 = base64.b64encode(str(cmd['command']).encode()).decode()
            print(f\"{cmd['id']}|{cmd_b64}\")
except:
    pass
" 2>/dev/null | while IFS='|' read -r cmd_id cmd_data_b64; do
        if [ -n "$cmd_id" ] && [ -n "$cmd_data_b64" ]; then
            cmd_data=$(echo "$cmd_data_b64" | base64 -d 2>/dev/null)
            if [ -n "$cmd_data" ]; then
                log_message "Executing command $cmd_id: $cmd_data"

                # Check for special commands
                if [ "$cmd_data" = "run_speedtest" ]; then
                    log_message "Running speedtest as requested"
                    result=$(run_speedtest)

                    # Send speedtest results to server
                    $CURL_CMD -s -m 10 -X POST \
                        -H "Content-Type: application/json" \
                        -d "{\"hardware_id\":\"$HARDWARE_ID\",\"agent_version\":\"$AGENT_VERSION\",\"speedtest_result\":$result}" \
                        "${SERVER_URL}/agent_checkin.php" > /dev/null 2>&1
                else
                    # Execute regular shell command
                    result=$(eval "$cmd_data" 2>&1 | head -1000)
                fi

                result_b64=$(echo "$result" | base64 2>/dev/null)

                # Report result back to server
                $CURL_CMD -s -m 10 -X POST \
                    -H "Content-Type: application/json" \
                    -d "{\"hardware_id\":\"$HARDWARE_ID\",\"command_id\":$cmd_id,\"status\":\"completed\",\"result\":\"$result_b64\"}" \
                    "${SERVER_URL}/agent_checkin.php" > /dev/null 2>&1

                log_message "Command $cmd_id completed"
            fi
        fi
    done
}

# Main entry point
main() {
    # Write PID file
    echo $$ > "$PID_FILE"

    # Load configuration
    load_config

    # Validate configuration
    if [ "$ENABLED" != "1" ]; then
        log_message "Agent is disabled in configuration"
        exit 0
    fi

    if [ -z "$SERVER_URL" ]; then
        log_message "ERROR: Server URL not configured"
        exit 1
    fi

    # Set up curl command with SSL options
    CURL_CMD="/usr/local/bin/curl"
    if [ "$VERIFY_SSL" != "1" ]; then
        CURL_CMD="$CURL_CMD -k"
        log_message "WARNING: SSL certificate verification disabled"
    fi

    CHECKIN_URL="${SERVER_URL}/agent_checkin.php"

    log_message "Starting OPNManager Agent v$AGENT_VERSION"
    log_message "Server: $SERVER_URL"
    log_message "Hardware ID: $HARDWARE_ID"
    log_message "Check-in interval: ${CHECKIN_INTERVAL}s"

    # Main loop
    ERROR_COUNT=0
    MAX_ERRORS=10

    while true; do
        rotate_log

        # Check if a newer version of the agent script exists (self-update detection)
        if [ -f "/usr/local/opnsense/scripts/OPNsense/OPNManagerAgent/agent.sh" ]; then
            INSTALLED_VERSION=$(grep '^AGENT_VERSION=' /usr/local/opnsense/scripts/OPNsense/OPNManagerAgent/agent.sh | head -1 | cut -d'"' -f2)
            if [ -n "$INSTALLED_VERSION" ] && [ "$INSTALLED_VERSION" != "$AGENT_VERSION" ]; then
                log_message "New agent version detected: $INSTALLED_VERSION (current: $AGENT_VERSION)"
                log_message "Restarting to use new version..."
                # Use service restart to ensure clean restart
                /usr/sbin/service opnmanager_agent restart >/dev/null 2>&1 &
                exit 0
            fi
        fi

        # Reload config periodically to pick up changes
        load_config

        # Calculate sleep time with exponential backoff on errors
        if [ $ERROR_COUNT -eq 0 ]; then
            SLEEP_TIME=$CHECKIN_INTERVAL
        else
            BACKOFF=$((ERROR_COUNT * 30))
            [ $BACKOFF -gt 300 ] && BACKOFF=300
            SLEEP_TIME=$((CHECKIN_INTERVAL + BACKOFF))
            log_message "Error backoff: sleeping ${SLEEP_TIME}s (errors: $ERROR_COUNT)"
        fi

        # Build check-in payload
        PAYLOAD=$(get_system_info)

        # Add system stats for dashboard graphs (CPU, memory, disk)
        SYSTEM_STATS=$(get_system_stats)
        PAYLOAD=$(echo "$PAYLOAD" | sed 's/}$//')
        PAYLOAD="${PAYLOAD},\"system_stats\":$SYSTEM_STATS}"

        # Add traffic stats for bandwidth graphs
        TRAFFIC_STATS=$(get_traffic_stats)
        PAYLOAD=$(echo "$PAYLOAD" | sed 's/}$//')
        PAYLOAD="${PAYLOAD},\"traffic_stats\":$TRAFFIC_STATS}"

        # Add latency statistics
        LATENCY_STATS=$(get_latency_stats)
        PAYLOAD=$(echo "$PAYLOAD" | sed 's/}$//')
        PAYLOAD="${PAYLOAD},\"latency_stats\":$LATENCY_STATS}"

        # Add update status - send both nested and top-level for compatibility
        UPDATE_INFO=$(check_updates)
        PAYLOAD=$(echo "$PAYLOAD" | sed 's/}$//')
        PAYLOAD="${PAYLOAD},\"opnsense_updates\":$UPDATE_INFO}"

        # Extract update fields to top level for server compatibility
        local upd_avail=$(echo "$UPDATE_INFO" | /usr/local/bin/python3 -c "import json,sys; d=json.load(sys.stdin); print(1 if d.get('updates_available') else 0)" 2>/dev/null || echo 0)
        local upd_ver=$(echo "$UPDATE_INFO" | /usr/local/bin/python3 -c "import json,sys; d=json.load(sys.stdin); print(d.get('new_version',''))" 2>/dev/null || echo "")
        PAYLOAD=$(echo "$PAYLOAD" | sed 's/}$//')
        PAYLOAD="${PAYLOAD},\"updates_available\":$upd_avail,\"available_version\":\"$upd_ver\"}"

        # Add WAN interface stats
        WAN_IFACE_STATS=$(get_wan_interface_stats)
        PAYLOAD=$(echo "$PAYLOAD" | sed 's/}$//')
        PAYLOAD="${PAYLOAD},\"wan_interface_stats\":$WAN_IFACE_STATS}"

        # Perform check-in
        RESPONSE=$($CURL_CMD -s -m 30 -X POST \
            -H "Content-Type: application/json" \
            -d "$PAYLOAD" \
            "$CHECKIN_URL" 2>&1)
        CURL_EXIT=$?

        if [ $CURL_EXIT -ne 0 ]; then
            ERROR_COUNT=$((ERROR_COUNT + 1))
            [ $ERROR_COUNT -gt $MAX_ERRORS ] && ERROR_COUNT=$MAX_ERRORS
            log_message "Check-in failed (exit code: $CURL_EXIT)"
        else
            if echo "$RESPONSE" | grep -q '"success"'; then
                ERROR_COUNT=0
                log_message "Check-in successful"
                process_response "$RESPONSE"
            else
                ERROR_COUNT=$((ERROR_COUNT + 1))
                log_message "Check-in failed: invalid response"
            fi
        fi

        sleep $SLEEP_TIME
    done
}

# Run main function
main "$@"
