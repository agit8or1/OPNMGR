# Critical Issues Summary
**Date**: October 15, 2025

## ✅ COMPLETED FIXES

### 1. Connection Error Fixed
**Problem**: `https://opn.agit8or.net:8104` refused to connect

**Root Cause**: Old SSH tunnels were still forwarding to localhost:443, but firewall web server is on port 80

**Solution**:
- Killed all old tunnels (PIDs 288013, 302840)
- Updated `manage_ssh_tunnel.php` to use `$firewall['web_port']` dynamically
- Protocol now auto-selects (http for port 80, https for port 443)

**Action Required**: Click "Connect Now" again to establish new tunnel with correct port

---

### 2. Terminology Updated
**Changed**: "On-Demand Reverse Tunnel" → "On-Demand SSH Tunnel"

**Why**: The tunnel is a forward tunnel (SSH -L), not a reverse tunnel (-R)

**Files Updated**:
- `firewall_details.php`: Connection type and description
- Description now says "auto-expiring firewall rules" instead of "no exposed ports"

---

### 3. Traffic Graph Embedded
**Removed**: Separate "Traffic Graph" button that opened full page

**Added**: Embedded "System Statistics" section in firewall details with 4 graphs:
1. **WAN Traffic** (7 days) - Inbound/Outbound in MB
2. **CPU Usage** - Placeholder (data collection not yet implemented)
3. **Memory Usage** - Placeholder (data collection not yet implemented)
4. **Disk Usage** - Placeholder (data collection not yet implemented)

**Location**: After Configuration Backups section

**Features**:
- Compact, responsive design
- Uses Chart.js for visualization
- Traffic graph pulls from `firewall_traffic_stats` table
- API endpoint: `/api/get_traffic_stats.php`

---

## ⚠️ CRITICAL ISSUE: Agent Not Checking In

### Problem
- Last checkin: 2025-10-15 14:00:00 (over 30 minutes ago)
- All queued commands timing out
- Agent process status unknown

### Symptoms
```
Command queue: TIMEOUT
Agent version: 3.4.9 (not upgrading to v3.5.0)
Traffic stats: 0 records (not collecting)
```

### Possible Causes
1. **Cron job not running** - Main agent scheduled every 2 minutes might be disabled
2. **Agent script crashed** - Process may have died and not restarted
3. **Network issue** - Firewall can't reach management server
4. **Permission issue** - Agent script not executable

### Diagnostic Steps
```bash
# On firewall (via SSH or console):
1. Check if agent is installed:
   ls -l /usr/local/bin/opnsense_agent_v2.sh

2. Check if agent is executable:
   file /usr/local/bin/opnsense_agent_v2.sh

3. Check cron jobs:
   crontab -l | grep opnsense

4. Test agent manually:
   /usr/local/bin/opnsense_agent_v2.sh

5. Check for errors:
   tail -50 /var/log/opnsense_agent.log

6. Check network connectivity:
   curl -k https://opn.agit8or.net/agent_checkin.php
```

### Quick Fix Options

**Option 1: Manual Trigger via SSH**
```bash
ssh root@73.35.46.112
/usr/local/bin/opnsense_agent_v2.sh
```

**Option 2: Reinstall Agent via Web UI**
- Use existing tunnel session (if one establishes)
- Navigate to System → Cron
- Check for agent entries

**Option 3: Add to Cron (if missing)**
```bash
# Main agent every 2 minutes:
*/2 * * * * /usr/local/bin/opnsense_agent_v2.sh >> /var/log/opnsense_agent.log 2>&1
```

---

## 📊 System Statistics Implementation Status

| Feature | Status | Notes |
|---------|--------|-------|
| WAN Traffic | ⏳ Partial | Table created, agent v3.5.0 ready, but not deployed |
| CPU Usage | ❌ Not Started | Needs agent collection code |
| Memory Usage | ❌ Not Started | Needs agent collection code |
| Disk Usage | ❌ Not Started | Needs agent collection code |

### To Complete System Stats

1. **Deploy Agent v3.5.0** (once agent is responsive):
   ```bash
   cd /var/www/opnsense && php scripts/upgrade_to_v350.php
   ```

2. **Add CPU/Memory/Disk Collection** to agent v3.6.0:
   ```bash
   # FreeBSD commands to collect:
   CPU: top -d 2 -n | grep "CPU:" | awk '{print $2}'
   Memory: top -d 1 -n | grep "Mem:" | awk '{print $2}'
   Disk: df -h / | tail -1 | awk '{print $5}'
   ```

3. **Create Database Table**:
   ```sql
   CREATE TABLE firewall_system_stats (
       id BIGINT AUTO_INCREMENT PRIMARY KEY,
       firewall_id INT NOT NULL,
       recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
       cpu_usage DECIMAL(5,2),
       memory_usage DECIMAL(5,2),
       disk_usage DECIMAL(5,2),
       INDEX idx_firewall_time (firewall_id, recorded_at),
       FOREIGN KEY (firewall_id) REFERENCES firewalls(id) ON DELETE CASCADE
   );
   ```

4. **Update `agent_checkin.php`** to parse and store system stats

5. **Create API endpoint** `/api/get_system_stats.php`

6. **Update Chart.js** code to fetch real data instead of placeholders

---

## 🔧 Current State

### Working
- ✅ Web port auto-detection (port 80 detected correctly)
- ✅ SSH tunnel code (uses correct port)
- ✅ Terminology updated
- ✅ Graphs embedded in details page
- ✅ Traffic stats database table created
- ✅ Traffic stats API endpoint created

### Blocked
- ⏸️ Connection testing (old tunnels killed, need new one)
- ⏸️ Traffic data collection (agent not checking in)
- ⏸️ Agent v3.5.0 deployment (agent not responsive)
- ⏸️ System stats (not yet implemented in agent)

### Immediate Actions Needed
1. **Investigate why agent stopped checking in** (CRITICAL)
2. **Test new tunnel connection** after agent is responsive
3. **Deploy agent v3.5.0** once agent issue resolved
4. **Verify traffic stats collection** starts working

---

## 📝 Files Modified This Session

1. `/var/www/opnsense/firewall_details.php`:
   - Removed "Traffic Graph" button
   - Added embedded System Statistics section with 4 graphs
   - Updated terminology (Reverse Tunnel → SSH Tunnel)
   - Added Chart.js library and initialization code

2. `/var/www/opnsense/api/get_traffic_stats.php`:
   - New API endpoint for traffic data
   - Returns hourly aggregated data for selected time period

3. `/var/www/opnsense/scripts/manage_ssh_tunnel.php`:
   - Uses dynamic `$firewall['web_port']` instead of hardcoded 443
   - Auto-selects http/https protocol

4. `/var/www/opnsense/firewall_proxy_ondemand.php`:
   - Uses `HTTP_HOST` for correct tunnel URL

---

## Next Session TODO

1. ✅ Debug agent checkin failure
2. ⏳ Implement CPU/Memory/Disk collection in agent
3. ⏳ Create firewall_system_stats table
4. ⏳ Update agent_checkin.php for system stats
5. ⏳ Create get_system_stats.php API
6. ⏳ Update Chart.js to use real system data
7. ⏳ Test complete end-to-end flow

---

**Priority**: Fix agent checkin issue before proceeding with any other features.
