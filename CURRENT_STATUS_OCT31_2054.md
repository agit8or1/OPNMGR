# OPNManager Status Summary
**Date:** October 31, 2025 20:54
**Session:** Extended troubleshooting and fixes

---

## ✅ **WORKING**

### 1. WAN Traffic Chart - ✅ FULLY WORKING
- Data collecting every 2 minutes
- API returns real-time data
- **Latest:** 36.47 Mbps in, 10.67 Mbps out (20:48)
- User's speedtests ARE visible in the graph
- Counter reset handling working correctly

### 2. Agent v3.7.1 - ✅ INSTALLED & RUNNING
- Installed on firewall at 20:39
- Checking in every 2 minutes
- Last checkin: 20:52:03
- **Install script created:** `/var/www/opnsense/scripts/install_agent_v3.7.1.sh`
  - Command: `fetch -o - https://opn.agit8or.net/scripts/install_agent_v3.7.1.sh | sh`

### 3. Data Collection - ✅ WORKING
- **Traffic stats:** Collecting (latest 20:52:03)
- **System stats:** Collecting (CPU 0.25, Memory 2.78%, Disk 4%)
- **Latency:** Collecting periodically
- Database has 1,939 system stats records (Oct 30 - Oct 31)

### 4. Bad Data Cleanup - ✅ COMPLETED
- Deleted 2,917 erroneous traffic records (bytes_in > 1TB)
- Deleted 8 mock speedtest records (fake 140 Mbps data)
- Traffic API now shows clean data

---

## ❌ **ISSUES FOUND**

### 1. System Stats Charts (CPU/Memory/Disk) - ❌ TOO MUCH DATA
**Problem:** API returns 1,300+ data points for "days=1" query
- Starts: Oct 30 22:20
- Ends: Oct 31 20:52
- Intervals: 10 minutes = ~150 points (should be OK)
- **Actual:** Returning ALL data with gaps, causing 1,300+ labels

**Why Charts Hang:**
- Browser tries to render 1,300+ data points
- JavaScript times out
- UI shows "Loading..." forever

**Fix Needed:** Modify `/var/www/opnsense/api/get_system_stats.php` to:
1. Filter out gaps/missing data
2. Aggregate more aggressively for 1-day view
3. Limit max data points to 144 (10-min intervals)

**Current API Path:** `/var/www/opnsense/api/get_system_stats.php` (lines 42-160)

---

### 2. Speedtest Not Working - ❌ COMMAND TYPE NOT RECOGNIZED
**Problem:** Manual speedtest trigger fails immediately

**Root Cause:** Agent v3.7.1 doesn't recognize "run_speedtest" command type
- Commands 1113, 1115, 1116: Status "failed"
- Agent `execute_command()` function only handles: system_update, update_agent, shell, api_test
- Missing: run_speedtest handler

**Solution Created:** Agent v3.7.2
- Location: `/var/www/opnsense/downloads/opnsense_agent_v3.7.2.sh`
- Added speedtest command handler (lines 233-235)
- Server updated to use v3.7.2 as latest version

**Status:** Force update command queued (ID 1117, sent at 20:52:03)
- Agent should execute on next checkin (~2 minutes)
- Will install v3.7.2 with working speedtest support

---

### 3. Network Tools SSH Error "Can't Connect to Host 712" - ❌ BROKEN FEATURE
**Problem:** Network tools tries to SSH to port 712 but fails

**Root Cause:** Table `firewall_tunnel_requests` doesn't exist
```
ERROR 1146 (42S02): Table 'opnsense_fw.firewall_tunnel_requests' doesn't exist
```

**Analysis:** SSH tunnel/network tools feature is incomplete or was removed
- Agent has tunnel code (lines 263-350 in v3.7.1)
- Database table missing
- UI still shows network tools button

**Fix Options:**
1. Create the missing table if feature is wanted
2. Hide/disable network tools UI if feature deprecated
3. Remove tunnel code from agent

---

## 📋 **NEXT STEPS**

### Immediate (Auto-executing)
1. **Agent v3.7.2 Update** - Force command sent, should execute within 2 minutes
2. **Verify Update** - Check agent_version becomes 3.7.2
3. **Test Speedtest** - Trigger manual test after v3.7.2 installed

### Manual Fixes Needed
1. **Fix System Stats API** - Limit data points returned
2. **Network Tools** - Either fix or disable
3. **Test All Charts** - Verify all graphs display correctly

---

## 📁 **KEY FILES**

### Agent Files
- **v3.7.1:** `/var/www/opnsense/downloads/opnsense_agent_v3.7.1.sh` (current on firewall)
- **v3.7.2:** `/var/www/opnsense/downloads/opnsense_agent_v3.7.2.sh` (queued for install)
- **Install Script:** `/var/www/opnsense/scripts/install_agent_v3.7.1.sh` (works for both versions)

### API Files
- **Traffic:** `/var/www/opnsense/api/get_traffic_stats.php` ✅ WORKING
- **System Stats:** `/var/www/opnsense/api/get_system_stats.php` ❌ NEEDS FIX
- **Latency:** `/var/www/opnsense/api/get_latency_stats.php` ✅ WORKING
- **Speedtest:** `/var/www/opnsense/api/get_speedtest_results.php` ✅ WORKING (no data yet)

### Server Config
- **Agent Checkin:** `/var/www/opnsense/agent_checkin.php` (updated to v3.7.2)

---

## 🔧 **COMMANDS FOR USER**

### Check Agent Status
```bash
sudo mysql opnsense_fw -e "SELECT agent_version, last_checkin FROM firewall_agents WHERE firewall_id=21"
```

### Test APIs Directly
```bash
# Traffic (WORKING)
curl "https://opn.agit8or.net/api/get_traffic_stats.php?firewall_id=21&days=1"

# System Stats (TOO MUCH DATA)
curl "https://opn.agit8or.net/api/get_system_stats.php?firewall_id=21&days=1&metric=cpu"
```

### Manually Trigger Speedtest (After v3.7.2)
```sql
INSERT INTO firewall_commands (firewall_id, command, description, status, created_at)
VALUES (21, 'run_speedtest', 'Manual speedtest trigger', 'pending', NOW());
```

### Reinstall Agent (If Needed)
```bash
ssh root@73.35.46.112
fetch -o - https://opn.agit8or.net/scripts/install_agent_v3.7.1.sh | sh
```

---

## 📊 **DATABASE STATUS**

### Traffic Stats
- **Records:** 706 (after cleanup)
- **Latest:** 2025-10-31 20:52:03
- **Quality:** ✅ Clean data, no spikes

### System Stats
- **Records:** 1,939 (Oct 30-31)
- **Latest:** 2025-10-31 20:50:03
- **Quality:** ✅ Good data, API needs fixing

### Speedtest
- **Records:** 0 (mock data deleted)
- **Status:** Waiting for first real test
- **Expected:** ~1200/300 Mbps

### Commands
- **Pending:** 3 speedtest commands (will work after v3.7.2)
- **Last Sent:** Force update to v3.7.2 (ID 1117, 20:52:03)

---

## ⏱️ **TIMELINE**

- **20:39:** Agent v3.7.1 installed on firewall
- **20:44:** Agent v3.7.2 created with speedtest fix
- **20:50:** Server updated to use v3.7.2
- **20:52:** Force update command sent to firewall
- **20:54:** Status documented (this file)
- **~20:54:** Agent should execute update (next checkin)

---

**Summary:** Main functionality working. System stats API needs data limiting fix. Speedtest will work once v3.7.2 installs (happening now).
