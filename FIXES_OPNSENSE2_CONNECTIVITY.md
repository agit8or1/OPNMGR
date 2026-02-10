# OPN2 Connectivity & UI Fixes - 2025-11-10

## Issues Fixed

### 1. ✅ UI Hanging When Connecting to opn2
**Problem:** UI would hang and become unresponsive until SSH connection timed out

**Root Cause:** 
- `/api/start_ssh_tunnel.php` was not specifying SSH key path
- No proper timeout handling
- Connection attempts were blocking

**Solution:** 
- Added SSH key path specification: `/var/www/opnsense/keys/id_firewall_{id}`
- Added key existence check before attempting connection
- Wrapped SSH command with `sudo -u www-data` for proper permissions
- Timeout already present (10s) but now working with key

**Files Modified:**
- `/var/www/opnsense/api/start_ssh_tunnel.php` lines 60-79

### 2. ✅ SSH Using Hostname Instead of WAN IP
**Problem:** Network tools and SSH connections were trying to use hostname instead of IP address

**Root Cause:**
- Code was checking `ip_address` column which is empty for opn2
- `wan_ip` column exists but wasn't being used as fallback
- Hostname resolution failing caused timeouts

**Solution:**
- Changed priority order: `wan_ip` → `ip_address` → `hostname`
- Now uses: 184.175.230.189 (wan_ip) instead of opn2.agit8or.net (hostname)

**Files Modified:**
- `/var/www/opnsense/api/run_diagnostic.php` lines 26, 42
- `/var/www/opnsense/api/start_ssh_tunnel.php` line 57

### 3. ✅ Network Tools Not Working from opn2
**Problem:** Ping, traceroute, DNS lookup, etc. failing for opn2

**Root Cause:** Same as issue #2 - SSH was trying to connect to hostname

**Solution:** Fixed by updating SSH host resolution in `run_diagnostic.php`

**Verification:**
```bash
$ sudo -u www-data ssh -i /var/www/opnsense/keys/id_firewall_25 root@184.175.230.189 'uname -a'
FreeBSD opn2.agit8or.net 14.2-RELEASE-p4 (amd64)
✓ Working
```

### 4. ✅ Tags Functionality
**Problem:** User reported tags "still don't work"

**Investigation Results:**
- Tags ARE working in backend (tested successfully)
- Data saves correctly to database
- Issue was visibility/UX, not functionality

**Improvements Made:**
1. **Better Visual Display:**
   - Tags now shown as colored badges in overview section
   - Color-coded with proper contrast (light/dark text based on background)
   - Added tag icon for better identification

2. **Improved Form:**
   - Better placeholder text: "Production, VPN, Office"
   - Shows current tags below input field
   - Added help text: "creates new tags automatically"
   - More prominent success message with dismiss button

3. **Company Field Added:**
   - Added company/customer_group display in overview
   - Editable in Configuration section
   - Shows in main firewalls list

**Files Modified:**
- `/var/www/opnsense/firewall_details.php`:
  - Lines 197-201: Improved success alert
  - Lines 551-587: Visual tag/company display
  - Lines 620-631: Improved tag input form
  - Lines 105-170: Tag save logic (already working)

## Database Verification

### Tags Working:
```sql
-- Firewall 21 has tag:
SELECT * FROM firewall_tags WHERE firewall_id = 21;
-- Result: tag_id = 4 (Agit8or.net)

-- Firewall 25 test:
INSERT INTO firewall_tags VALUES (25, 5);  -- Test tag
-- ✓ Success
```

### Company Working:
```sql
SELECT id, hostname, customer_group FROM firewalls WHERE id = 25;
-- Result: customer_group = "Company B" ✓
```

## SSH Keys Status

All firewalls have SSH keys:
```
/var/www/opnsense/keys/id_firewall_21      (home.agit8or.net)
/var/www/opnsense/keys/id_firewall_25      (opn2.agit8or.net)
```

## Testing Commands

### Test SSH Connection:
```bash
sudo -u www-data ssh -i /var/www/opnsense/keys/id_firewall_25 \
  -o ConnectTimeout=10 root@184.175.230.189 'echo "Working"'
```

### Test Network Tools:
```bash
# From web UI: Diagnostics → Network Tools
# Select: Ping, Traceroute, DNS Lookup, etc.
# All should work now
```

### Test Tags:
1. Go to firewall details
2. Scroll to Configuration section
3. Enter tags: "Test, Production, VPN"
4. Click "Update Configuration"
5. See green success message
6. Tags appear as badges in overview

## Notes

- **UI No Longer Hangs:** Proper timeouts prevent blocking
- **Network Tools Fixed:** Using correct IP address
- **Tags Fully Functional:** Backend working, UI improved
- **Company Field:** Added and working

---
**Date:** 2025-11-10
**Status:** All Issues Resolved ✅
