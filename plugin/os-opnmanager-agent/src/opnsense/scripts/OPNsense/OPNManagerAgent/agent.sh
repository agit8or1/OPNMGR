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
AGENT_VERSION="1.5.6"
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

    local link_counter_file="/tmp/opnmanager_link_counter_${wan_iface}"

    # Get Link layer and IP layer counters
    local link_line=$(netstat -ibn 2>/dev/null | grep "^${wan_iface}" | grep '<Link' | head -1)
    local ip_line=$(netstat -ibn 2>/dev/null | grep "^${wan_iface}" | grep -v '<Link' | grep -v '^\s' | head -1)

    local link_bytes_in=0 link_bytes_out=0
    local ip_bytes_in=0 ip_bytes_out=0

    if [ -n "$link_line" ]; then
        link_bytes_in=$(echo "$link_line" | awk '{print $8}')
        link_bytes_out=$(echo "$link_line" | awk '{print $11}')
        [ -z "$link_bytes_in" ] && link_bytes_in=0
        [ -z "$link_bytes_out" ] && link_bytes_out=0
    fi

    if [ -n "$ip_line" ]; then
        ip_bytes_in=$(echo "$ip_line" | awk '{print $8}')
        ip_bytes_out=$(echo "$ip_line" | awk '{print $11}')
        [ -z "$ip_bytes_in" ] && ip_bytes_in=0
        [ -z "$ip_bytes_out" ] && ip_bytes_out=0
    fi

    # Detect frozen/broken Link layer counters (common with virtio_net)
    # Compare current Link counter with previous reading
    local link_broken=0
    if [ -f "$link_counter_file" ]; then
        local prev_link=$(cat "$link_counter_file" 2>/dev/null)
        if [ "$link_bytes_in" = "$prev_link" ] && [ "$ip_bytes_in" -gt "$link_bytes_in" ] 2>/dev/null; then
            link_broken=1
            if [ ! -f "/tmp/opnmanager_link_broken_${wan_iface}" ]; then
                log_message "Link layer counter frozen on $wan_iface (stuck at $link_bytes_in), using pf counters"
                touch "/tmp/opnmanager_link_broken_${wan_iface}"
            fi
        fi
    fi
    echo "$link_bytes_in" > "$link_counter_file"

    local bytes_in=0 bytes_out=0 packets_in=0 packets_out=0

    if [ "$link_broken" -eq 1 ]; then
        # Link counters frozen (virtio_net bug) - use pf interface counters
        # pfctl -vvsI tracks all traffic through the packet filter (including forwarded/NAT)
        local pf_stats=$(pfctl -vvsI -i "$wan_iface" 2>/dev/null)
        local pf_in4_pass=$(echo "$pf_stats" | grep 'In4/Pass' | awk -F'Bytes:' '{print $2}' | awk '{print $1}')
        local pf_out4_pass=$(echo "$pf_stats" | grep 'Out4/Pass' | awk -F'Bytes:' '{print $2}' | awk '{print $1}')
        local pf_in4_block=$(echo "$pf_stats" | grep 'In4/Block' | awk -F'Bytes:' '{print $2}' | awk '{print $1}')
        local pf_out4_block=$(echo "$pf_stats" | grep 'Out4/Block' | awk -F'Bytes:' '{print $2}' | awk '{print $1}')

        # Ensure numeric
        case "$pf_in4_pass" in ''|*[!0-9]*) pf_in4_pass=0 ;; esac
        case "$pf_out4_pass" in ''|*[!0-9]*) pf_out4_pass=0 ;; esac
        case "$pf_in4_block" in ''|*[!0-9]*) pf_in4_block=0 ;; esac
        case "$pf_out4_block" in ''|*[!0-9]*) pf_out4_block=0 ;; esac

        # Total bytes = pass + block (all traffic seen by pf on this interface)
        bytes_in=$((pf_in4_pass + pf_in4_block))
        bytes_out=$((pf_out4_pass + pf_out4_block))

        # Get packet counts too
        local pf_pkts_in=$(echo "$pf_stats" | grep 'In4/Pass' | awk -F'Packets:' '{print $2}' | awk '{print $1}')
        local pf_pkts_out=$(echo "$pf_stats" | grep 'Out4/Pass' | awk -F'Packets:' '{print $2}' | awk '{print $1}')
        case "$pf_pkts_in" in ''|*[!0-9]*) pf_pkts_in=0 ;; esac
        case "$pf_pkts_out" in ''|*[!0-9]*) pf_pkts_out=0 ;; esac
        packets_in=$pf_pkts_in
        packets_out=$pf_pkts_out

        # If pf counters are zero or unavailable, fall back to IP layer
        if [ "$bytes_in" -eq 0 ] 2>/dev/null && [ "$ip_bytes_in" -gt 0 ] 2>/dev/null; then
            bytes_in=$ip_bytes_in
            bytes_out=$ip_bytes_out
            packets_in=$((bytes_in / 500))
            packets_out=$((bytes_out / 500))
            log_message "pf counters unavailable, falling back to IP layer"
        fi
    else
        # Link layer working - use whichever counter is higher
        if [ "$link_bytes_in" -ge "$ip_bytes_in" ] 2>/dev/null; then
            # Use Link layer (includes forwarded/NAT traffic)
            bytes_in=$link_bytes_in
            bytes_out=$link_bytes_out
            local link_pkts_in=$(echo "$link_line" | awk '{print $5}')
            local link_pkts_out=$(echo "$link_line" | awk '{print $9}')
            packets_in=${link_pkts_in:-0}
            packets_out=${link_pkts_out:-0}
        else
            # Link layer seems low - use IP layer
            bytes_in=$ip_bytes_in
            bytes_out=$ip_bytes_out
            local ip_pkts_in=$(echo "$ip_line" | awk '{print $5}')
            local ip_pkts_out=$(echo "$ip_line" | awk '{print $9}')
            packets_in=${ip_pkts_in:-0}
            packets_out=${ip_pkts_out:-0}
        fi

    fi

    # Convert dashes and empty values to zero
    [ -z "$bytes_in" ] || [ "$bytes_in" = "-" ] && bytes_in=0
    [ -z "$bytes_out" ] || [ "$bytes_out" = "-" ] && bytes_out=0
    [ -z "$packets_in" ] || [ "$packets_in" = "-" ] && packets_in=0
    [ -z "$packets_out" ] || [ "$packets_out" = "-" ] && packets_out=0

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

    # Use only server latency (target3) for management purposes
    # Internet pings (8.8.8.8, 1.1.1.1) are logged but not used for avg
    local avg_latency="$ping3"

    # Fallback to average if server ping failed
    if [ "$avg_latency" = "0" ] || [ -z "$avg_latency" ]; then
        local count=0
        avg_latency=0
        for lat in $ping1 $ping2; do
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
    fi

    cat <<EOF
{"target1":"$target1","latency1":$ping1,"target2":"$target2","latency2":$ping2,"target3":"$target3","latency3":$ping3,"average_latency":$avg_latency}
EOF
}

