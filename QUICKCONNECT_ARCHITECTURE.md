# QuickConnect Architecture
**On-Demand Remote Access to OPNsense Firewalls**

## Overview
QuickConnect provides secure, on-demand access to firewall web GUIs without permanent SSH rules or reverse tunnels.

## How It Works

### 1. Request Initiation (Manager Side)
User clicks "Connect" in the web GUI, which:
- Inserts a record into `proxy_requests` table with status='pending'
- Specifies local_port (443 on firewall) and remote_port (8443 on manager)

### 2. Agent Detection (Firewall Side)
The **quickconnect_wrapper.sh** runs every minute via cron:
- Checks for pending proxy_requests for its firewall_id
- If found, triggers connection setup

### 3. Firewall Opens SSH Rule
Agent on firewall executes:
```bash
# Add temporary firewall rule allowing SSH from manager IP
echo "pass in quick on igc0 inet proto tcp from 184.175.206.229 to any port 22" | pfctl -a opnsense -F rules -f -
```

**CRITICAL**: The anchor must be invoked in the main ruleset, OR the rule must be added through OPNsense's config system.

### 4. Firewall Initiates Connection
Agent on firewall runs:
```bash
# Connect TO manager and create LOCAL port forward
# This forwards manager:8443 -> firewall:443
ssh -f -N -R 8443:localhost:443 root@opn.agit8or.net
```

**Direction**: Firewall connects TO manager (outbound connection always allowed)
**Result**: Port 8443 on manager now forwards to port 443 on firewall

### 5. Manager Opens GUI
Once tunnel established:
- Update proxy_requests status to 'active'
- Manager opens browser to https://localhost:8443
- User sees firewall web GUI

### 6. Cleanup
When done:
- User clicks "Disconnect" 
- Agent kills SSH tunnel
- Agent removes temporary firewall rule
- Update proxy_requests status to 'completed'

## Why This Design?

### ✅ Advantages
1. **Outbound Only**: Firewall initiates connection (always allowed)
2. **No Permanent Rules**: SSH rule added/removed on demand
3. **No Exposed Ports**: Manager doesn't need inbound SSH access
4. **Auditable**: All connections logged in proxy_requests table
5. **Secure**: Uses SSH key auth, temporary rules

### ❌ What We DON'T Do
- ❌ Reverse tunnels FROM manager TO firewall (requires inbound SSH)
- ❌ Permanent SSH firewall rules (security risk)
- ❌ Direct SSH from manager (requires exposed SSH port)

## Components

### Files
- `/usr/local/bin/quickconnect_wrapper.sh` - Main agent (runs via cron)
- `/usr/local/bin/quickconnect_agent.sh` - Helper script (if exists)
- `/root/.ssh/quickconnect_key` - SSH key for tunnel auth

### Database Tables
- `proxy_requests` - Connection requests and status
  - firewall_id, local_port, remote_port, status, created_at, completed_at
  - Status: pending → active → completed

### Cron
```
* * * * * /usr/local/bin/quickconnect_wrapper.sh
```

## Current Issue (2025-10-14)

### Problem
Firewall rule added to anchor `opnsense` but anchor NOT invoked in main ruleset:
```bash
pfctl -sr | grep anchor  # Returns nothing!
```

The anchor exists and has rules:
```bash
pfctl -sr -a opnsense  # Shows the SSH rule
```

But without `anchor "opnsense"` directive in main ruleset, rules never evaluated.

### Attempted Fixes
1. ✅ Added rule to anchor - SUCCESS
2. ✅ Reloaded firewall config - SUCCESS
3. ❌ SSH connection still times out - anchor not invoked
4. ❌ Need to add rule via OPNsense config system

### Next Steps
1. Add anchor invocation to main ruleset, OR
2. Add rule directly to OPNsense config (persistent), OR  
3. Use alternative: firewall initiates tunnel WITHOUT needing inbound SSH rule

## Alternative Approach (If Anchor Won't Work)

Since the firewall can initiate OUTBOUND connections without any rules:

### Skip the SSH Rule Entirely
Instead of trying to SSH FROM manager TO firewall:
1. Firewall agent detects pending request
2. Firewall runs: `ssh -f -N -R 8443:localhost:443 root@opn.agit8or.net`
3. Manager now has localhost:8443 → firewall:443
4. Done! No firewall rule needed.

**This is the original design and should work!**

## Testing QuickConnect

### 1. Check if agent exists
```bash
ls -la /usr/local/bin/quickconnect*
```

### 2. Check if cron is running it
```bash
crontab -l | grep quickconnect
```

### 3. Manually test
```bash
/usr/local/bin/quickconnect_wrapper.sh
```

### 4. Create test request
```sql
INSERT INTO proxy_requests (firewall_id, local_port, remote_port, status, created_at)
VALUES (21, 443, 8443, 'pending', NOW());
```

### 5. Check status
```sql
SELECT * FROM proxy_requests WHERE firewall_id=21 ORDER BY created_at DESC LIMIT 5;
```

## Security Notes

- SSH keys should be unique per firewall
- Tunnels should timeout after X minutes
- Failed connection attempts should be logged
- Manager should validate firewall_id before allowing connections

---

**Last Updated**: 2025-10-14  
**Status**: Investigating anchor invocation issue, may switch to pure outbound approach
