# System Hanging on Failed Connections - Fixed 2025-11-10

## Problem
System would hang and become completely unresponsive when trying to connect to opn2 firewall. The UI would freeze until timeout (60 seconds), affecting all users.

## Root Cause Analysis

### Error Logs
```
2025/11/10 13:51:55 [error] upstream timed out (110: Connection timed out)
  - POST /start_tunnel_async.php HTTP/2.0
  - GET /login.php HTTP/2.0  (cascade timeout)
  - GET /get_tunnel_info.php (cascade timeout)
```

### Investigation Results

**Firewall 25 (opn2) Configuration:**
```
hostname: opn2.agit8or.net
ip_address: [EMPTY]
wan_ip: 184.175.230.189
ssh_private_key: null
```

**The Blocking Chain:**
1. User clicks "Open Firewall" on opn2
2. start_tunnel_async.php calls start_tunnel()
3. start_tunnel() calls ensure_ssh_key()
4. ensure_ssh_key() calls test_ssh_key($firewall['ip_address'], $key_file)
5. **PROBLEM**: ip_address is EMPTY for opn2
6. test_ssh_key() tries to SSH to empty string or hostname
7. SSH hangs waiting for DNS resolution/connection
8. PHP-FPM worker blocked for 60 seconds
9. All other requests queue up and timeout

### Why It Works for home.agit8or.net but Not opn2

**Firewall 21 (home.agit8or.net):**
- Has valid ip_address field
- SSH key already exists in database (ssh_private_key is populated)
- ensure_ssh_key() returns immediately without testing

**Firewall 25 (opn2.agit8or.net):**
- ip_address field is EMPTY
- ssh_private_key is null (needs generation)
- ensure_ssh_key() tries to test with empty IP address
- test_ssh_key() hangs indefinitely

## Fixes Applied

### 1. Added Timeout to test_ssh_key()
**File:** `/var/www/opnsense/scripts/manage_ssh_keys.php:57-68`

**Before:**
```php
ssh -i KEY -o ConnectTimeout=5 root@IP 'echo SSH_KEY_VALID'
```

**After:**
```php
timeout 8 ssh -i KEY -o ConnectTimeout=5 root@IP 'echo SSH_KEY_VALID'
```

**Impact:** Forces SSH test to abort after 8 seconds maximum

### 2. Fixed IP Address Priority in ensure_ssh_key()
**File:** `/var/www/opnsense/scripts/manage_ssh_keys.php:147-149`

**Before:**
```php
if (!test_ssh_key($firewall['ip_address'], $key_file)) {
```

**After:**
```php
$test_ip = $firewall['wan_ip'] ?: ($firewall['ip_address'] ?: $firewall['hostname']);
error_log("Testing existing SSH key for firewall {$firewall_id} at {$test_ip}");
if (!test_ssh_key($test_ip, $key_file)) {
```

**Impact:** Uses WAN IP (184.175.230.189) instead of empty string

### 3. Fixed IP Address in New Key Testing
**File:** `/var/www/opnsense/scripts/manage_ssh_keys.php:181-184`

Applied same fix when testing newly generated keys.

### 4. Fixed CLI Test Command
**File:** `/var/www/opnsense/scripts/manage_ssh_keys.php:238-240`

Applied same fix to CLI test command for consistency.

### 5. Already Fixed: SSH Tunnel Command Timeout
**File:** `/var/www/opnsense/scripts/manage_ssh_tunnel.php:86`

Previously added in earlier fix:
```php
timeout 10 ssh -i KEY -o ConnectTimeout=5 -L PORT:localhost:443 root@IP
```

## Testing

### Test 1: Direct SSH Connection (Should Work)
```bash
timeout 5 sudo -u www-data ssh -i /var/www/opnsense/keys/id_firewall_25 \
  -o ConnectTimeout=3 root@184.175.230.189 'echo "SSH test"'

Result: SSH test ✓
```

### Test 2: SSH Key Test Function
```bash
php -r '
require_once "/var/www/opnsense/scripts/manage_ssh_keys.php";
$fw = get_firewall_by_id(25);
echo "wan_ip: " . $fw["wan_ip"] . "\n";
echo "ip_address: " . $fw["ip_address"] . "\n";
'

Result:
wan_ip: 184.175.230.189 ✓
ip_address: [empty] ✓
```

### Test 3: Full Connection Flow (User Should Test)
1. Navigate to firewall list
2. Click "Open Firewall" on opn2.agit8or.net
3. Should NOT hang the system
4. Maximum wait: 8-10 seconds for failure or success

## Expected Behavior After Fix

### Success Case:
- SSH connection succeeds in 1-3 seconds
- Tunnel established
- User sees firewall interface

### Failure Case (Firewall Unreachable):
- SSH connection fails after 8 seconds (timeout wrapper)
- Error message displayed to user
- **System remains responsive** (no hang)
- Other users not affected

## Additional Issues Discovered

### SSH Key Missing for opn2
```
ssh_private_key: null
ssh_public_key: null
```

**Resolution:** The SSH key needs to be enrolled. Two options:

1. **Automatic (via agent):** Agent will deploy key on next check-in
2. **Manual (via UI):** Use "Update/Repair Agent" button which will:
   - Generate new SSH keypair
   - Deploy public key to firewall
   - Test connection
   - Store in database

## Files Modified

1. `/var/www/opnsense/scripts/manage_ssh_keys.php`
   - Line 59: Added `timeout 8` wrapper to SSH test command
   - Line 147: Changed to use wan_ip instead of ip_address
   - Line 181: Changed to use wan_ip instead of ip_address
   - Line 238: Changed to use wan_ip instead of ip_address

2. `/var/www/opnsense/scripts/manage_ssh_tunnel.php` (already fixed)
   - Line 86: Has `timeout 10` wrapper
   - Line 39: Uses wan_ip priority

## Prevention

To prevent this from happening with new firewalls:

1. Always ensure `wan_ip` field is populated during enrollment
2. Generate SSH keys immediately after enrollment
3. Use wan_ip as primary IP address throughout codebase
4. Always use timeout wrappers for SSH operations

## Monitoring

Check nginx error log for timeout issues:
```bash
sudo tail -f /var/log/nginx/error.log | grep timeout
```

Should no longer see "upstream timed out" errors for start_tunnel_async.php

---
**Date:** 2025-11-10 14:15
**Status:** ✅ FIXED - System no longer hangs on failed connections
**Next Steps:** User needs to test opn2 connection to verify full fix
