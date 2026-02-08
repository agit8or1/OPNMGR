# OPNManager Changelog - v3.4.0 Release

## Version 3.4.0 - WAN Interface Auto-Detection & Monitoring (2026-01-08)

### 🎯 FOCUS: Intelligent Network Interface Detection and Monitoring

This release introduces **automatic WAN interface detection and comprehensive monitoring** capabilities to the OPNsense management agent. The agent now intelligently identifies which network interfaces are configured as WAN in your OPNsense firewall and provides real-time monitoring of their status, performance, and health.

---

## ✨ Major New Features

### 1. Automatic WAN Interface Detection
**Feature**: Agent automatically discovers WAN interfaces from OPNsense configuration
**Implementation**: Reads `/conf/config.xml` to identify WAN interfaces without manual configuration

**Benefits**:
- Zero configuration required - fully automatic
- Supports multiple WAN interfaces (WAN, WAN2, WAN3, WAN_DHCP, WAN_PPPoE, etc.)
- Works with any interface naming convention (igc0, igb0, em0, etc.)
- Detects changes in WAN configuration automatically

**How It Works**:
```bash
# Agent parses config.xml
<interfaces>
  <wan>
    <if>igc0</if>  ← Automatically detected
  </wan>
  <wan2>
    <if>igc1</if>  ← Additional WANs detected
  </wan2>
</interfaces>
```

**Files Added**:
- `scripts/opnsense_agent_v3.4.0.sh` - Enhanced agent with detection logic
- Function: `detect_wan_interfaces()` (lines 38-75)

### 2. WAN Gateway Group Detection
**Feature**: Identifies multi-WAN configurations with load balancing and failover groups
**Implementation**: Parses gateway group configuration from config.xml

**Use Cases**:
- Multi-WAN load balancing setups
- WAN failover configurations
- Policy-based routing with multiple gateways

**Files Modified**:
- `scripts/opnsense_agent_v3.4.0.sh`
- Function: `detect_wan_groups()` (lines 78-95)

### 3. Comprehensive Interface Monitoring
**Feature**: Real-time monitoring of WAN interface health and performance

**Metrics Collected Per Interface**:
- **Status**: up, down, no_carrier (physical link status)
- **IP Address**: Currently assigned public/private IP
- **Media Type**: Interface speed and duplex (e.g., "1000baseT full-duplex")
- **RX Statistics**: Received packets, bytes, errors
- **TX Statistics**: Transmitted packets, bytes, errors

**Data Sources**:
- `netstat -ibn` for packet/byte counters
- `ifconfig` for status, IP, and media information

**Example Output**:
```json
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
```

**Files Modified**:
- `scripts/opnsense_agent_v3.4.0.sh`
- Functions: `get_interface_stats()`, `monitor_wan_interfaces()` (lines 98-178)

### 4. Enhanced Agent Check-in Protocol
**Feature**: Agent now reports WAN interface data to management server

**New Fields in Check-in Payload**:
```json
{
  "wan_interfaces": "igc0,igc1",           // Comma-separated interface names
  "wan_groups": "WAN_FAILOVER,WAN_LB",     // Gateway groups
  "wan_interface_stats": [                 // Detailed stats array
    {
      "interface": "igc0",
      "status": "up",
      "ip": "1.2.3.4",
      "rx_bytes": 987654321,
      "tx_bytes": 456789123
    }
  ]
}
```

**Files Modified**:
- `scripts/opnsense_agent_v3.4.0.sh` - Function: `perform_checkin()` (lines 310-431)

---

## 🗄️ Database Schema Changes

### New Columns in `firewalls` Table
```sql
ALTER TABLE firewalls
ADD COLUMN wan_interfaces VARCHAR(255) DEFAULT NULL,
ADD COLUMN wan_groups VARCHAR(255) DEFAULT NULL,
ADD COLUMN wan_interface_stats JSON DEFAULT NULL;
```

### New Table: `firewall_wan_interfaces`
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
    UNIQUE KEY unique_firewall_interface (firewall_id, interface_name)
);
```

### New View: `v_firewall_wan_status`
```sql
CREATE OR REPLACE VIEW v_firewall_wan_status AS
SELECT
    f.id AS firewall_id,
    f.hostname,
    f.wan_interfaces,
    f.wan_groups,
    w.interface_name,
    w.status AS interface_status,
    w.ip_address AS interface_ip,
    w.rx_bytes,
    w.tx_bytes,
    w.rx_errors,
    w.tx_errors
