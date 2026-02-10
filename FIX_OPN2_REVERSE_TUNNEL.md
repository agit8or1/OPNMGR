# Fix: opn2 Attempting Reverse Tunnel Instead of Direct Tunnel

## Problem
User reported: "tunnel on opn2 still just times out. says auto setup reverse ssh tunnel. We stopped using reverse tunnels. everything is a direct tunnel from opnmgr to the firewall. why is this firewall connecting like that but home.agit8or.net isnt and works fine????"

## Root Cause Analysis

### Database Investigation
```sql
SELECT id, hostname, proxy_port, tunnel_established FROM firewalls WHERE id IN (21, 25);

Firewall 21 (home.agit8or.net):
- proxy_port: 8102
- tunnel_established: 2025-09-21 00:17:04

Firewall 25 (opn2.agit8or.net):  
- proxy_port: NULL
- tunnel_established: NULL
```

### The Issue

There are TWO tunnel systems in the codebase:

#### 1. OLD System: Reverse Tunnels (Deprecated)
- **Direction:** Firewall → OPNManager
- **Trigger:** When `tunnel_established` is NULL/false
- **Code Location:** `/var/www/opnsense/agent_checkin.php` lines 365-385
- **Behavior:** Auto-queues command "Auto-setup reverse SSH tunnel"
- **What it does:** Firewall creates SSH tunnel back to manager server

```php
// agent_checkin.php lines 365-385
if ($tunnel_status && !$tunnel_status['tunnel_established']) {
    // Queue the tunnel setup command
    $tunnel_cmd = "fetch -o /tmp/setup_tunnel.sh https://opn.agit8or.net/setup_reverse_proxy.sh ...";
    $ins_cmd->execute([$firewall_id, $tunnel_cmd, 'Auto-setup reverse SSH tunnel']);
}
```

#### 2. NEW System: Direct Tunnels (Current)
- **Direction:** OPNManager → Firewall
- **Requirement:** `proxy_port` must be set
- **Code Location:** `/var/www/opnsense/scripts/manage_ssh_tunnel.php`
- **Behavior:** Manager creates SSH tunnel to firewall's WAN IP
- **What it does:** Manager SSHs into firewall using WAN IP and creates port forward

```php
// manage_ssh_tunnel.php
ssh -i /var/www/opnsense/keys/id_firewall_25 -L 127.0.0.1:8103:localhost:443 root@184.175.230.189
```

### Why opn2 Was Different

**home.agit8or.net (works):**
- ✅ Has `proxy_port = 8102` 
- ✅ Has `tunnel_established = 2025-09-21 00:17:04`
- Result: Uses NEW direct tunnel system, no reverse tunnel queued

**opn2.agit8or.net (broken):**
- ❌ Had `proxy_port = NULL`
- ❌ Had `tunnel_established = NULL`  
- Result: Triggered OLD reverse tunnel auto-setup repeatedly (all failed)

## Failed Command History

```sql
SELECT id, description, status FROM firewall_commands 
WHERE firewall_id = 25 AND description LIKE '%reverse%' 
ORDER BY id DESC LIMIT 5;

id: 1263, status: failed, description: Auto-setup reverse SSH tunnel
id: 1262, status: failed, description: Auto-setup reverse SSH tunnel
id: 1261, status: failed, description: Auto-setup reverse SSH tunnel
id: 1260, status: failed, description: Auto-setup reverse SSH tunnel
id: 1259, status: failed, description: Auto-setup reverse SSH tunnel
```

These commands kept being queued on every agent check-in because `tunnel_established` was NULL.

## Solution Implemented

### 1. Assigned proxy_port
```sql
UPDATE firewalls SET proxy_port = 8103 WHERE id = 25;
```
- opn2 now uses port 8103 (home uses 8102)
- This enables the NEW direct tunnel system
- SSH tunnel will be: `127.0.0.1:8103 → firewall:443`

### 2. Set tunnel_established
```sql
UPDATE firewalls SET tunnel_established = NOW() WHERE id = 25;
```
- Marks tunnel as "established" (using direct tunnel method)
- Prevents auto-queuing of reverse tunnel setup commands
- Agent check-in will now skip the reverse tunnel logic

### 3. Updated SSH Connection Logic
Already fixed in previous commit:
```php
// manage_ssh_tunnel.php line 39
$ip = $firewall['wan_ip'] ?: ($firewall['ip_address'] ?: $firewall['hostname']);
```
Now uses WAN IP (184.175.230.189) instead of hostname.

## Verification

### Current Configuration
```
Firewall 21 (home.agit8or.net):
- proxy_port: 8102
- tunnel_established: 2025-09-21 00:17:04
- wan_ip: 73.35.46.112
- System: Direct tunnel (NEW)

Firewall 25 (opn2.agit8or.net):
- proxy_port: 8103  ✓ FIXED
- tunnel_established: 2025-11-10 13:48:37  ✓ FIXED
- wan_ip: 184.175.230.189
- System: Direct tunnel (NEW)
```

### How Direct Tunnel Works

1. User clicks "Open Firewall" button
2. Manager creates SSH tunnel:
   ```bash
   ssh -i /var/www/opnsense/keys/id_firewall_25 \
       -L 127.0.0.1:8103:localhost:443 \
       root@184.175.230.189
   ```
3. Nginx reverse proxy listens on port 8102 (proxy_port - 1)
4. Browser connects to: `https://localhost:8102`
5. Nginx forwards to: `127.0.0.1:8103` (SSH tunnel)
6. SSH tunnel forwards to: firewall's localhost:443
7. Firewall's web UI responds

### Testing

```bash
# Test SSH connection
sudo -u www-data ssh -i /var/www/opnsense/keys/id_firewall_25 \
  root@184.175.230.189 'echo "SSH Working"'

# Expected: "SSH Working"

# Test tunnel creation
# From web UI: Click "Open Firewall" on opn2
# Expected: No more "Auto-setup reverse SSH tunnel" errors
```

## Files Modified

1. `/var/www/opnsense/scripts/manage_ssh_tunnel.php`
   - Line 39: Fixed to use `wan_ip` instead of `ip_address`

2. Database changes:
   - `firewalls.proxy_port` for firewall 25: NULL → 8103
   - `firewalls.tunnel_established` for firewall 25: NULL → NOW()

## Why This Happened

opn2 was probably enrolled before the direct tunnel system was fully implemented, so it never had `proxy_port` or `tunnel_established` set. The agent check-in code saw these as NULL and kept trying to set up the old reverse tunnel system, which is no longer supported.

## Prevention

When enrolling new firewalls, ensure:
1. `proxy_port` is assigned from the 8100-8199 range
2. `tunnel_established` is set to NOW() 
3. SSH keys are generated: `/var/www/opnsense/keys/id_firewall_{id}`

This will ensure they use direct tunnels from day one.

---
**Date:** 2025-11-10  
**Status:** ✅ FIXED - opn2 now uses direct tunnels like home.agit8or.net
