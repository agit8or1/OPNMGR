# Log System Improvements - October 5, 2025

## Summary
Fixed log filtering and standardized categories, updated agent checkin intervals, and improved auto-refresh timing.

## Changes Made

### 1. Agent Checkin Intervals ✅
**File**: `/var/www/opnsense/agent_checkin.php`

- **Primary Agent**: Now checks in every **120 seconds (2 minutes)** instead of 5 minutes
- **Update Agent**: Checks in every **300 seconds (5 minutes)** 
- Agents automatically adjust based on `checkin_interval` returned from server

**Why**: Faster response time for commands and proxy requests with primary agent, while update agent conserves resources with less frequent checkins.

### 2. Log Category Standardization ✅
**Files**: 
- `/var/www/opnsense/LOG_CATEGORIES.md` (new documentation)
- Database updates via SQL

**Problems Fixed**:
- Inconsistent category naming ("Agent Checkin" vs "command" vs "agent_update")
- Mixed case (some uppercase, some lowercase)
- Spaces vs underscores

**Solution**:
- Normalized ALL existing logs to lowercase with underscores
- Consolidated similar categories:
  - "agent_checkin" + "agent_update" → **"agent"**
  - "system_update" + "system_management" + "updater" → **"system"**
- Created standard category list with guidelines

**Standard Categories**:
- `agent` - Agent Events (98 logs)
- `proxy` - Proxy Requests
- `command` - Commands (37 logs)
- `firewall` - Firewall Events (25 logs)
- `backup` - Backups (35 logs)
- `auth` - Authentication
- `system` - System (41 logs)
- `dashboard` - Dashboard (10 logs)
- `housekeeping` - Maintenance (2 logs)

### 3. Friendly Category Names in UI ✅
**File**: `/var/www/opnsense/logs.php`

Added display name mapping so dropdown shows:
- "Agent Events" instead of "agent"
- "Proxy Requests" instead of "proxy"
- "Commands" instead of "command"
- etc.

**Before**: Dropdown showed raw database values like "agent", "command"
**After**: Dropdown shows friendly names like "Agent Events", "Commands"

### 4. Auto-Refresh Interval ✅
**File**: `/var/www/opnsense/logs.php`

Changed from **5 seconds** to **10 seconds**

**Why**: 
- Less server load
- Less distracting for users viewing logs
- Still frequent enough to feel "live"
- More reasonable for log viewing (logs don't change that fast)

## Database Changes

```sql
-- Normalize categories to lowercase with underscores
UPDATE system_logs SET category = LOWER(REPLACE(REPLACE(category, ' ', '_'), '-', '_'));

-- Consolidate agent categories
UPDATE system_logs SET category = 'agent' WHERE category IN ('agent_checkin', 'agent_update');

-- Consolidate system categories
UPDATE system_logs SET category = 'system' WHERE category IN ('system_management', 'system_update', 'updater');
```

## Testing

1. ✅ Category filter now works correctly
2. ✅ Dropdown shows friendly names ("Agent Events" not "agent")
3. ✅ Auto-refresh is 10 seconds instead of 5
4. ✅ Primary agent checks in every 2 minutes
5. ✅ Update agent checks in every 5 minutes

## Future Improvements

1. Add "proxy" category logs when proxy feature is used
2. Consider adding search within logs
3. Add export to CSV functionality
4. Add real-time log streaming with WebSockets

## Documentation Created

- `/var/www/opnsense/LOG_CATEGORIES.md` - Complete guide on standard categories and usage patterns
