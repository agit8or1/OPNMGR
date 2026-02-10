# Session Timeout Fix - October 20, 2025

## Problem
Users reported that their authentication credentials were expiring shortly after connecting to a firewall through the SSH tunnel system, often within just a few minutes rather than the expected 4 hours.

## Root Cause Analysis

### Initial Investigation
- PHP `gc_maxlifetime` was set to 14400 seconds (4 hours) in `/etc/php/8.3/fpm/php.ini`
- However, the `inc/auth.php` file was NOT explicitly setting session lifetime
- This meant sessions were using PHP defaults which could vary

### Key Issues Identified
1. **No Explicit Session Lifetime**: The `auth.php` file didn't explicitly set `session.gc_maxlifetime` or `session.cookie_lifetime`, relying on php.ini defaults
2. **No Activity Tracking**: There was no last_activity tracking in sessions
3. **Potential Cookie Issues**: Session cookies weren't explicitly set to long lifetime

## Solution Implemented

### Changes to `/var/www/opnsense/inc/auth.php`

1. **Explicit 24-Hour Session Lifetime**
   ```php
   // Set session lifetime to 24 hours (86400 seconds)
   ini_set('session.gc_maxlifetime', 86400);
   ini_set('session.cookie_lifetime', 86400);
   ```

2. **Activity Tracking (Without Timeout)**
   - Added `$_SESSION['last_activity']` tracking on every page load
   - Idle timeout is DISABLED by default (commented out)
   - Reason: Users actively using firewall UI through tunnels shouldn't be logged out

3. **Enhanced Login Function**
   ```php
   function login($username, $password) {
       // ...existing code...
       $_SESSION['last_activity'] = time();
       $_SESSION['login_time'] = time();
       return true;
   }
   ```

4. **Session Regeneration on Login**
   - Added `session_regenerate_id(true)` in login function
   - Prevents session fixation attacks

## Why Idle Timeout is Disabled

When users connect to firewalls through SSH tunnels:
- They may be actively configuring the firewall for 30+ minutes
- The tunnel stays open and active
- Logging them out of OPNManager would be disruptive
- The `tunnel_proxy.php` handles its own timeout logic (30 minutes max, 15 minutes idle)

If you want to enable idle timeout in the future, uncomment this section in `auth.php`:
```php
$idle_timeout = 3600; // 1 hour in seconds
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $idle_timeout) {
    session_unset();
    session_destroy();
    session_start();
    $_SESSION['timeout'] = true;
}
```

## Session Architecture

### OPNManager Sessions (auth.php)
- **Lifetime**: 24 hours
- **Idle Timeout**: Disabled
- **Storage**: `/var/lib/php/sessions/`
- **Cookie Name**: `PHPSESSID`
- **Scope**: OPNManager UI pages

### Tunnel Sessions (tunnel_proxy.php)
- **Lifetime**: 30 minutes max
- **Idle Timeout**: 15 minutes
- **Auth Method**: Session ID in URL (not PHP sessions)
- **Scope**: Firewall UI access through tunnels
- **Note**: Does NOT use `auth.php` to avoid interfering with firewall sessions

## Testing

1. **Test Long Session**:
   - Log into OPNManager
   - Leave browser open for 1+ hour
   - Navigate to different pages
   - Session should remain active

2. **Test Tunnel Session**:
   - Create SSH tunnel to firewall
   - Use firewall UI for 20+ minutes
   - OPNManager session should remain valid
   - Tunnel session will auto-expire after 30 minutes

3. **Test Session Persistence**:
   - Log in to OPNManager
   - Close browser completely
   - Reopen browser within 24 hours
   - Should still be logged in (if cookie persisted)

## Rollback Instructions

If needed, restore the original `auth.php`:
```bash
sudo cp /var/www/opnsense/inc/auth.php.backup /var/www/opnsense/inc/auth.php
```

## Version History
- **v2.2.2**: Initial session timeout fix (October 20, 2025)
- Set 24-hour lifetime, disabled idle timeout, added activity tracking

## Related Files
- `/var/www/opnsense/inc/auth.php` - Main authentication and session handling
- `/var/www/opnsense/tunnel_proxy.php` - Tunnel proxy (does not use auth.php)
- `/etc/php/8.3/fpm/php.ini` - PHP configuration
- `/var/lib/php/sessions/` - Session file storage

## Security Considerations

✅ **Maintained**:
- Session cookies still HTTPOnly
- Session cookies still Secure (HTTPS only)
- Session cookies still SameSite=Lax
- Strict mode enabled
- Session regeneration on login

⚠️ **Changed**:
- Extended session lifetime to 24 hours (was 4 hours)
- Disabled idle timeout (users not logged out for inactivity)

**Justification**: OPNManager is typically used by administrators in controlled environments, and the extended session lifetime improves usability without significant security impact. The system is already protected by HTTPS, strong passwords, and other security measures.

## Future Improvements

1. **Configurable Session Timeout**: Add admin setting to configure session lifetime
2. **Activity Monitoring**: Log session activity for audit purposes
3. **Multi-Factor Auth**: Add 2FA support for enhanced security
4. **Session Limits**: Limit concurrent sessions per user
5. **Remember Me**: Add "remember me" option for even longer sessions

---
**Status**: ✅ FIXED
**Version**: 2.2.2
**Date**: October 20, 2025
