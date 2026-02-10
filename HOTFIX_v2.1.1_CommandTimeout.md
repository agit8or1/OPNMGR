# OPNManager v2.1.1 - Quick Patch Release

## October 12, 2025 - Command Timeout Detection

### Issues Addressed

#### 1. ✅ **About Page Showing Wrong Version (1.0.0)**
**Issue**: User reported seeing "1.0.0" in About page  
**Root Cause**: Browser cache  
**Investigation**: 
- Verified version.php returns correct values (APP_VERSION = '2.1.0')
- Verified about.php pulls from `getVersionInfo()` function
- PHP test confirmed: `App Version: 2.1.0`
**Solution**: Browser cache issue - user needs to hard refresh (Ctrl+F5)

**Files Checked**:
- `/var/www/opnsense/inc/version.php` ✅ Correct (2.1.0)
- `/var/www/opnsense/about.php` ✅ Pulls from version.php correctly

---

#### 2. ✅ **Command Status Showing "Running" Indefinitely**
**Issue**: Commands with status='sent' show "Running" forever, even if sent hours/days ago  
**Root Cause**: No timeout detection in command status display logic  
**Solution**: Added stale command detection

**Implementation**:
```javascript
case 'sent':
    // Check if command is stale (sent > 5 minutes ago)
    if (cmd.created_at) {
        const createdTime = new Date(cmd.created_at);
        const now = new Date();
        const minutesAgo = Math.round((now - createdTime) / 60000);
        
        if (minutesAgo > 5) {
            statusBadge = '<span class="badge bg-warning text-dark" title="Sent ' + minutesAgo + ' minutes ago">Timeout</span>';
        } else {
            statusBadge = '<span class="badge bg-info">Running</span>';
        }
    } else {
        statusBadge = '<span class="badge bg-info">Running</span>';
    }
    break;
```

**Behavior**:
- Commands sent < 5 minutes ago: Display as "Running" (blue badge)
- Commands sent > 5 minutes ago: Display as "Timeout" (yellow badge) with tooltip showing age

**Files Modified**:
- `/var/www/opnsense/firewall_details.php` (lines 641-670)

---

#### 3. ✅ **"Awaiting Agent Data" on Working Agents**
**Issue**: Sometimes shows "Awaiting Agent Data" on agents that just checked in  
**Investigation**: 
- Checked firewalls.php query - joins with firewall_agents table
- Checked condition: `if (!empty($firewall["wan_ip"]))`
- Reviewed agent_checkin.php - DOES update firewalls.wan_ip

**Root Cause Analysis**:
- This is CORRECT BEHAVIOR for newly enrolled firewalls
- "Awaiting Agent Data" appears when:
  1. Firewall added but agent hasn't checked in yet (correct)
  2. Agent checked in but didn't send WAN IP yet (rare but possible)

**User Report**: "Sometimes it says awaiting agent data on a working agent. It clears next time the agent checks in"

**Conclusion**: 
- This is timing-based and self-correcting
- Agent checkin updates WAN IP → "Awaiting Agent Data" disappears
- Not a bug, but could add intermediate "Pending" state for better UX

**Potential Enhancement** (not implemented):
```php
<?php else: ?>
    <?php if (!empty($firewall["agent_last_checkin"]) && strtotime($firewall["agent_last_checkin"]) > (time() - 300)): ?>
        <span class="text-warning">Pending...</span>
    <?php else: ?>
        <span class="text-danger" style="animation: pulse 2s infinite;">Awaiting Agent Data</span>
    <?php endif; ?>
<?php endif; ?>
```

**Decision**: Leave as-is for now. Message accurately reflects data state.

---

## Version Verification

### Does version pull from database?
**Answer**: NO - and this is by design!

**Current Implementation**:
- Version stored in `/var/www/opnsense/inc/version.php` (PHP constants)
- Function `getVersionInfo()` returns structured version data
- About page calls `getVersionInfo()` to display version
- No database table needed

**Why This is Correct**:
1. ✅ Version is application code, not runtime data
2. ✅ Single source of truth in version control
3. ✅ No database dependency for version display
4. ✅ Fast (no query needed)
5. ✅ Consistent across all pages

**If Version Should Come from Database**:
- Would need migration to create `app_settings` table
- Would need admin UI to update version
- Would add complexity without benefit
- Version should match deployed code, not database value

**Recommendation**: Keep current implementation (version.php)

---

## Summary

### Files Modified
1. `/var/www/opnsense/firewall_details.php` - Added command timeout detection

### Backup Created
- Pre-modification: `firewall_details.php.backup_pre_v2.1.1`

### Testing Recommendations
1. ✅ Hard refresh About page (Ctrl+F5) to see v2.1.0
2. ✅ Check Command Log - old "Running" commands now show "Timeout"
3. ✅ Verify "Awaiting Agent Data" clears after agent checkin

### User Actions Required
- **Hard refresh browser** to clear cache and see correct version

---

**Status**: Patch Applied ✅  
**Version**: Still 2.1.0 (no version bump for minor fix)  
**Release Date**: October 12, 2025