# Run iperf3 bandwidth test to public server (WAN test)
# Tries multiple servers with retry logic for reliability
run_iperf3_test() {
    log_message "Running iperf3 WAN bandwidth test..."

    # Check if iperf3 is installed
    if ! command -v iperf3 >/dev/null 2>&1; then
        log_message "iperf3 not installed, attempting to install..."
        if command -v pkg >/dev/null 2>&1; then
            pkg install -y iperf3 >/dev/null 2>&1
        fi
    fi

    if ! command -v iperf3 >/dev/null 2>&1; then
        log_message "iperf3 not available"
        echo '{"error":"iperf3 not available"}'
        return 1
    fi

    # List of public iperf3 servers to try (in order of preference)
    local servers="iperf.he.net bouygues.iperf.fr speedtest.wtnet.de iperf.par2.as49434.net"

    for iperf_server in $servers; do
        log_message "Trying iperf3 server: $iperf_server"

        # Test download (reverse mode) with 4 parallel streams
        local download_result=$(timeout 30 iperf3 -c "$iperf_server" -R -P 4 -t 8 -J 2>&1)
        local download_exit=$?

        # Check if server was busy
        if echo "$download_result" | grep -q "server is busy"; then
            log_message "Server $iperf_server is busy, trying next..."
            sleep 1
            continue
        fi

        local download=0
        if [ $download_exit -eq 0 ] && [ -n "$download_result" ]; then
            download=$(echo "$download_result" | /usr/local/bin/python3 -c "import json,sys; data=json.load(sys.stdin); print(int(data.get('end',{}).get('sum_received',{}).get('bits_per_second',0)/1000000))" 2>/dev/null)
        fi

        if [ -z "$download" ] || [ "$download" -le 0 ] 2>/dev/null; then
            log_message "Download test failed on $iperf_server, trying next..."
            continue
        fi

        sleep 2

        # Test upload (normal mode) with 4 parallel streams
        local upload_result=$(timeout 30 iperf3 -c "$iperf_server" -P 4 -t 8 -J 2>&1)
        local upload_exit=$?

        local upload=0
        if [ $upload_exit -eq 0 ] && [ -n "$upload_result" ]; then
            upload=$(echo "$upload_result" | /usr/local/bin/python3 -c "import json,sys; data=json.load(sys.stdin); print(int(data.get('end',{}).get('sum_sent',{}).get('bits_per_second',0)/1000000))" 2>/dev/null)
        fi

        [ -z "$upload" ] && upload=0

        if [ "$download" -gt 0 ]; then
            log_message "iperf3 test complete: Down=${download}Mbps, Up=${upload}Mbps (to $iperf_server)"
            cat <<EOF
{"download_mbps":$download,"upload_mbps":$upload,"ping_ms":0,"server":"WAN-$iperf_server","timestamp":"$(date '+%Y-%m-%d %H:%M:%S')","test_type":"iperf3"}
EOF
            return 0
        fi
    done

    log_message "All iperf3 servers failed or busy"
    echo '{"error":"iperf3 test failed - all servers busy or unreachable"}'
    return 1
}

