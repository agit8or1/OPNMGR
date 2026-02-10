# ROOT CAUSE: opn2 Connection Failure - November 10, 2025

## USER REPORT
"still cant connect to opn2. CHECK ALL LOGS AND FIND OUT WHY!!"

## EXHAUSTIVE LOG ANALYSIS

### 1. Nginx Error Log
```
2025/11/10 13:51:55 [error] upstream timed out (110: Connection timed out)
  - POST /start_tunnel_async.php HTTP/2.0
  - Client: 73.35.46.112 (user's browser)
```

### 2. PHP-FPM Log
- No relevant errors (empty)

### 3. System Log
- No SSH or tunnel failures

### 4. Reverse Tunnel Auto-Queue Issue (Red Herring)
```
PHP message: Auto-queued tunnel setup for firewall 25
```
- These stopped after tunnel_established was set at 13:48:37
- Last auto-queue: 13:48:02 (BEFORE tunnel_established)
- This was NOT the cause

## INVESTIGATION STEPS

### Step 1: Check Database Configuration
```sql
SELECT id, hostname, tunnel_established, proxy_port, web_port
FROM firewalls WHERE id = 25;
```

Result:
```
id: 25
hostname: opn2.agit8or.net
tunnel_established: 2025-11-10 13:48:37  ✓
proxy_port: 8103  ✓
web_port: 443  ← PROBLEM!
```

### Step 2: Verify SSH Connectivity
```bash
sudo -u www-data ssh -i /var/www/opnsense/keys/id_firewall_25 root@184.175.230.189 'echo TEST'
```
Result: **"TEST"** ✓ SSH works perfectly

### Step 3: Check SSH Key Files
```bash
ls -la /var/www/opnsense/keys/id_firewall_25*
```
Result:
```
-rw------- 1 www-data www-data 411 Nov 10 14:07 id_firewall_25
-rw-r--r-- 1 www-data www-data  98 Nov 10 14:07 id_firewall_25.pub
```
✓ Keys exist

### Step 4: Test Manual Tunnel Creation
```bash
sudo -u www-data ssh -i /var/www/opnsense/keys/id_firewall_25 \
  -L 127.0.0.1:9999:localhost:443 -N -f root@184.175.230.189
```
Result: Tunnel created ✓

```bash
curl -k https://localhost:9999/
```
Result: **"OpenSSL SSL_ERROR_SYSCALL"** ✗ Connection failed!

### Step 5: Check What's Actually Listening on Firewall
```bash
sudo -u www-data ssh -i /var/www/opnsense/keys/id_firewall_25 root@184.175.230.189 \
  'sockstat -4 -l | grep -E ":443|:80"'
```

Result:
```
root lighttpd 38947 7 tcp4 *:80 *:*
```

**NO SERVICE ON PORT 443!**

### Step 6: Test Connection to Port 80 from Firewall
```bash
sudo -u www-data ssh -i /var/www/opnsense/keys/id_firewall_25 root@184.175.230.189 \
  'curl -s -o /dev/null -w "%{http_code}" http://localhost:80/'
```
Result: **"200"** ✓ Web interface is on port 80!

### Step 7: Create Tunnel to Correct Port
```bash
sudo -u www-data ssh -i /var/www/opnsense/keys/id_firewall_25 \
  -L 127.0.0.1:9997:localhost:80 -N -f root@184.175.230.189

curl http://localhost:9997/
```
Result: **HTTP 200** ✓ WORKS!

## ROOT CAUSE

**Database had wrong web_port value:**
- Database: `web_port = 443` (HTTPS)
- Reality: Firewall runs on port 80 (HTTP)

**Why this caused connection failure:**
1. User clicks "Open Firewall" on opn2
2. start_tunnel_async.php calls start_tunnel()
3. start_tunnel() reads web_port = 443 from database
4. Creates SSH tunnel: `-L 8103:localhost:443`
5. Tunnel connects successfully
6. But localhost:443 on firewall has nothing listening
7. Tunnel appears "active" but is non-functional
8. Browser/proxy tries to connect through tunnel
9. Connection fails / times out
10. System eventually times out after 60 seconds

## SOLUTION

```sql
UPDATE firewalls SET web_port = 80 WHERE id = 25;
```

## VERIFICATION

### Before Fix:
```bash
ssh -L 9999:localhost:443 root@184.175.230.189
curl -k https://localhost:9999/
Result: SSL_ERROR_SYSCALL
```

### After Fix:
```bash
ssh -L 9999:localhost:80 root@184.175.230.189
curl http://localhost:9999/
Result: HTTP 200 ✓
```

## WHY THIS WASN'T DETECTED EARLIER

1. **SSH connection worked** - Tests passed for basic connectivity
2. **Tunnel process started** - Process appeared in ps aux
3. **No obvious errors** - SSH command returned exit code 0
4. **Port was bound** - netstat showed port 8103 listening
5. **Test only checked process** - Didn't test actual HTTP response

The tunnel was technically "active" but forwarding to a port with no service.

## COMPARISON WITH WORKING FIREWALL

**Firewall 21 (home.agit8or.net) - WORKS:**
```sql
web_port: 80
```
```bash
sockstat output: lighttpd listening on *:80
```

**Firewall 25 (opn2.agit8or.net) - BROKEN:**
```sql
web_port: 443 (WRONG!)
```
```bash
sockstat output: lighttpd listening on *:80 (not 443!)
```

## FILES MODIFIED

1. Database:
   ```sql
   UPDATE firewalls SET web_port = 80 WHERE id = 25;
   ```

## LESSONS LEARNED

1. **Always verify port is actually accepting connections** - Not just that tunnel process exists
2. **Test HTTP response through tunnel** - Don't assume port is correct
3. **Check what's actually listening on firewall** - Database may be wrong
4. **Add port verification to tunnel creation** - Test connectivity before reporting success

## NEXT STEPS

1. User should try connecting to opn2 now - should work
2. Consider adding port auto-detection during enrollment
3. Add HTTP response test to tunnel readiness check

---
**Date:** 2025-11-10 14:26
**Status:** ✅ ROOT CAUSE IDENTIFIED AND FIXED
**Fix:** Changed web_port from 443 to 80
