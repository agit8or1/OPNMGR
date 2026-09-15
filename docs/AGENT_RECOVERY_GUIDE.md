# Agent Recovery Guide

## Overview

This document explains how to troubleshoot and recover agents that have stopped checking in to the OPNManager server.

## Problem: fw-chi-edge02.northwind.example Not Checking In

### Issue Details
- **Firewall:** fw-chi-edge02.northwind.example (ID: 48)
- **Last Check-In:** 2025-12-11 19:01:14 (68+ hours ago)
- **Agent Version:** 1.4.0 (older plugin-based agent)
- **Symptom:** Agent stopped checking in completely after December 11, 2025 at 7:01 PM

### Root Cause
The agent process on the firewall crashed or stopped running. The last check-in showed an empty WAN IP, suggesting a network configuration issue or agent crash at that time.

### Actions Taken

#### 1. Automatic Detection and Marking
- ✅ Firewall automatically marked as **OFFLINE** in database
- ✅ Status updated in dashboard to reflect offline state

#### 2. Emergency Command Queued
- ✅ Emergency reset command queued (Command ID: 1540)
- This command will execute **automatically if the agent comes back online**
- Command performs a complete agent reinstall with updated version

#### 3. SSH Reset Attempted
- ⚠️ SSH connection failed - authentication key not authorized
- Cannot remotely access firewall without proper SSH key

## Auto-Reset System (NEW)

### What Was Implemented

A comprehensive automatic monitoring and recovery system has been deployed:

#### 1. Monitoring Script: `/var/www/opnsense/scripts/auto_reset_stale_agents.php`

**Features:**
- Detects agents that haven't checked in for 2+ hours
- Marks agents as offline after 24+ hours of no check-in
- Queues emergency reset commands
- Attempts SSH-based agent restart
- Comprehensive logging to system logs

**Usage:**
```bash
# Run manually with verbose output
php /var/www/opnsense/scripts/auto_reset_stale_agents.php --verbose

# Dry-run mode (test without making changes)
php /var/www/opnsense/scripts/auto_reset_stale_agents.php --dry-run --verbose

# Force check specific firewall
php /var/www/opnsense/scripts/auto_reset_stale_agents.php --force-firewall-id=48

# Custom threshold (default: 2 hours)
php /var/www/opnsense/scripts/auto_reset_stale_agents.php --threshold=6
```

#### 2. Automated Cron Job

**Schedule:** Every hour at the top of the hour
**Cron Entry:**
```
0 * * * * /usr/bin/php /var/www/opnsense/scripts/auto_reset_stale_agents.php >> /var/log/auto_reset_agents.log 2>&1
```

**Log Location:** `/var/log/auto_reset_agents.log`

#### 3. Web Interface: `/admin/reset_agent.php`

**Features:**
- View all firewalls with their check-in status
- Color-coded status indicators:
  - 🔴 Red: 24+ hours stale (critical)
  - 🟡 Yellow: 2-24 hours stale (warning)
  - 🟢 Green: < 2 hours (healthy)
- Manual "Reset" button for each firewall
- Real-time output display of reset operations

**Access:** Log in to OPNManager and navigate to `/admin/reset_agent.php`

## Manual Recovery Options

### Option 1: Wait for Automatic Recovery
If the agent spontaneously restarts (e.g., after firewall reboot), the queued emergency command will execute automatically and reinstall the agent.

**Timeline:** Automatic check runs every hour

### Option 2: Use Web Interface
1. Log in to OPNManager
2. Navigate to `/admin/reset_agent.php`
3. Find fw-chi-edge02.northwind.example in the list
4. Click the "Reset" button
5. Monitor the output for success/errors

### Option 3: Run CLI Script
```bash
# Run the auto-reset script targeting fw-chi-edge02.northwind.example specifically
php /var/www/opnsense/scripts/auto_reset_stale_agents.php --force-firewall-id=48 --verbose
```

### Option 4: Physical/Console Access Required

If SSH fails and the agent doesn't come back online, you'll need physical or console access to the firewall:

#### Steps via Console:
1. **Access OPNsense Console** (physical access or remote console)
2. **Select option 8** (Shell)
3. **Download and run the reinstall script:**
   ```bash
   fetch -o /tmp/reinstall_agent.sh \
     "https://your-opnmgr-server/reinstall_agent.php?firewall_id=48"
   chmod +x /tmp/reinstall_agent.sh
   sh /tmp/reinstall_agent.sh
   ```

4. **Verify agent is running:**
   ```bash
   ps aux | grep tunnel_agent
   tail -f /tmp/agent.log
   ```

5. **Fix SSH access (optional but recommended):**
   ```bash
   # Add the server's public key to authorized_keys
   mkdir -p ~/.ssh
   fetch -o ~/.ssh/authorized_keys \
     "https://your-opnmgr-server/admin/get_ssh_public_key.php"
   chmod 700 ~/.ssh
   chmod 600 ~/.ssh/authorized_keys
   ```

## Monitoring and Verification

### Check System Logs
```bash
# View auto-reset logs
tail -f /var/log/auto_reset_agents.log

# View database logs
mysql -u opnsense_user -p opnsense_fw -e \
  "SELECT * FROM system_logs WHERE category='auto_reset' ORDER BY timestamp DESC LIMIT 20;"
```

### Check Agent Status in Database
```bash
mysql -u opnsense_user -p opnsense_fw -e \
  "SELECT id, hostname, status, last_checkin,
          TIMESTAMPDIFF(HOUR, last_checkin, NOW()) as hours_stale
   FROM firewalls
   WHERE id = 48;"
```

### View Queued Commands
```bash
mysql -u opnsense_user -p opnsense_fw -e \
  "SELECT * FROM firewall_commands
   WHERE firewall_id = 48 AND status IN ('pending', 'queued')
   ORDER BY created_at DESC;"
```

## Prevention Measures Implemented

### 1. Proactive Monitoring
- Hourly automated checks for stale agents
- Early warning at 2 hours (before 24-hour offline threshold)
- Automatic database status updates

### 2. Self-Healing
- Automatic SSH-based restart attempts
- Emergency command queuing for when agent returns
- Graceful degradation if SSH fails

### 3. Visibility
- Web dashboard showing real-time status
- Comprehensive logging of all reset attempts
- Color-coded warnings in admin interface

### 4. Manual Override
- CLI tools for direct intervention
- Web interface for non-technical users
- Detailed documentation and recovery procedures

## Future Improvements

Consider implementing:

1. **Email/Slack Notifications** - Alert administrators when agents go stale
2. **SSH Key Management** - Automated SSH key distribution and rotation
3. **Agent Health Checks** - Ping/connectivity tests before declaring offline
4. **Graduated Responses** - Gentler restarts before nuclear reset
5. **Historical Analytics** - Track agent reliability over time

## Summary

The auto-reset system provides **three layers of protection**:

1. **Automatic hourly monitoring** (cron job)
2. **Web-based manual intervention** (admin interface)
3. **Command-line tools** (for advanced users)

For fw-chi-edge02.northwind.example specifically:
- ✅ System is monitoring and will auto-recover if possible
- ✅ Emergency command queued for automatic execution
- ⚠️ SSH access needs to be restored for remote management
- 📝 Physical access may be required if agent doesn't recover

## Contact

For issues or questions:
- Check logs: `/var/log/auto_reset_agents.log`
- Review system logs in database: `system_logs` table
- Web interface: `https://your-opnmgr-server/admin/reset_agent.php`