# Run speedtest (only when requested by server) - uses iperf3 for accurate WAN bandwidth
# Tests to public iperf3 servers that can handle multiple connections
run_speedtest() {
    log_message "Running bandwidth test..."

    # Use iperf3 for WAN bandwidth test to public server
    local iperf_result=$(run_iperf3_test)

    if echo "$iperf_result" | grep -q '"download_mbps"'; then
        # iperf3 succeeded
        echo "$iperf_result"
        return 0
    else
        # iperf3 failed
        log_message "Bandwidth test failed"
        echo '{"error":"Bandwidth test failed - all iperf3 servers unavailable"}'
        return 1
    fi
}

# Detect WAN interfaces from config.xml
detect_wan_interfaces() {
    local wan_interfaces=""

    if [ ! -f "$CONFIG_FILE" ]; then
        echo ""
        return
    fi

    # Try xml command first
    if command -v xml >/dev/null 2>&1; then
        # Get interfaces configured as WAN (type='wan' or descr contains 'WAN')
        wan_interfaces=$(xml sel -t -m "//interfaces/*[enable='1']" -v "if" -n "$CONFIG_FILE" 2>/dev/null | while read iface; do
            # Check if interface has a real physical device assigned
            local ifname=$(xml sel -t -v "//interfaces/$iface/if" "$CONFIG_FILE" 2>/dev/null)
            [ -n "$ifname" ] && echo "$ifname"
        done | head -5 | tr '\n' ',' | sed 's/,$//')
    elif command -v xmllint >/dev/null 2>&1; then
        # Fallback to xmllint
        wan_interfaces=$(xmllint --xpath "//interfaces/*/if/text()" "$CONFIG_FILE" 2>/dev/null | head -5 | tr '\n' ',' | sed 's/,$//')
    fi

    # Fallback: detect from routing table
    if [ -z "$wan_interfaces" ]; then
        wan_interfaces=$(netstat -rn 2>/dev/null | grep default | awk '{print $NF}' | sort -u | head -3 | tr '\n' ',' | sed 's/,$//')
    fi

    echo "$wan_interfaces"
}

# Detect WAN gateway groups
detect_wan_groups() {
    local wan_groups=""

    if [ ! -f "$CONFIG_FILE" ]; then
        echo ""
        return
    fi

    # Try to get gateway groups from config
    if command -v xml >/dev/null 2>&1; then
        wan_groups=$(xml sel -t -m "//gateways/gateway_group" -v "name" -o "," "$CONFIG_FILE" 2>/dev/null | sed 's/,$//')
    elif command -v xmllint >/dev/null 2>&1; then
        wan_groups=$(xmllint --xpath "//gateways/gateway_group/name/text()" "$CONFIG_FILE" 2>/dev/null | tr '\n' ',' | sed 's/,$//')
    fi

    echo "$wan_groups"
}

