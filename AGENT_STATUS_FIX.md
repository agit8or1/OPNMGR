# Agent Status Display Fix

## Issues Fixed

### Issue 1: "Agent Offline" Button Showing Despite Agent Being Online

**Problem:**
- Secure Connection section showed "Agent Offline" button (disabled)
- Primary agent was actually online and checking in every 120 seconds

**Root Cause:**
```php
// WRONG - undefined variable
<?php if ($primary_agent && $primary_agent["status"] == "online"): ?>

// CORRECT - use the $agents array
<?php if (isset($agents['primary']) && $agents['primary']["status"] == "online"): ?>
```

**Solution:**
- Fixed variable reference from `$primary_agent` to `$agents['primary']`
- Now correctly detects when primary agent is online
- "Connect Now" button appears when agent is active

---

### Issue 2: "Update Agent: Not configured" (This is CORRECT)

**Status:** This is actually the correct display!

**Why:**
- FW #21 currently has **ONLY** the primary agent installed
- The update agent has **NOT** been deployed yet
- Database shows only one agent:
  ```
  agent_type: primary
  agent_version: 2.4.0
  status: online
  last_checkin: 2025-10-08 17:55:01
  ```

**To Fix:**
You need to manually deploy the update agent as documented in:
- `/var/www/opnsense/MANUAL_UPDATE_AGENT_DEPLOY.md`

**Deployment Command (run on firewall console):**
```bash
curl -k -o /tmp/opnsense_update_agent.sh \
  'https://opn.agit8or.net/download_update_agent.php?firewall_id=21'

mv /tmp/opnsense_update_agent.sh /usr/local/bin/opnsense_update_agent.sh
chmod +x /usr/local/bin/opnsense_update_agent.sh
nohup /usr/local/bin/opnsense_update_agent.sh > /dev/null 2>&1 &
```

**After Deployment:**
Within 5 minutes, the update agent will check in and you'll see:
```
✓ Update Agent:
  Version: 2.4.0
  Last checkin: XXs ago
```

---

## Current vs Expected State

### Current State (Before Update Agent Deployment)
```
✓ Primary Agent: Online
  Version: 2.4.0
  Last checkin: Active

○ Update Agent: Not configured
  [Yellow warning box]
```

### Expected State (After Update Agent Deployment)
```
✓ Primary Agent: Online
  Version: 2.4.0
  Last checkin: Active

✓ Update Agent: Online
  Version: 2.4.0
  Last checkin: Active
  [Yellow highlight box - bulletproof updates]
```

---

## Verification

### Check Current Agents
```bash
php /var/www/opnsense/check_dual_agents.php
```

**Current Output:**
```
FW #21 (home.agit8or.net): ⚠️  WARNING
    Primary: v2.4.0 (0 min ago)
    Missing update agent
```

**After Deployment:**
```
FW #21 (home.agit8or.net): ✅ OK
    Primary: v2.4.0 (0 min ago)
    Update: v2.4.0 (3 min ago)
```

### Database Check
```sql
SELECT agent_type, agent_version, last_checkin, status
FROM firewall_agents
WHERE firewall_id = 21
ORDER BY agent_type;
```

**Current:**
```
primary | 2.4.0 | 2025-10-08 17:55:01 | online
```

**After Deployment:**
```
primary | 2.4.0 | 2025-10-08 17:55:01 | online
update  | 2.4.0 | 2025-10-08 17:58:23 | online
```

---

## Files Modified

### Fixed
- `/var/www/opnsense/firewall_details.php`
  - Changed: `$primary_agent` → `$agents['primary']`
  - Line 229: Secure Connection button logic

### Backup
- `/var/www/opnsense/firewall_details.php.backup_*`

---

## Summary

✅ **"Agent Offline" Button** - FIXED  
  - Was showing due to wrong variable name
  - Now correctly shows "Connect Now" when primary agent is online

⚠️  **"Update Agent: Not configured"** - CORRECT DISPLAY  
  - This is accurate - the update agent hasn't been deployed yet
  - Not a bug, it's showing the true state
  - Deploy update agent to change this to green checkmark

---

## Next Steps

1. **Refresh page** - "Connect Now" button should now appear
2. **Deploy update agent** - Follow MANUAL_UPDATE_AGENT_DEPLOY.md
3. **Wait 5 minutes** - Update agent will check in
4. **Refresh again** - Both agents will show green checkmarks

---

**The "Not configured" message is INTENTIONAL and CORRECT until you deploy the update agent!**
