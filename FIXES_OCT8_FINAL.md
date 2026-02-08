# ✅ FIXES - October 8, 2025 (CORRECTED)

## Issues Fixed This Round

### 1. Settings.php - Proxy Settings Removed ✅
**Issue:** I accidentally removed BACKUP RETENTION instead of PROXY SETTINGS
**Fix:** Restored backup and correctly removed:
- Proxy Settings card (lines 258-273)
- Proxy modal (lines 382-430)

**Result:** 
- ✅ Backup Retention card is PRESENT and working
- ✅ Proxy Settings card is REMOVED

**File:** `/var/www/opnsense/settings.php`
**Verify with:** `grep -n "Backup Retention\|Proxy Settings" /var/www/opnsense/settings.php`
Should only show Backup Retention lines.

---

### 2. Firewall Edit - Status Field Fixed ✅
**Issue:** Status shows "unknown" instead of actual status
**Root Cause:** Query selected `a.status` but code looked for `agent_status`
**Fix:** Changed query to `a.status as agent_status`

**Line changed:**
```sql
SELECT f.*, a.last_checkin, a.agent_version, a.status as agent_status, a.wan_ip, a.ipv6_address
```

**Result:** Status now shows correctly ("Online", "Offline", etc.)

**File:** `/var/www/opnsense/firewall_edit.php`

---

### 3. Firewall Edit - Customer Group Dropdown ✅
**Status:** Already implemented in previous round
- Dropdown populates with existing customer groups from database
- Currently shows "SMB" as option
- Queries: `SELECT DISTINCT customer_group FROM firewalls...`

---

### 4. Firewall Edit - Tags Multi-Select ✅  
**Status:** Already implemented in previous round
- Multi-select dropdown with colored tag bullets
- Available tags: Agit8or.net (red), Dewey Home (blue)
- JavaScript syncs selections to hidden field

---

### 5. Duplicate Logs - Still Occurring ⚠️
**Status:** 9 agent processes running, 5 duplicates in last 5 minutes

**Investigation:**
- Command #784 (flock) didn't prevent respawning
- Command #786 showed 9 concurrent processes
- Agents keep spawning new instances

**New Fix (Command #787):**
Created PID wrapper script `/usr/local/bin/agent_single.sh` that:
1. Checks if PID file exists and process is running
2. Exits if agent already running
3. Creates PID file and removes on exit
4. Wraps the actual agent script

This is a MORE PERMANENT solution than flock.

**Waiting for:** Command #787 to execute

---

### 6. Firewall Details Page Breaking Dropdowns ⚠️
**Issue:** When viewing firewall_details.php, Administration and Development dropdowns don't work
**Investigation:**
- Page structure is balanced (54 opening divs, 54 closing divs)
- Scripts look normal
- Footer is included
- Already added z-index: 9999 to .dropdown-menu in header.php

**Possible causes:**
1. DOMContentLoaded event listener might be interfering
2. Page JavaScript loading order issue
3. Bootstrap not fully initialized when page loads

**Next steps:** Need to test if moving the backup JavaScript to bottom helps, or adding a delay to loadBackups()

---

## Summary

### ✅ DEFINITELY FIXED:
1. Proxy Settings removed (Backup Retention kept) ✅
2. Status field shows correctly in firewall edit ✅
3. Customer group dropdown present ✅
4. Tags multi-select present ✅

### ⚠️ IN PROGRESS:
1. Duplicate logs - Command #787 queued with PID wrapper
2. Firewall details breaking dropdowns - Need more investigation

---

## Testing Instructions

### Test Settings Page:
```bash
# Should see Backup Retention, NOT Proxy Settings
grep "Backup Retention\|Proxy Settings" /var/www/opnsense/settings.php
```

### Test Firewall Edit:
1. Go to Firewall → Edit
2. **Status:** Should show "Online" not "unknown"
3. **Customer Group:** Should show dropdown with "SMB" option
4. **Tags:** Should show multi-select with colored bullets

### Check Duplicates (after ~2 minutes):
```bash
php -r "require '/var/www/opnsense/inc/db.php'; \$stmt = \$DB->query('SELECT COUNT(*) FROM system_logs WHERE timestamp > DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND message LIKE \"%DUPLICATE BLOCKED%\"'); echo \$stmt->fetchColumn() . \" duplicates\n\";"
```

Should be 0 after command #787 executes.

---

## Files Modified

1. `/var/www/opnsense/settings.php` - Removed proxy, kept backup retention
2. `/var/www/opnsense/firewall_edit.php` - Fixed status query alias
3. Firewall #21: `/usr/local/bin/agent_single.sh` - PID wrapper (via command #787)

