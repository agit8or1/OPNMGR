# OPNsense Agent - WAN Interface Auto-Detection Summary

## What Was Done

I've successfully enhanced your OPNsense management agent to **automatically detect and monitor WAN interfaces and WAN groups**. The agent now intelligently identifies which network interfaces are configured as WAN in your OPNsense firewall and monitors their status and performance.

## Key Features Added

### 1. Automatic WAN Interface Detection
- **Reads `/conf/config.xml`** to identify WAN interfaces
- **Supports multiple WAN interfaces**: WAN, WAN2, WAN3, WAN_DHCP, WAN_PPPoE, etc.
- **No manual configuration needed** - fully automatic

### 2. WAN Gateway Group Detection
- Detects **multi-WAN setups** with load balancing or failover
- Identifies **gateway groups** configured for policy-based routing

### 3. Comprehensive Interface Monitoring
Each WAN interface is monitored for:
- **Status**: up, down, no_carrier
- **IP Address**: Currently assigned public IP
- **Media Type**: Interface speed (e.g., "1000baseT full-duplex")
- **Bandwidth Counters**: RX/TX bytes
- **Packet Counters**: RX/TX packets
- **Error Counters**: RX/TX errors

## Files Created

### Agent Script
- **`opnsense/scripts/opnsense_agent_v3.4.0.sh`** (18.9 KB)
  - Enhanced agent with WAN detection and monitoring
  - Location: `/home/administrator/opnsense/scripts/opnsense_agent_v3.4.0.sh`

### Deployment Files
- **`opnsense/deploy_agent_v3.4.0.sh`**
  - Automated deployment script
  - Copies agent to download directories

- **`opnsense/download/opnsense_agent_v3.4.0.sh`** ✓ Deployed
- **`opnsense/downloads/opnsense_agent_v3.4.0.sh`** ✓ Deployed

### Database Migration
- **`opnsense/database/migrate_v3.4.0.sql`**
  - SQL migration to add WAN interface columns
  - Creates `firewall_wan_interfaces` table
  - Creates view `v_firewall_wan_status`

### Documentation
- **`opnsense/AGENT_V3.4.0_CHANGELOG.md`**
  - Comprehensive changelog with technical details
  - Sample payloads and implementation guide

- **`opnsense/agent_checkin_v3.4.0_update.php`**
  - Code snippets to update `agent_checkin.php`
  - Includes function to process WAN interface stats

## How It Works

### Step 1: Detection (at agent startup)
```bash
# Agent reads /conf/config.xml
<interfaces>
  <wan>
    <if>igc0</if>  ← Detected as WAN interface
  </wan>
</interfaces>
```

### Step 2: Monitoring (every check-in)
```bash
# Agent runs: netstat -ibn | grep igc0
# Collects: packets, bytes, errors
# Agent runs: ifconfig igc0
# Collects: status, IP, media type
```

### Step 3: Reporting (sent to management server)
```json
{
  "wan_interfaces": "igc0",
  "wan_groups": "WAN_FAILOVER",
  "wan_interface_stats": [
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

## Installation Instructions

### Option 1: Quick Install on a Firewall
```bash
# Download and run the new agent
fetch -o /tmp/agent_v3.4.0.sh https://opn.agit8or.net/download/opnsense_agent_v3.4.0.sh
chmod +x /tmp/agent_v3.4.0.sh
/tmp/agent_v3.4.0.sh

# Check the log to verify WAN detection
tail -20 /var/log/opnsense_agent.log
# Should see: "Detected WAN interface(s): igc0"
```

### Option 2: Update Cron Job
```bash
# Edit root crontab
crontab -e

