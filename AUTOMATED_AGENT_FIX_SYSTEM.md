# Automated Agent Fix System

## Overview

This system allows the OPNManager server to automatically fix/update agents on firewalls.

## Components Created

### 1. SSH Key-Based Trust
- **Location**: `/var/www/opnsense/keys/id_firewall_<ID>`
- **Purpose**: Passwordless SSH from server to firewalls
- **Status**: Keys exist, but firewalls must allow inbound SSH for this to work

### 2. Fix Scripts

#### A. `/var/www/opnsense/scripts/fix_agent_via_ssh.sh`
- **Method**: Direct SSH to firewall WAN IP
- **Requires**: Firewall allows SSH from server's IP
- **Usage**: `sudo -u www-data /var/www/opnsense/scripts/fix_agent_via_ssh.sh <firewall_id>`
- **Best for**: Firewalls with open SSH access

#### B. `/var/www/opnsense/scripts/fix_agent_web_ui.php`
- **Method**: Uses existing SSH tunnel + queued commands
- **Requires**: Agent must be checking in to receive commands
- **Usage**: `/var/www/opnsense/scripts/fix_agent_web_ui.php <firewall_id>`
- **Best for**: When agent is running but needs update

### 3. Auto-Update System (Already Working)
- **Location**: `/var/www/opnsense/agent_checkin.php` - `checkAgentUpdate()` function
- **How**: Server detects outdated agent during check-in, sends update command
- **Status**: ✅ Fully functional
- **Limitation**: Requires agent to be checking in

## Current Issue: The Chicken-and-Egg Problem

### Problem:
- **Agent is dead** → Can't check in → Can't receive commands → Can't update itself
- **Firewalls behind NAT** → Server can't SSH in directly → Can't manually fix agent

### Solutions:

#### Option 1: SSH Access for Automation (RECOMMENDED)
Configure firewalls to allow SSH from server:

**On each firewall:**
1. Add server's SSH public key to `/root/.ssh/authorized_keys`
2. Allow SSH from server IP in firewall rules
3. Server can then SSH in and fix agents automatically

**Server public key:**
```
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOSF41zYTRe76rGOj6Q21S2UJPGMaQy2Fx2RfEDYShkU firewall-21-auto
```

#### Option 2: Agent-Initiated Reverse SSH Tunnel
Have agents create reverse SSH tunnels for management:

**In agent v3.9.0 (future):**
- Agent creates reverse SSH tunnel: `-R 2222:localhost:22`
- Server can SSH to localhost:2222 to reach firewall
- Survives NAT, no firewall rule changes needed

#### Option 3: Manual Web UI (Current Workaround)
Until automation is setup:
1. Log into firewall web UI
2. Go to System → Diagnostics → Command Prompt
3. Paste fix command

## How Auto-Fix Will Work (When SSH Access Enabled)

1. **Monitoring**: System detects agent offline (31+ minutes)
2. **Automated Fix**: Cron job runs every 5 minutes:
   ```bash
   */5 * * * * /var/www/opnsense/scripts/check_and_fix_dead_agents.sh
   ```
3. **Process**:
   - SSH into firewall
   - Kill old agent
   - Download latest agent
   - Install and start
   - Verify check-in
4. **Notification**: Log result + alert if fails

## Manual Fix Commands

### For home.agit8or.net (73.35.46.112):
```bash
# SSH into firewall as root, then:
fetch -q -o /usr/local/bin/tunnel_agent.sh https://opn.agit8or.net/download_tunnel_agent.php?firewall_id=21 && \
chmod +x /usr/local/bin/tunnel_agent.sh && \
(crontab -l 2>/dev/null | grep -v tunnel_agent; echo "*/2 * * * * /usr/local/bin/tunnel_agent.sh") | crontab - && \
nohup /usr/local/bin/tunnel_agent.sh > /tmp/agent_start.log 2>&1 &
```

### For opn2.agit8or.net:
```bash
# Use enrollment command from Add Firewall page
# It now includes agent installation automatically
```

## Current Status

| Component | Status | Notes |
|-----------|--------|-------|
| Auto-update system | ✅ Working | Agent v3.8.0 ready to deploy |
| Network config detection | ✅ Working | Fixes LAN network data issue |
| SSH keys | ✅ Created | One per firewall in `/var/www/opnsense/keys/` |
| Direct SSH access | ❌ Blocked | Firewalls behind NAT/firewall rules |
| Fix via tunnels | ⚠️ Partial | Requires agent to be alive |
| Reverse SSH tunnels | 📝 Planned | Agent v3.9.0 feature |

## Next Steps

1. **Short-term**: Manually fix home.agit8or.net agent (see commands above)
2. **Mid-term**: Configure SSH access from server to firewalls
3. **Long-term**: Implement agent-initiated reverse SSH tunnels (v3.9.0)

## Files Modified/Created

- `/var/www/opnsense/download_tunnel_agent.php` - Now serves v3.8.0
- `/var/www/opnsense/downloads/opnsense_agent_v3.8.0.sh` - New agent with network config
- `/var/www/opnsense/downloads/update_agent.sh` - Safe update script
- `/var/www/opnsense/scripts/fix_agent_via_ssh.sh` - Direct SSH fix
- `/var/www/opnsense/scripts/fix_agent_web_ui.php` - Tunnel-based fix
- `/var/www/opnsense/agent_checkin.php` - Updated to offer v3.8.0