FROM firewalls f
LEFT JOIN firewall_wan_interfaces w ON f.id = w.firewall_id;
```

**Files Added**:
- `database/migrate_v3.4.0.sql` - Complete migration script

---

## 🔧 Backend Updates

### 1. Agent Check-in Handler Updates
**File**: `agent_checkin.php`

**New Input Fields**:
```php
$wan_interfaces = trim($input['wan_interfaces'] ?? '');
$wan_groups = trim($input['wan_groups'] ?? '');
$wan_interface_stats = $input['wan_interface_stats'] ?? null;
```

**Updated SQL Queries**:
- Modified UPDATE statements to include new WAN interface columns
- Preserves network config when not provided by agent

**New Function**:
```php
function processWANInterfaceStats($firewall_id, $wan_interface_stats) {
    // Inserts/updates firewall_wan_interfaces table
    // Tracks individual interface statistics over time
}
```

**Files Modified**:
- `agent_checkin.php` - Core check-in handler
- Reference: `agent_checkin_v3.4.0_update.php` - Update guide

### 2. Download Agent Script Updates
**File**: `download_tunnel_agent.php`

**Changes**:
- Updated default version from 2.4.0 to 3.4.0
- Added version mapping for all agent releases
- Supports version parameter: `?version=3.4.0`
- Logs all agent downloads for tracking

**Version Mapping**:
```php
$agent_versions = [
    '3.4.0' => __DIR__ . '/download/opnsense_agent_v3.4.0.sh',
    '3.3.0' => __DIR__ . '/download/opnsense_agent_v3.3.0.sh',
    '3.2.1' => __DIR__ . '/download/opnsense_agent_v3.2.1.sh',
    // ... older versions ...
];
```

**Files Modified**:
- `download_tunnel_agent.php` (lines 1-60)

### 3. Agent Version Detection Updates
**File**: `agent_checkin.php` - `checkAgentUpdate()` function

**Changes**:
- Updated `$latest_agent_version` from "2.3.0" to "3.4.0"
- Re-enabled automatic agent update checks
- Agents on older versions will be notified of v3.4.0 availability

**Files Modified**:
- `agent_checkin.php` (lines 203-204, 334)

```php
// Before
$latest_agent_version = "2.3.0";
$agent_update_check = ['update_available' => false, ...];

// After
$latest_agent_version = "3.4.0"; // Latest version with WAN interface auto-detection
$agent_update_check = checkAgentUpdate($agent_version, $firewall_id);
```

---

## 📦 Deployment

### Agent Deployment Locations
- ✅ `download/opnsense_agent_v3.4.0.sh` (18,927 bytes)
- ✅ `downloads/opnsense_agent_v3.4.0.sh` (18,927 bytes)
- ✅ `scripts/opnsense_agent_v3.4.0.sh` (source)

### Download URLs
- `https://opn.agit8or.net/download/opnsense_agent_v3.4.0.sh`
- `https://opn.agit8or.net/downloads/opnsense_agent_v3.4.0.sh`
- `https://opn.agit8or.net/download_tunnel_agent.php?firewall_id=X` (defaults to v3.4.0)

### Installation Command
```bash
fetch -o /tmp/agent_v3.4.0.sh https://opn.agit8or.net/download/opnsense_agent_v3.4.0.sh
chmod +x /tmp/agent_v3.4.0.sh
/tmp/agent_v3.4.0.sh
```

### Deployment Script
```bash
./deploy_agent_v3.4.0.sh
```

**Files Added**:
- `deploy_agent_v3.4.0.sh` - Automated deployment script

---

## 📋 Migration Checklist

### Required Steps
- [ ] **Run database migration**: `mysql < database/migrate_v3.4.0.sql`
- [ ] **Update agent_checkin.php**: Apply changes from `agent_checkin_v3.4.0_update.php`
- [ ] **Deploy agent to firewalls**: Use download URLs or push via management interface
- [ ] **Verify agent log**: Check `/var/log/opnsense_agent.log` for WAN detection
- [ ] **Test database**: Query `firewall_wan_interfaces` table for stats

### Optional Steps
- [ ] Update firewall details page to display WAN interface statistics
- [ ] Create dashboard widgets for WAN interface health monitoring
- [ ] Set up alerts for WAN interface failures or high error rates
- [ ] Build historical graphs for bandwidth usage trends

---

## 🔍 Testing & Verification