# Change from v3.3.0 to v3.4.0
*/2 * * * * /usr/local/bin/opnsense_agent_v3.4.0.sh
```

## Backend Setup (Required!)

### 1. Run Database Migration
```bash
mysql -u opnsense_user -p opnsense_db < database/migrate_v3.4.0.sql
```

This adds:
- `wan_interfaces` column to `firewalls` table
- `wan_groups` column to `firewalls` table
- `wan_interface_stats` JSON column to `firewalls` table
- `firewall_wan_interfaces` table for detailed tracking
- `v_firewall_wan_status` view for easy queries

### 2. Update agent_checkin.php
Open `agent_checkin.php` and add the code from:
```
opnsense/agent_checkin_v3.4.0_update.php
```

Key changes:
- Extract `wan_interfaces`, `wan_groups`, `wan_interface_stats` from input
- Update the main UPDATE query to include new fields
- Add `processWANInterfaceStats()` function
- Call the function after successful checkin

### 3. Update Firewall Details Page (Optional)
Add a section to `firewall_details.php` to display WAN interface statistics:

```php
// Fetch WAN interface stats
$wan_stats_query = $DB->prepare('
    SELECT * FROM firewall_wan_interfaces
    WHERE firewall_id = ?
    ORDER BY interface_name
');
$wan_stats_query->execute([$firewall_id]);
$wan_interfaces = $wan_stats_query->fetchAll(PDO::FETCH_ASSOC);

// Display in UI
foreach ($wan_interfaces as $iface) {
    echo "<tr>";
    echo "<td>{$iface['interface_name']}</td>";
    echo "<td>{$iface['status']}</td>";
    echo "<td>{$iface['ip_address']}</td>";
    echo "<td>" . formatBytes($iface['rx_bytes']) . "</td>";
    echo "<td>" . formatBytes($iface['tx_bytes']) . "</td>";
    echo "</tr>";
}
```

## Testing Checklist

- [ ] Run database migration
- [ ] Update agent_checkin.php with new code
- [ ] Deploy agent v3.4.0 to a test firewall
- [ ] Verify WAN interfaces detected in log: `tail -f /var/log/opnsense_agent.log`
- [ ] Check database for new data: `SELECT wan_interfaces, wan_groups FROM firewalls WHERE id = 21;`
- [ ] Verify interface stats table: `SELECT * FROM firewall_wan_interfaces WHERE firewall_id = 21;`
- [ ] View aggregated data: `SELECT * FROM v_firewall_wan_status WHERE firewall_id = 21;`
- [ ] Update firewall details page to display stats

## Benefits

1. **No Manual Configuration**: Agent automatically detects WAN interfaces
2. **Multi-WAN Support**: Works with load balancing and failover setups
3. **Real-Time Monitoring**: Track interface status, bandwidth, and errors
4. **Historical Data**: Database stores statistics over time
5. **Failure Detection**: Identify when WAN interfaces go down or have errors
6. **Bandwidth Tracking**: Monitor data usage per WAN interface

## Example Queries

### View all WAN interfaces for a firewall
```sql
SELECT * FROM firewall_wan_interfaces WHERE firewall_id = 21;
```

### Check WAN interface status across all firewalls
```sql
SELECT
    f.hostname,
    w.interface_name,
    w.status,
    w.rx_errors + w.tx_errors AS total_errors
FROM firewalls f
JOIN firewall_wan_interfaces w ON f.id = w.firewall_id
WHERE w.status != 'up';
```

### Calculate total bandwidth usage per firewall
```sql
SELECT
    f.hostname,
    SUM(w.rx_bytes + w.tx_bytes) / 1024 / 1024 / 1024 AS total_gb
FROM firewalls f
JOIN firewall_wan_interfaces w ON f.id = w.firewall_id
GROUP BY f.id, f.hostname;
```

## Troubleshooting

### Agent not detecting WAN interfaces
- Check if `/conf/config.xml` exists: `ls -l /conf/config.xml`
- Verify WAN is configured: `grep -A 10 "<wan>" /conf/config.xml`
- Check agent log: `tail -100 /var/log/opnsense_agent.log | grep -i wan`

### No stats appearing in database
- Verify database migration ran successfully
- Check agent_checkin.php has been updated
- Look for PHP errors: `tail -100 /var/www/opnsense/logs/error.log`
- Verify JSON payload: Check raw POST data in agent_checkin.php

### Interface stats showing zeros
- Verify interface name is correct: `ifconfig`
- Check netstat output: `netstat -ibn`
- Ensure interface has traffic: `ifconfig igc0` (look for RX/TX counters)

## Next Steps

1. **Deploy to Production**: Roll out v3.4.0 to all managed firewalls
2. **Build Dashboard**: Create UI to visualize WAN interface health
3. **Set Up Alerts**: Notify when WAN interfaces go down or have high error rates
4. **Historical Graphs**: Plot bandwidth usage over time
5. **Performance Baselines**: Establish normal traffic patterns per WAN

## File Locations Summary

```
opnsense/
├── scripts/
│   └── opnsense_agent_v3.4.0.sh          ← Main agent script
├── download/
│   └── opnsense_agent_v3.4.0.sh          ← Deployed (public URL)
├── downloads/
│   └── opnsense_agent_v3.4.0.sh          ← Deployed (public URL)
├── database/
│   └── migrate_v3.4.0.sql                ← Database migration
├── AGENT_V3.4.0_CHANGELOG.md             ← Detailed changelog
├── agent_checkin_v3.4.0_update.php       ← Backend update snippets
├── deploy_agent_v3.4.0.sh                ← Deployment script
└── WAN_AUTO_DETECTION_SUMMARY.md         ← This file
```

## Support

For questions or issues:
- Review agent log: `/var/log/opnsense_agent.log`
- Check PHP error log for backend issues
- Verify database migration completed successfully
- Test agent manually: `sh /tmp/agent_v3.4.0.sh`

---

**Version**: 3.4.0
**Created**: 2026-01-08
**Status**: Ready for deployment ✓
