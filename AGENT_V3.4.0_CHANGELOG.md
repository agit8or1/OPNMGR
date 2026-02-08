# OPNsense Agent v3.4.0 - WAN Interface Auto-Detection

## Overview

Version 3.4.0 introduces **automatic WAN interface detection and monitoring**. The agent now automatically identifies WAN interfaces and WAN groups from the OPNsense configuration and monitors their status, bandwidth, and performance metrics.

## New Features

### 1. **Automatic WAN Interface Detection**
- Reads `/conf/config.xml` to identify configured WAN interfaces
- Detects primary WAN and additional WAN interfaces (WAN2, WAN3, WAN_DHCP, WAN_PPPoE, etc.)
- No manual configuration needed - fully automatic detection

**Implementation:** `detect_wan_interfaces()` function (lines 38-75)

### 2. **WAN Gateway Group Detection**
- Automatically detects configured gateway groups (load balancing, failover)
- Identifies multi-WAN setups with policy-based routing

**Implementation:** `detect_wan_groups()` function (lines 78-95)

### 3. **Real-Time Interface Monitoring**
- Monitors each WAN interface independently
- Collects comprehensive statistics:
  - **Status**: up, down, no_carrier
  - **IP Address**: Currently assigned IP
  - **Media**: Interface speed and type (e.g., "1000baseT full-duplex")
  - **RX Packets**: Received packet count
  - **RX Errors**: Receive error count
  - **RX Bytes**: Total bytes received
  - **TX Packets**: Transmitted packet count
  - **TX Errors**: Transmit error count
  - **TX Bytes**: Total bytes transmitted

**Implementation:** `get_interface_stats()` and `monitor_wan_interfaces()` functions (lines 98-178)

### 4. **Enhanced Agent Check-in**
- Check-in payload now includes:
  - `wan_interfaces`: Comma-separated list of WAN interface names (e.g., "igc0,igc1")
  - `wan_groups`: Comma-separated list of gateway group names
  - `wan_interface_stats`: JSON array with detailed statistics for each WAN interface

**Sample Check-in Payload:**
```json
{
    "firewall_id": "aabbccddeeff",
    "agent_version": "3.4.0",
    "wan_ip": "1.2.3.4",
    "wan_interfaces": "igc0",
    "wan_groups": "WAN_FAILOVER,WAN_LOADBALANCE",
    "wan_interface_stats": [
        {
            "interface": "igc0",
            "status": "up",
            "ip": "1.2.3.4",
            "media": "1000baseT full-duplex",
            "rx_packets": 1234567,
            "rx_errors": 0,
            "rx_bytes": 987654321,
            "tx_packets": 654321,
            "tx_errors": 0,
            "tx_bytes": 456789123
        }
    ]
}
```

## Technical Details

### Configuration File Parsing
The agent parses `/conf/config.xml` to extract:
```xml
<interfaces>
  <wan>
    <enable>1</enable>
    <if>igc0</if>
    <ipaddr>dhcp</ipaddr>
    ...
  </wan>
</interfaces>
```

### Interface Statistics Collection
Uses FreeBSD's `netstat -ibn` command to gather:
- Packet counters (input/output)
- Byte counters (input/output)
- Error counters (input/output)

Uses `ifconfig` to determine:
- Interface operational status
- Media type and speed
- IP address assignments

## Benefits

1. **Automated Monitoring**: No manual interface configuration required
2. **Multi-WAN Support**: Automatically handles multiple WAN connections
3. **Failover Detection**: Can detect when WAN interfaces go down
4. **Bandwidth Tracking**: Monitor data usage per WAN interface
5. **Error Detection**: Identify interface errors that could indicate hardware issues

## Database Schema Updates (Required)

To store the new WAN interface data, update the `firewalls` table:

```sql
ALTER TABLE firewalls
ADD COLUMN wan_interfaces VARCHAR(255) DEFAULT NULL,
ADD COLUMN wan_groups VARCHAR(255) DEFAULT NULL,
ADD COLUMN wan_interface_stats JSON DEFAULT NULL;
```

Or create a separate table for interface statistics:

```sql
CREATE TABLE firewall_wan_interfaces (
    id INT AUTO_INCREMENT PRIMARY KEY,
    firewall_id INT NOT NULL,
    interface_name VARCHAR(50) NOT NULL,
    status VARCHAR(20),
    ip_address VARCHAR(50),
    media VARCHAR(100),
    rx_packets BIGINT,
    rx_errors BIGINT,
    rx_bytes BIGINT,
    tx_packets BIGINT,
    tx_errors BIGINT,
    tx_bytes BIGINT,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (firewall_id) REFERENCES firewalls(id) ON DELETE CASCADE,
    INDEX idx_firewall_interface (firewall_id, interface_name)
);
```

## Backend Updates (Required)

Update `agent_checkin.php` to handle new fields:

```php
// Extract new WAN interface data
$wan_interfaces = trim($input['wan_interfaces'] ?? '');
$wan_groups = trim($input['wan_groups'] ?? '');
$wan_interface_stats = $input['wan_interface_stats'] ?? null;

// Store in database
$stmt = $DB->prepare('UPDATE firewalls SET
    wan_interfaces = ?,
    wan_groups = ?,
    wan_interface_stats = ?
    WHERE id = ?');
$stmt->execute([
    $wan_interfaces,
    $wan_groups,
    json_encode($wan_interface_stats),
    $firewall_id
]);
```

## Deployment

### Option 1: Direct Installation
```bash
fetch -o /tmp/opnsense_agent_v3.4.0.sh https://opn.agit8or.net/download/opnsense_agent_v3.4.0.sh
chmod +x /tmp/opnsense_agent_v3.4.0.sh
```

### Option 2: Update Existing Cron Job
```bash
# Edit crontab
crontab -e

# Update to use v3.4.0
*/2 * * * * /usr/local/sbin/opnsense_agent_v3.4.0.sh
```

## Testing

Test the agent locally:
```bash
# Run the agent once
sh /path/to/opnsense_agent_v3.4.0.sh

# Check the log
tail -f /var/log/opnsense_agent.log

# Expected log output:
# 2026-01-08 12:00:00 - Detected WAN interface(s): igc0
# 2026-01-08 12:00:01 - Starting agent checkin with WAN interface monitoring...
# 2026-01-08 12:00:02 - Checkin data prepared with WAN interfaces: igc0
# 2026-01-08 12:00:03 - Checkin successful
```

## Compatibility

- **OPNsense**: 21.x, 22.x, 23.x, 24.x, 25.x
- **FreeBSD**: 12.x, 13.x, 14.x
- **Requires**: Python 3 (for JSON parsing in proxy request handling)

## Future Enhancements

Potential additions for v3.5.0:
- Gateway status monitoring (ping response times, packet loss)
- Interface bandwidth usage rate (Mbps)
- Historical bandwidth graphs
- Alert thresholds for interface errors
- VLAN interface detection and monitoring
- PPPoE/PPtP connection status

## Support

For issues or questions:
- Check `/var/log/opnsense_agent.log` for diagnostic information
- Verify `/conf/config.xml` exists and is readable
- Ensure firewall has outbound connectivity to management server

---

**Agent Version**: 3.4.0
**Release Date**: 2026-01-08
**Author**: OPNsense Management Platform Team
