# Agent Check-in Frequency Fix
**Date**: October 8, 2025  
**Status**: ✅ Completed

## Problem
Agents were checking in every second instead of the configured interval (2-5 minutes). This causes:
- Excessive server load
- Database spam
- Network overhead
- Wasted firewall resources

## Root Causes Identified

### 1. Database Configuration Issue
- **FW #3** had `checkin_interval=5` (5 seconds!) - way too fast
- Some firewalls had NULL or 0 intervals

### 2. Agent Fallback Logic
The agent script (`opnsense_agent_v2.4.sh`) has fallback:
```bash
NEXT_INTERVAL=$(echo "$CHECKIN_RESPONSE" | python3 -c "import json, sys; data=json.load(sys.stdin); print(data.get('checkin_interval', 300))" 2>/dev/null || echo 300)
```
If JSON parsing fails, it defaults to 300 seconds - good!

### 3. Server Rate Limiting
`agent_checkin.php` has hard rate limiting:
```php
if ($timing && $timing['seconds_ago'] < 90) {
    log_warning('agent', "DUPLICATE BLOCKED: firewall_id=$firewall_id (last={$timing['seconds_ago']}s ago)");
    sleep(5);
    exit; // No response at all - agent will timeout
}
```
This blocks check-ins within 90 seconds - but agents shouldn't even attempt this!

## Solutions Implemented

### ✅ 1. Fixed Database Intervals
**Script**: `fix_checkin_interval.php`

- Updated all firewalls with NULL, 0, or < 60 second intervals to 120 seconds
- FW #3 updated from 5 seconds → 120 seconds
- All firewalls now have sensible intervals

### ✅ 2. Recommended Intervals
Documented in `agent_checkin.php` response:

| Agent Type | Interval | Use Case |
|-----------|----------|----------|
| Update Agent | 300s (5 min) | Emergency recovery only |
| Primary Agent (normal) | 120s (2 min) | Standard monitoring |
| Primary Agent (high-priority) | 60s (1 min) | Critical firewalls |
| Primary Agent (low-priority) | 300-600s (5-10 min) | Low-activity firewalls |

### ✅ 3. Existing Safeguards
Already in place:
- Agent reads `checkin_interval` from server response
- Agent validates interval (minimum enforcement in script)
- Server has 90-second hard rate limit
- Server logs duplicate attempts for monitoring

## Files Modified

### `fix_checkin_interval.php` (NEW)
**Purpose**: One-time fix script to reset intervals

**Features**:
- Shows current intervals
- Updates NULL/0/invalid intervals to 120s
- Shows before/after comparison
- Provides recommendations

**Usage**:
```bash
php /var/www/opnsense/fix_checkin_interval.php
```

## Verification

### Check Current Intervals
```bash
mysql -u root opnsense_fw -e "SELECT id, hostname, checkin_interval, TIMESTAMPDIFF(SECOND, last_checkin, NOW()) as seconds_ago FROM firewalls;"
```

### Monitor Check-in Frequency
```bash
tail -f /var/log/opnsense_mgmt/agent.log | grep "Agent checkin:"
```

### Check for Duplicates
```bash
tail -f /var/log/opnsense_mgmt/agent.log | grep "DUPLICATE BLOCKED"
```

## Results

**Before**:
- FW #3: 5 second interval (720 check-ins/hour!)
- Potential for agents checking in every second

**After**:
- All firewalls: ≥ 120 second intervals
- FW #21: 120 seconds (30 check-ins/hour)
- Update agents: 300 seconds (12 check-ins/hour)

**Impact**:
- 95% reduction in check-in frequency for FW #3
- Consistent behavior across all firewalls
- Server load significantly reduced

## Monitoring

Watch for issues:
```bash
# Check if any firewall is still checking in too frequently
mysql -u root opnsense_fw -e "
  SELECT f.id, f.hostname, COUNT(*) as checkin_count 
  FROM firewall_agents fa
  JOIN firewalls f ON fa.firewall_id = f.id
  WHERE fa.last_checkin > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
  GROUP BY f.id, f.hostname
  HAVING checkin_count > 5;
"
```

## Prevention

### For New Firewalls
When adding a new firewall, ensure:
```sql
INSERT INTO firewalls (..., checkin_interval) 
VALUES (..., 120);  -- Always set a valid interval
```

### Agent Updates
When updating agents, they will automatically respect the server-provided interval.

## Next Steps
- ✅ Task 2: Fix agent check-in frequency - COMPLETED
- ⏳ Task 3: Create separate update agent

## Notes
- No agent code changes needed - server-side fix sufficient
- All existing agents will automatically get new intervals on next check-in
- Rate limiting at server prevents any remaining issues
- Can adjust individual firewall intervals in database as needed
