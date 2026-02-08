# OPNsense Agent Installation Guide

## Status Summary
✅ **Proxy Setup Fixed**: nginx write permissions resolved, reverse proxy working on port 8277  
❌ **Agent Check-in Issue**: Agent not checking in every 5 minutes (last seen 17 minutes ago)

## Required Action: Install Agent on Firewall

The agent needs to be installed on your actual OPNsense firewall (home.agit8or.net) to enable proper communication.

### Step 1: Download Agent Script to Firewall
```bash
# SSH to your OPNsense firewall
ssh root@home.agit8or.net

# Download the latest agent
fetch -o /tmp/opnsense_agent_v2.sh http://opn.agit8or.net/opnsense_agent_v2.sh.txt

# Make it executable
chmod +x /tmp/opnsense_agent_v2.sh
```

### Step 2: Install Agent
```bash
# Run the installer (it will set up the cron job automatically)
/tmp/opnsense_agent_v2.sh
```

### Step 3: Verify Installation
```bash
# Check if cron job was created
crontab -l | grep opnsense

# You should see:
# */5 * * * * /usr/local/bin/opnsense_agent_v2.sh >/dev/null 2>&1

# Manual test run
/usr/local/bin/opnsense_agent_v2.sh
```

### What the Agent Does
- **Check-in Every 5 Minutes**: Reports status and version to management server
- **Command Processing**: Executes firmware updates and other commands
- **Auto-Update**: Updates itself when new versions are available
- **Status Reporting**: Sends OPNsense version, agent version, and system status

### Current Setup Status
- ✅ Management server running at opn.agit8or.net  
- ✅ Database configured with firewall entry
- ✅ Reverse proxy configured on port 8277
- ✅ Agent v2.0 script available for download
- ✅ Command queue system operational
- ❌ Agent not installed on actual firewall

### Expected Behavior After Installation
1. Agent will check in every 5 minutes
2. Pending commands will be processed (firmware updates queued)
3. Connection interface will work through reverse proxy
4. Agent will auto-update if newer versions become available

### Troubleshooting
If agent doesn't work after installation:
```bash
# Check cron logs
tail -f /var/log/cron

# Manual test with verbose output
/usr/local/bin/opnsense_agent_v2.sh

# Check network connectivity
ping opn.agit8or.net
```

## Expected Results After Agent Installation

1. ✅ **Check-ins**: Every 5 minutes, visible in management panel
2. ✅ **Updates**: Clicking update will execute real firmware updates
3. ✅ **Auto-updates**: Agent will auto-update to newer versions
4. ✅ **Commands**: All queued commands will be processed

## Verification Commands

```bash
# Check agent check-ins
tail -f /var/log/opnsense_agent.log

# Verify cron job
crontab -l | grep opnsense_agent

# Test manual check-in
/usr/local/bin/opnsense_agent.sh version
/usr/local/bin/opnsense_agent.sh checkin
```

## Current Update Status
The update command queued at 03:13:24 will be executed as soon as the agent is installed and checks in.