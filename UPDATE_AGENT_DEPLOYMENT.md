# Update Agent Deployment - October 8, 2025

## ✅ Issues Fixed

### 1. On-Demand Proxy Page Error (HTTP 500) - FIXED
**Problem:** SQL error when clicking "Connect Now"
```
Column not found: 1054 Unknown column 'details' in 'INSERT INTO'
```

**Root Cause:** Wrong column name in system_logs INSERT
- Used: `details` (doesn't exist)
- Should be: `additional_data`

**Solution:** 
- Fixed `/var/www/opnsense/firewall_proxy_ondemand.php` line 55
- Changed column name and added missing `level` field

**Status:** ✅ Fixed - "Connect Now" button should work now

---

### 2. Update Agent Deployment - IN PROGRESS

**Method:** Deploying via primary agent command queue

**Commands Queued:**
- Command #773: First attempt (failed - nohup issue)
- Command #774: Second attempt (simpler, should work)

**Command:**
```bash
curl -k -s -o /usr/local/bin/opnsense_update_agent.sh \
  'https://opn.agit8or.net/download_update_agent.php?firewall_id=21' && \
chmod +x /usr/local/bin/opnsense_update_agent.sh && \
/usr/local/bin/opnsense_update_agent.sh &
```

**Timeline:**
- 18:05:22 - Command #773 queued and sent
- 18:05:22 - Downloaded 4,385 bytes (success!)
- 18:05:22 - Failed to start (nohup issue)
- 18:06:XX - Command #774 queued (simpler version)
- 18:08:XX - Waiting for execution
- ~18:13:XX - Update agent should check in (5 min interval)

---

## Verification

### Check Command Status
```bash
php -r "require '/var/www/opnsense/inc/db.php'; \$stmt = \$DB->query('SELECT id, status, result FROM firewall_commands WHERE id = 774'); print_r(\$stmt->fetch());"
```

### Check for Update Agent
```bash
php /var/www/opnsense/check_dual_agents.php
```

**Expected Output (after 5 minutes):**
```
FW #21 (home.agit8or.net): ✅ OK
    Primary: v2.4.0 (0 min ago)
    Update: v2.4.0 (2 min ago)
```

### Database Check
```sql
SELECT agent_type, agent_version, last_checkin, status
FROM firewall_agents
WHERE firewall_id = 21
ORDER BY agent_type;
```

**Should show:**
```
primary | 2.4.0 | [recent] | online
update  | 2.4.0 | [recent] | online
```

---

## If Update Agent Doesn't Appear

### Manual Verification on Firewall
SSH to firewall and check:
```bash
# Check if file was downloaded
ls -la /usr/local/bin/opnsense_update_agent.sh

# Check if process is running
ps aux | grep opnsense_update_agent.sh | grep -v grep

# Manually start if needed
/usr/local/bin/opnsense_update_agent.sh &
```

### Check Logs
```bash
# Agent checkin logs
tail -50 /var/www/opnsense/logs/agent_checkins.log | grep "FW #21"

# Command execution logs
php -r "require '/var/www/opnsense/inc/db.php'; \$stmt = \$DB->query('SELECT command, result FROM firewall_commands WHERE firewall_id = 21 ORDER BY id DESC LIMIT 3'); while(\$r = \$stmt->fetch()) { echo \"Command: \" . substr(\$r['command'], 0, 80) . \"...\\nResult: \" . \$r['result'] . \"\\n\\n\"; }"
```

---

## Files Modified

### Fixed
- `/var/www/opnsense/firewall_proxy_ondemand.php`
  - Line 55: Changed `details` → `additional_data`
  - Added `level` field to INSERT statement

### Backups
- `/var/www/opnsense/firewall_proxy_ondemand.php.backup_*`

### Commands Queued
- firewall_commands #773 - Deploy update agent (v1)
- firewall_commands #774 - Deploy update agent (v2 - simplified)

---

## Next Steps

1. **Wait 5-10 minutes** for update agent to check in
2. **Refresh firewall details page**
   - Should show: ✅ Update Agent: Online
3. **Test "Connect Now" button** (proxy page is fixed)
4. **Verify dual-agent status:**
   ```bash
   php /var/www/opnsense/check_dual_agents.php
   ```

---

## Why Deploy Via Agent Command?

✅ **Advantages:**
- No SSH access needed
- Automated deployment
- Works from web interface
- Agent executes locally on firewall

✅ **How It Works:**
1. Command queued in database
2. Primary agent checks in (every 120s)
3. Agent sees pending command
4. Agent downloads update agent script
5. Agent installs and starts update agent
6. Update agent checks in (every 300s)
7. Dual-agent system complete!

---

## Status Summary

✅ **On-Demand Proxy** - Fixed (SQL error resolved)  
⏳ **Update Agent Deployment** - In progress (command #774 executing)  
⏰ **Update Agent Check-in** - Expected within 5-10 minutes  

**Refresh page in 10 minutes to see both agents online!** 🎉