# Get WAN interface statistics
get_wan_interface_stats() {
    local wan_interfaces="$1"

    if [ -z "$wan_interfaces" ]; then
        echo "[]"
        return
    fi

    # Use a temp file to avoid subshell issues with while loops in pipes
    local stats_json="["
    local first=1

    # Process each interface
    local IFS=','
    for iface in $wan_interfaces; do
        # Skip empty
        [ -z "$iface" ] && continue

        # Skip loopback and wireguard virtual interfaces for now
        echo "$iface" | grep -qE '^(lo[0-9]|wg[0-9]|wireguard)' && continue

        # Get interface status from ifconfig
        local iface_info=$(ifconfig "$iface" 2>/dev/null)
        if [ -z "$iface_info" ]; then
            continue
        fi

        # Parse status
        local status="down"
        echo "$iface_info" | grep -q "status: active" && status="active"
        echo "$iface_info" | grep -q "status: no carrier" && status="no carrier"

        # Parse IP address and netmask
        local ip_line=$(echo "$iface_info" | grep 'inet ' | head -1)
        local ip_address=$(echo "$ip_line" | awk '{print $2}')
        local netmask=$(echo "$ip_line" | awk '{print $4}')

        # Get gateway from routing table
        local gateway=$(netstat -rn 2>/dev/null | grep "^default" | grep "$iface" | head -1 | awk '{print $2}')

        # Get media information
        local media=$(echo "$iface_info" | grep "media:" | sed 's/.*media: //' | sed 's/ .*//')

        # Get statistics from netstat (Link layer)
        local stats=$(netstat -ibn 2>/dev/null | grep "^${iface}" | grep '<Link' | head -1)
        local rx_packets=0
        local rx_bytes=0
        local rx_errors=0
        local tx_packets=0
        local tx_bytes=0
        local tx_errors=0

        if [ -n "$stats" ]; then
            rx_packets=$(echo "$stats" | awk '{print $5}')
            rx_bytes=$(echo "$stats" | awk '{print $8}')
            rx_errors=$(echo "$stats" | awk '{print $6}')
            tx_packets=$(echo "$stats" | awk '{print $9}')
            tx_bytes=$(echo "$stats" | awk '{print $11}')
            tx_errors=$(echo "$stats" | awk '{print $10}')
        fi

        # Check if Link counters are frozen (virtio_net bug) - use pf counters instead
        local link_check_file="/tmp/opnmanager_link_counter_${iface}"
        if [ -f "$link_check_file" ]; then
            local prev_link_val=$(cat "$link_check_file" 2>/dev/null)
            local ip_stats_line=$(netstat -ibn 2>/dev/null | grep "^${iface}" | grep -v '<Link' | grep -v '^\s' | head -1)
            local ip_rx=$(echo "$ip_stats_line" | awk '{print $8}')
            case "$ip_rx" in ''|*[!0-9]*) ip_rx=0 ;; esac
            if [ "$rx_bytes" = "$prev_link_val" ] && [ "$ip_rx" -gt "$rx_bytes" ] 2>/dev/null; then
                # Link frozen - use pf interface counters
                local pf_data=$(pfctl -vvsI -i "$iface" 2>/dev/null)
                local pf_rx=$(echo "$pf_data" | grep 'In4/Pass' | awk -F'Bytes:' '{print $2}' | awk '{print $1}')
                local pf_tx=$(echo "$pf_data" | grep 'Out4/Pass' | awk -F'Bytes:' '{print $2}' | awk '{print $1}')
                local pf_rx_blk=$(echo "$pf_data" | grep 'In4/Block' | awk -F'Bytes:' '{print $2}' | awk '{print $1}')
                local pf_tx_blk=$(echo "$pf_data" | grep 'Out4/Block' | awk -F'Bytes:' '{print $2}' | awk '{print $1}')
                local pf_rx_pkts=$(echo "$pf_data" | grep 'In4/Pass' | awk -F'Packets:' '{print $2}' | awk '{print $1}')
                local pf_tx_pkts=$(echo "$pf_data" | grep 'Out4/Pass' | awk -F'Packets:' '{print $2}' | awk '{print $1}')
                case "$pf_rx" in ''|*[!0-9]*) pf_rx=0 ;; esac
                case "$pf_tx" in ''|*[!0-9]*) pf_tx=0 ;; esac
                case "$pf_rx_blk" in ''|*[!0-9]*) pf_rx_blk=0 ;; esac
                case "$pf_tx_blk" in ''|*[!0-9]*) pf_tx_blk=0 ;; esac
                case "$pf_rx_pkts" in ''|*[!0-9]*) pf_rx_pkts=0 ;; esac
                case "$pf_tx_pkts" in ''|*[!0-9]*) pf_tx_pkts=0 ;; esac
                if [ "$pf_rx" -gt 0 ] 2>/dev/null; then
                    rx_bytes=$((pf_rx + pf_rx_blk))
                    tx_bytes=$((pf_tx + pf_tx_blk))
                    rx_packets=$pf_rx_pkts
                    tx_packets=$pf_tx_pkts
                fi
            fi
        fi

        # Build JSON for this interface
        [ "$first" -eq 0 ] && stats_json="${stats_json},"
        first=0

        stats_json="${stats_json}{\"interface\":\"$iface\",\"status\":\"$status\",\"ip_address\":\"$ip_address\",\"netmask\":\"$netmask\",\"gateway\":\"$gateway\",\"media\":\"$media\",\"rx_packets\":${rx_packets:-0},\"rx_bytes\":${rx_bytes:-0},\"rx_errors\":${rx_errors:-0},\"tx_packets\":${tx_packets:-0},\"tx_bytes\":${tx_bytes:-0},\"tx_errors\":${tx_errors:-0}}"
    done

    stats_json="${stats_json}]"
    echo "$stats_json"
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

    # Detect WAN interfaces and groups (v1.5.0 feature)
    local wan_interfaces=$(detect_wan_interfaces)
    local wan_groups=$(detect_wan_groups)

    # Network config details (v1.5.4 feature)
    local wan_iface=$(netstat -rn 2>/dev/null | grep '^default' | head -1 | awk '{print $NF}')
    [ -z "$wan_iface" ] && wan_iface="em0"

    local wan_netmask=$(ifconfig "$wan_iface" 2>/dev/null | grep 'inet ' | head -1 | awk '{print $4}')
    # Convert hex netmask to dotted decimal
    if echo "$wan_netmask" | grep -q '^0x'; then
        wan_netmask=$(printf "%d.%d.%d.%d" \
            $(( $(printf '%d' "$wan_netmask") >> 24 & 255 )) \
            $(( $(printf '%d' "$wan_netmask") >> 16 & 255 )) \
            $(( $(printf '%d' "$wan_netmask") >> 8 & 255 )) \
            $(( $(printf '%d' "$wan_netmask") & 255 )))
    fi

    local wan_gateway=$(netstat -rn 2>/dev/null | grep '^default' | head -1 | awk '{print $2}')
    local wan_dns_primary=$(awk '/^nameserver/{print $2; exit}' /etc/resolv.conf 2>/dev/null)
    local wan_dns_secondary=$(awk '/^nameserver/{n++; if(n==2) print $2}' /etc/resolv.conf 2>/dev/null)

    # LAN network config
    local lan_netmask=""
    local lan_network=""
    if [ -n "$lan_ip" ]; then
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
            if [ -n "$lan_netmask" ] && [ -n "$lan_ip" ]; then
                local oIFS="$IFS"; IFS='.'
                set -- $lan_ip; local ip1=$1 ip2=$2 ip3=$3 ip4=$4
                set -- $lan_netmask; local nm1=$1 nm2=$2 nm3=$3 nm4=$4
                IFS="$oIFS"
                lan_network="$((ip1 & nm1)).$((ip2 & nm2)).$((ip3 & nm3)).$((ip4 & nm4))"
            fi
        fi
    fi

    cat <<EOF
{"hardware_id":"$HARDWARE_ID","agent_version":"$AGENT_VERSION","wan_ip":"$wan_ip","lan_ip":"$lan_ip","ipv6_address":"$ipv6_address","opnsense_version":"$version","uptime":"$uptime_info","hostname":"$hostname","wan_interfaces":"$wan_interfaces","wan_groups":"$wan_groups","wan_netmask":"${wan_netmask:-}","wan_gateway":"${wan_gateway:-}","wan_dns_primary":"${wan_dns_primary:-}","wan_dns_secondary":"${wan_dns_secondary:-}","lan_netmask":"${lan_netmask:-}","lan_network":"${lan_network:-}"}
EOF
}

