# OPNsense Update Detection Implementation - Agent v3.8.2

## Overview
Fixed OPNsense update checking logic by implementing automatic firmware update detection in the agent and removing placeholder/random demo code.

## Problem Identified
The `/api/check_updates.php` was using **hardcoded demo data** and **random results** instead of actually checking OPNsense firewalls for updates:

```php
// OLD CODE (lines 44-47):
$current_version = "24.7.1";
$available_version = "24.7.2";
$updates_available = rand(0, 1) == 1; // Random for demo
```

## Solution Implemented

### 1. Created Agent v3.8.2 with OPNsense Update Detection

**New Function: `check_opnsense_updates()`**
- Queries OPNsense API endpoint: `https://localhost/api/core/firmware/status`
- Parses JSON response for:
  - `updates_available` (boolean)
  - `new_version` (string)
  - `needs_reboot` (boolean)
- Fallback to `opnsense-update -c` command if API unavailable
- Returns JSON object with update status

**Integration:**
- Called automatically during every agent check-in
- Included in check-in payload as `opnsense_updates` field
- Data sent to management server every 2 minutes

### 2. Updated `agent_checkin.php`

**Added Processing (lines 269-297):**
```php
if (isset($input['opnsense_updates'])) {
    $updates = $input['opnsense_updates'];
    // Parse and store in database:
    // - updates_available
    // - available_version
    // - reboot_required
    // - last_update_check
}
```

### 3. Fixed `/api/check_updates.php`

**Replaced Demo Code with Real Data:**
- Now reads actual update status from database (populated by agent)
- Returns current version, available version, update availability
- Queues update check command if data is stale (>1 hour old)
- No more random/hardcoded values

### 4. Updated All Version References

**Files Modified:**
- `/var/www/opnsense/downloads/opnsense_agent_v3.8.2.sh` - New agent
- `/var/www/opnsense/download_tunnel_agent.php` - Serves v3.8.2
- `/var/www/opnsense/agent_checkin.php` - Latest version = 3.8.2
- `/var/www/opnsense/api/repair_agent_ssh.php` - References v3.8.2
- `/var/www/opnsense/scripts/fix_agent_web_ui.php` - Target version 3.8.2

## How It Works

### Automatic Detection Flow:

1. **Agent Check-in (Every 2 minutes):**
   - Agent calls `check_opnsense_updates()`
   - Queries OPNsense firmware API
   - Includes results in check-in payload

2. **Server Processing:**
   - `agent_checkin.php` receives update data
   - Updates `firewalls` table:
     - `updates_available` = 1 or 0
     - `available_version` = "25.1.1" (or null)
     - `reboot_required` = 1 or 0
     - `last_update_check` = NOW()

3. **Manual Check Request:**
   - User clicks "Check Updates" in UI
   - `/api/check_updates.php` reads database values
   - Returns real data from latest agent check-in
   - Queues command if data is stale

### Database Fields Used:
- `firewalls.updates_available` - Boolean (0/1)
- `firewalls.available_version` - String or NULL
- `firewalls.reboot_required` - Boolean (0/1)
- `firewalls.last_update_check` - Timestamp

## Benefits

1. **Accurate Real-Time Data**: No more random/demo values
2. **Automatic Monitoring**: Updates checked every 2 minutes
3. **No UI Blocking**: Agent does work asynchronously
4. **Centralized Tracking**: All update statuses in database
5. **Version Visibility**: Know which version is available
6. **Reboot Detection**: Know when reboot needed after update

## Deployment

All firewalls will automatically upgrade to v3.8.2 on next check-in (agent auto-update system).
No manual intervention required.

## Testing

To test update detection on a firewall:
```bash
# SSH into firewall
sudo -u www-data ssh -i /var/www/opnsense/keys/id_firewall_21 root@<firewall_ip>

# Run update check function directly
curl -s -k https://localhost/api/core/firmware/status | python -m json.tool

# Or check via command
opnsense-update -c
```

## Version History

- **v3.8.0**: Network configuration detection
- **v3.8.1**: Fixed LAN IP detection + POSIX compatibility
- **v3.8.2**: Added OPNsense firmware update detection (this version)

---
**Implementation Date**: 2025-11-10
**Status**: ✅ Complete and Deployed