### Verify WAN Detection
```bash
# On firewall
tail -f /var/log/opnsense_agent.log

# Expected output:
# 2026-01-08 12:00:00 - Detected WAN interface(s): igc0
# 2026-01-08 12:00:01 - Starting agent checkin with WAN interface monitoring...
# 2026-01-08 12:00:02 - Checkin data prepared with WAN interfaces: igc0
```

### Verify Database Updates
```sql
-- Check basic WAN info
SELECT id, hostname, wan_interfaces, wan_groups
FROM firewalls
WHERE id = 21;

-- Check detailed interface stats
SELECT * FROM firewall_wan_interfaces
WHERE firewall_id = 21;

-- Use the view
SELECT * FROM v_firewall_wan_status
WHERE firewall_id = 21;
```

### Check for Errors
```bash
# On management server
tail -100 /var/log/apache2/error.log | grep -i wan
tail -100 /var/log/mysql/error.log | grep -i wan
```

---

## 📊 Example Use Cases

### 1. Multi-WAN Monitoring
Monitor bandwidth distribution across multiple WAN connections:
```sql
SELECT
    interface_name,
    status,
    ROUND(rx_bytes / 1024 / 1024 / 1024, 2) AS rx_gb,
    ROUND(tx_bytes / 1024 / 1024 / 1024, 2) AS tx_gb
FROM firewall_wan_interfaces
WHERE firewall_id = 21
ORDER BY interface_name;
```

### 2. Interface Health Check
Identify interfaces with errors:
```sql
SELECT
    f.hostname,
    w.interface_name,
    w.status,
    w.rx_errors + w.tx_errors AS total_errors
FROM firewalls f
JOIN firewall_wan_interfaces w ON f.id = w.firewall_id
WHERE w.rx_errors + w.tx_errors > 0
ORDER BY total_errors DESC;
```

### 3. Failover Status
Check WAN interface availability for failover groups:
```sql
SELECT
    hostname,
    wan_groups,
    COUNT(w.id) AS total_interfaces,
    SUM(CASE WHEN w.status = 'up' THEN 1 ELSE 0 END) AS active_interfaces
FROM firewalls f
JOIN firewall_wan_interfaces w ON f.id = w.firewall_id
WHERE wan_groups LIKE '%FAILOVER%'
GROUP BY f.id;
```

---

## 🐛 Known Issues & Limitations

### Current Limitations
1. **Config File Access**: Requires read access to `/conf/config.xml` (standard on OPNsense)
2. **Python Dependency**: Proxy request processing requires Python 3 (installed by default)
3. **Interface Names**: Must match OPNsense naming convention (igc, igb, em, etc.)

### Compatibility
- ✅ OPNsense: 21.x, 22.x, 23.x, 24.x, 25.x
- ✅ FreeBSD: 12.x, 13.x, 14.x
- ✅ Backward Compatible: Can coexist with older agent versions

---

## 🚀 Future Enhancements (v3.5.0 Planned)

- Gateway latency monitoring (ping response times)
- Packet loss detection per WAN interface
- Real-time bandwidth rate calculation (Mbps)
- Historical bandwidth graphs and trending
- Interface error threshold alerting
- VLAN interface detection and monitoring
- PPPoE/PPtP connection status tracking
- IPv6 gateway monitoring
- BGP peer status (for advanced setups)

---

## 📚 Documentation

### New Documentation Files
- `AGENT_V3.4.0_CHANGELOG.md` - Detailed technical changelog
- `WAN_AUTO_DETECTION_SUMMARY.md` - Implementation guide and summary
- `agent_checkin_v3.4.0_update.php` - Backend integration code samples
- `database/migrate_v3.4.0.sql` - Database migration script

### Updated Files
- `download_tunnel_agent.php` - Now serves v3.4.0 by default
- `agent_checkin.php` - Updated agent version checks

---

## 👥 Credits

**Release Manager**: OPNsense Management Platform Team
**Development**: Automated Infrastructure Team
**Testing**: Production Environment Validation
**Release Date**: January 8, 2026

---

## 📞 Support

For issues or questions regarding this release:
1. Check agent logs: `/var/log/opnsense_agent.log`
2. Verify database migration completed successfully
3. Review PHP error logs for backend issues
4. Test agent manually: `sh /tmp/agent_v3.4.0.sh`
5. Consult documentation in `WAN_AUTO_DETECTION_SUMMARY.md`

---

**Version**: 3.4.0
**Status**: ✅ RELEASED
**Deployment**: Ready for Production
**Agent Size**: 18.9 KB
**Database Changes**: Required (see migration script)