# Check for available updates
check_updates() {
    local updates_available="false"
    local new_version=""

    # Method 1: opnsense-update -c (most reliable for OPNsense updates)
    if command -v opnsense-update >/dev/null 2>&1; then
        local update_output=$(opnsense-update -c 2>&1)
        local update_exit=$?
        # Exit code 0 means updates available
        if [ $update_exit -eq 0 ] && [ -n "$update_output" ]; then
            updates_available="true"
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

    # Check for agent update notification
    local agent_update_available=$(echo "$response" | /usr/local/bin/python3 -c "
import json, sys
try:
    data = json.load(sys.stdin)
    print('true' if data.get('agent_update_available', False) else 'false')
except:
    print('false')
" 2>/dev/null)

    if [ "$agent_update_available" = "true" ]; then
        local new_version=$(echo "$response" | /usr/local/bin/python3 -c "
import json, sys
try:
    data = json.load(sys.stdin)
    print(data.get('latest_version', ''))
except:
    print('')
" 2>/dev/null)

        local update_cmd=$(echo "$response" | /usr/local/bin/python3 -c "
import json, sys
try:
    data = json.load(sys.stdin)
    print(data.get('update_command', ''))
except:
    print('')
" 2>/dev/null)

        if [ -n "$update_cmd" ]; then
            log_message "Agent update available: v$new_version (current: $AGENT_VERSION)"
            log_message "Executing agent update command..."
            nohup sh -c "$update_cmd" > /dev/null 2>&1 &
        fi
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
                # Execute command with error handling and timeout
                result=""
                cmd_status="completed"

                # Use a subshell with timeout to prevent agent crashes
                (
                    if [ "$cmd_data" = "run_speedtest" ]; then
                        log_message "Running speedtest as requested"
                        result=$(run_speedtest 2>&1)
                        echo "$result" > /tmp/cmd_${cmd_id}_result.txt

                        # Send speedtest results to server
                        $CURL_CMD -s -m 10 -X POST \
                            -H "Content-Type: application/json" \
                            -d "{\"hardware_id\":\"$HARDWARE_ID\",\"agent_version\":\"$AGENT_VERSION\",\"speedtest_result\":$result}" \
                            "${SERVER_URL}/agent_checkin.php" > /dev/null 2>&1
                    else
                        # Execute regular shell command with timeout
                        # Limit output and execution time to prevent hangs
                        timeout 300 sh -c "$cmd_data" 2>&1 | head -1000 > /tmp/cmd_${cmd_id}_result.txt || echo "Command timed out or failed" >> /tmp/cmd_${cmd_id}_result.txt
                    fi
                ) &

                cmd_pid=$!

                # Wait up to 310 seconds for command to complete
                wait_count=0
                while kill -0 $cmd_pid 2>/dev/null && [ $wait_count -lt 310 ]; do
                    sleep 1
                    wait_count=$((wait_count + 1))
                done

                # If still running, kill it
                if kill -0 $cmd_pid 2>/dev/null; then
                    log_message "Command $cmd_id exceeded timeout, killing..."
                    kill -9 $cmd_pid 2>/dev/null
                    result="Command execution timeout exceeded (310s)"
                    cmd_status="failed"
                else
                    # Read result from temp file
                    if [ -f /tmp/cmd_${cmd_id}_result.txt ]; then
                        result=$(cat /tmp/cmd_${cmd_id}_result.txt)
                        rm -f /tmp/cmd_${cmd_id}_result.txt
                    fi
                fi

                # Base64 encode result and remove newlines to prevent JSON breakage
                result_b64=$(echo "$result" | base64 2>/dev/null | tr -d '\n')

                # Report result back to server
                $CURL_CMD -s -m 10 -X POST \
                    -H "Content-Type: application/json" \
                    -d "{\"hardware_id\":\"$HARDWARE_ID\",\"command_id\":$cmd_id,\"status\":\"$cmd_status\",\"result\":\"$result_b64\"}" \
                    "${SERVER_URL}/agent_checkin.php" > /dev/null 2>&1

                log_message "Command $cmd_id $cmd_status"
            fi
        fi
    done
}

# Configure alternate hostname for HTTP_REFERER check (one-time configuration)
configure_alternate_hostnames() {
    local config_marker="/tmp/.opnmanager_althostnames_configured"

    # Skip if already configured
    if [ -f "$config_marker" ]; then
        return 0
    fi

    # Extract server hostname from SERVER_URL
    local server_hostname=$(echo "$SERVER_URL" | sed 's|https\?://||' | sed 's|/.*||')

    if [ -z "$server_hostname" ]; then
        log_message "WARNING: Could not extract server hostname from $SERVER_URL"
        return 1
    fi

    log_message "Configuring alternate hostname: $server_hostname"

    # Check if already configured
    if grep -q "<althostnames>.*$server_hostname" /conf/config.xml 2>/dev/null; then
        log_message "Alternate hostname already configured"
        touch "$config_marker"
        return 0
    fi

    # Backup config
    cp /conf/config.xml /conf/config.xml.bak_althost 2>/dev/null

    # Add or update alternate hostnames
    if grep -q "<webgui>" /conf/config.xml; then
        if grep -q "<althostnames>" /conf/config.xml; then
            # Append to existing
            sed -i '' "s|<althostnames>\(.*\)</althostnames>|<althostnames>\1 $server_hostname</althostnames>|" /conf/config.xml
        else
            # Add new tag
            sed -i '' "s|<webgui>|<webgui><althostnames>$server_hostname</althostnames>|" /conf/config.xml
        fi

        # Restart webgui to apply
        /usr/local/etc/rc.restart_webgui >/dev/null 2>&1 &

        log_message "Alternate hostname configured: $server_hostname"
        touch "$config_marker"
        return 0
    else
        log_message "WARNING: Could not find <webgui> section in config.xml"
        return 1
    fi
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

    # One-time configuration: Add alternate hostname for HTTP_REFERER check
    configure_alternate_hostnames

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
                # Clean up PID file and exit - let rc.d restart us
                rm -f "$PID_FILE"
                # Kill this process and all children
                kill -TERM $$ 2>/dev/null
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

        # Add update status
        UPDATE_INFO=$(check_updates)
        # Extract values from UPDATE_INFO and add as flat fields for backend compatibility
        UPDATES_AVAIL=$(echo "$UPDATE_INFO" | grep -o '"updates_available":[^,}]*' | sed 's/"updates_available"://' | sed 's/"//g')
        NEW_VERSION=$(echo "$UPDATE_INFO" | grep -o '"new_version":"[^"]*"' | sed 's/"new_version":"//' | sed 's/"$//')

        # Convert true/false to 1/0 for updates_available
        if [ "$UPDATES_AVAIL" = "true" ]; then
            UPDATES_AVAIL=1
        else
            UPDATES_AVAIL=0
        fi

        PAYLOAD=$(echo "$PAYLOAD" | sed 's/}$//')
        PAYLOAD="${PAYLOAD},\"updates_available\":$UPDATES_AVAIL,\"available_version\":\"$NEW_VERSION\"}"

        # Add WAN interface statistics (v1.5.0 feature)
        # Extract wan_interfaces from PAYLOAD to pass to stats function
        WAN_IFACES=$(echo "$PAYLOAD" | grep -o '"wan_interfaces":"[^"]*"' | sed 's/"wan_interfaces":"//' | sed 's/"$//')
        if [ -n "$WAN_IFACES" ]; then
            WAN_STATS=$(get_wan_interface_stats "$WAN_IFACES")
            PAYLOAD=$(echo "$PAYLOAD" | sed 's/}$//')
            PAYLOAD="${PAYLOAD},\"wan_interface_stats\":$WAN_STATS}"
        fi

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
