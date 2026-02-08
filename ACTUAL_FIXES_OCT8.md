# ACTUAL Fixes - October 8, 2025 @ 19:00

## ✅ Issue 1: Dropdown Hover Effect - ACTUALLY FIXED
**Problem:** Dropdown menu items had no hover effect after previous "fix"

**Root Cause:** Previous fix only changed text color to white, didn't add the glow effect

**ACTUAL Fix:**
```css
/* BEFORE */
.dropdown-item:hover { background-color: var(--sidebar-bg); color: #ffffff!important; }

/* AFTER - Matches top-level button hover */
.dropdown-item:hover { 
    background-color: rgba(138,180,248,0.2)!important; 
    color: #8ab4f8!important; 
    box-shadow: 0 0 10px rgba(138,180,248,0.3)!important; 
    transform: translateX(2px); 
    transition: all 0.2s ease-in-out; 
}
```

**Files Modified:** `/var/www/opnsense/inc/header.php` (line 184)

**Result:** ✅ **TESTED** - Dropdown items now have blue glow on hover like buttons

---

## ⚠️ Issue 2: Connect Now Button - ROOT CAUSE IDENTIFIED
**Problem:** Popup shows "timeout" then redirects back to opnmanager instead of connecting to firewall

**Investigation Results:**
```sql
SELECT id, status, updated_at FROM request_queue WHERE status='pending' ORDER BY id DESC LIMIT 5;

#157: pending (updated: never)
#156: pending (updated: never)  
#155: pending (updated: never)
...15 total pending requests
```

**ROOT CAUSE IDENTIFIED:**
1. ✅ `firewall_proxy_ondemand.php` creates request successfully
2. ✅ `check_tunnel_status.php` exists and works
3. ❌ **Agent never picks up the proxy requests!**
4. Requests stay "pending" forever
5. After 30 seconds, timeout occurs
6. JavaScript redirects back to firewall_details.php

**Why Agent Doesn't Process Requests:**
The agent script needs to call `agent_proxy_check.php` to see pending requests. Either:
- Agent script isn't calling it
- Agent script has wrong URL
- Agent script isn't running the proxy check code

**Status:** ❌ **NOT FIXED** - Requires agent script modification or debugging

**Temporary Workaround:**
User would need to manually establish SSH tunnel:
```bash
ssh -L 8100:localhost:443 root@firewall-ip
# Then browse to http://localhost:8100
```

---

## ⚠️ Issue 3: Update Agent - DEPLOYMENT FAILED
**Problem:** Update agent still shows "not configured"

**Command Results:**
- Command #773: **failed** (nohup execution issue)
- Command #774: **failed** (timeout)

**Why Commands Failed:**
```bash
# Command was:
curl -k -s -o /usr/local/bin/opnsense_update_agent.sh \
  'https://opn.agit8or.net/download_update_agent.php?firewall_id=21' && \
chmod +x /usr/local/bin/opnsense_update_agent.sh && \
/usr/local/bin/opnsense_update_agent.sh &

# But agent likely can't reach opn.agit8or.net from firewall
# Or command times out before completing
```

**Status:** ❌ **NOT FIXED** - Manual deployment required

**Recommendation:**
```bash
# SSH to firewall and run:
fetch -o /tmp/update_agent.sh https://opn.agit8or.net/download_update_agent.php?firewall_id=21
chmod +x /tmp/update_agent.sh  
/tmp/update_agent.sh &
```

---

## Summary

| Issue | Status | Actually Fixed? |
|-------|--------|-----------------|
| Dropdown hover effect | ✅ FIXED | YES - Tested CSS |
| Connect Now button | ❌ NOT WORKING | NO - Agent not processing |
| Update agent | ❌ NOT DEPLOYED | NO - Commands failed |

---

## What Actually Works Now

1. ✅ **Backup Retention Modal** - Opens and saves (from earlier)
2. ✅ **Dropdown Hover** - Blue glow effect works
3. ✅ **Dropdown Contrast** - Text readable (from earlier)

## What Still Broken

1. ❌ **Proxy Tunnels** - Agent not processing requests (15 stuck in queue)
2. ❌ **Update Agent** - Not deployed (commands timed out)
3. ⚠️ **Duplicate Blocked Logs** - Still happening (318K+ logs)

---

## Next Steps for User

### Immediate Action Required:

1. **SSH to firewall and check agent process:**
   ```bash
   ps aux | grep opnsense_agent | grep -v grep
   # Should see 1-2 processes
   
   # If multiple processes, kill extras:
   pkill -f opnsense_agent
   # Then restart proper agent
   ```

2. **Manually deploy update agent:**
   ```bash
   fetch -o /tmp/update_agent.sh https://opn.agit8or.net/download_update_agent.php?firewall_id=21
   chmod +x /tmp/update_agent.sh
   /tmp/update_agent.sh &
   ```

3. **Test if agent processes proxy requests:**
   - After fixing agent, click "Connect Now" again
   - Should see request move from "pending" to "processing"
   - Then redirect to http://127.0.0.1:8100 (or 8101, etc.)

---

## Files Modified This Session

1. `/var/www/opnsense/settings.php`
   - Fixed modal target
   - Removed duplicate modal
   
2. `/var/www/opnsense/inc/header.php`  
   - Fixed dropdown hover (actually tested this time!)

3. `/var/www/opnsense/firewall_proxy_ondemand.php`
   - Port range 9000-9999 → 8100-8200 (earlier)

---

**1 out of 3 issues actually fixed in this session.**

The other 2 require agent-level fixes that can't be done from web interface.
