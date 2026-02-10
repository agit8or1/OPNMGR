# Issues Analysis - October 20, 2025

## Issue #1: Badge Not Showing ❌
**Status**: NOT FIXED
**Root Cause**: nginx sub_filter module is NOT working despite:
- gunzip module enabled ✓
- HTML being decompressed ✓  
- sub_filter directive in config ✓
- No nginx errors ✓

**Attempted Fixes**:
1. ✗ Added `proxy_set_header Accept-Encoding "";` - didn't work
2. ✗ Added `gunzip on;` - HTML decompressed but sub_filter still not working
3. ✗ Changed from </body> to </head> injection - didn't work
4. ✗ Switched to JavaScript injection - didn't work

**Real Issue**: sub_filter ISN'T WORKING AT ALL in nginx proxy context
**Alternative Solution**: Use PHP proxy (tunnel_with_badge.php) OR inject via JavaScript on the main page

## Issue #2: Session Timeout ❌
**Status**: NOT FIXED  
**Root Cause**: NOT PHP session timeout (that's 4 hours)
**Real Issue**: Application-level session validation or cookie expiry

**What We Know**:
- PHP gc_maxlifetime = 14400 (4 hours) ✓
- User says timeout happens "within a few minutes"
- No hardcoded timeout found in auth.php

**Need to Check**:
1. Cookie lifetime settings
2. Application session checks in auth middleware
3. Firewall details page session validation
4. Any JavaScript that checks session state

## Issue #3: Grading 107/100 ❌
**Status**: NOT FIXED but CAP EXISTS
**Root Cause**: UNKNOWN - cap function exists `return min($health_score, 100);`

**What We Know**:
- calculateHealthScore() has `return min($health_score, 100)` at line 89
- Function is called once at line 179
- Maximum possible score WITHOUT cap: 110 points
  - Connectivity: 35
  - Version: 25  
  - Updates: 20 (but adds 10 default)
  - Uptime: 15
  - Config: 15
  - Total: 35+25+10+20+15 = 105-110

**Possible Causes**:
1. Database storing uncapped value?
2. Display code (lines 824+) calculating separately?
3. Old cached value?

**Need to Test**: Open browser dev tools, check actual score value

## Issue #4: Automated Backups Not Working ❌
**Status**: PARTIALLY WORKING
**What's Happening**:
- Cron runs ✓
- Commands get QUEUED ✓  
- Backups in /var/www/opnsense/backups/ exist ✓

**Real Issue**: The backup system uses TWO METHODS:
1. **OLD**: firewall_commands table + agent processing (NO LONGER USED)
2. **NEW**: Direct SSH execution (SHOULD BE USED)

**What nightly_backups.php Does**:
- Queues to OLD firewall_commands table
- Creates backup script that uploads to upload_backup.php
- But WITH permanent SSH rule, should execute directly via SSH!

**Solution**: Update nightly_backups.php to use direct SSH like manage_ssh_tunnel.php does

---

## PRIORITY FIXES

### 1. Badge (HIGH - User complained)
**Simplest Fix**: Use tunnel_with_badge.php PHP proxy
- Change start_tunnel_async.php to return:
  `https://opn.agit8or.net/tunnel_with_badge.php?session={id}`
- This PHP script proxies to tunnel and injects badge in HTML

### 2. Session Timeout (HIGH - User complained)  
**Next Steps**:
1. Check cookie settings in PHP
2. Add session debugging to see what's expiring
3. Check if firewall details page has timeout logic

### 3. Grading 107/100 (MEDIUM - Cosmetic)
**Debug Steps**:
1. Add logging to calculateHealthScore()
2. Check browser network tab for actual value
3. Verify display isn't adding extra points

### 4. Backups (LOW - Working but inefficiently)
**Fix**: Update nightly_backups.php to use direct SSH instead of command queue

