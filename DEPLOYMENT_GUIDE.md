# Agent v2.5.0 Deployment Guide

## Overview
Agent v2.5.0 adds on-demand reverse SSH tunnel support for secure firewall access without dedicated ports.

## Prerequisites
- Firewall must have internet access
- Current agent v2.4.0 should be running
- Root access to firewall

## Deployment Steps

### Step 1: Download New Agent
On the firewall, run:
```bash
curl -k -H "X-Agent-Key: opnsense_agent_2024_secure" \
  "https://opn.agit8or.net/download_agent.php?version=2.5.0&firewall_id=21" \
  -o /usr/local/bin/opnsense_agent_v2.5.0.sh

chmod +x /usr/local/bin/opnsense_agent_v2.5.0.sh
```

### Step 2: Generate SSH Key
```bash
ssh-keygen -t ed25519 -f /root/.ssh/opnsense_proxy_key -N ""
```

### Step 3: Register Public Key
```bash
PUBLIC_KEY=$(cat /root/.ssh/opnsense_proxy_key.pub)

curl -k -X POST \
  -H "X-Agent-Key: opnsense_agent_2024_secure" \
  -d "firewall_id=21" \
  -d "public_key=$PUBLIC_KEY" \
  https://opn.agit8or.net/add_ssh_key.php
```

### Step 4: Stop Old Agent
```bash
# If running from cron
crontab -r

# If running as daemon, kill the process
pkill -f opnsense_agent
```

### Step 5: Start New Agent
```bash
# Start in foreground for testing
/usr/local/bin/opnsense_agent_v2.5.0.sh

# Or start in background
nohup /usr/local/bin/opnsense_agent_v2.5.0.sh > /var/log/opnsense_agent.log 2>&1 &
```

### Step 6: Verify Operation
Check logs:
```bash
tail -f /var/log/opnsense_agent.log
```

Expected output:
```
[INFO] Agent v2.5.0 starting in daemon mode
[INFO] Lockfile acquired
[INFO] Check-in successful
[INFO] Checking for proxy requests...
```

### Step 7: Test Connection
1. Go to https://opn.agit8or.net/firewall_details.php?id=21
2. Click "Connect" button
3. Should see "Establishing Secure Connection" page
4. After ~5 seconds, firewall web interface should load

## Troubleshooting

### Agent not starting
```bash
# Check for lockfile conflicts
ls -la /var/run/opnsense_agent.lock

# Remove if stale
rm /var/run/opnsense_agent.lock
```

### SSH tunnel failing
```bash
# Test SSH connection manually
ssh -i /root/.ssh/opnsense_proxy_key proxy@opn.agit8or.net

# Check authorized_keys on management server
ssh root@opn.agit8or.net "cat /home/proxy/.ssh/authorized_keys"
```

### Proxy requests not processed
```bash
# Check if agent is polling
grep "Checking for proxy" /var/log/opnsense_agent.log

# Verify database connectivity
curl -k -X POST -H "X-Agent-Key: opnsense_agent_2024_secure" \
  -H "Content-Type: application/json" \
  -d '{"firewall_id":21,"agent_version":"2.5.0"}' \
  https://opn.agit8or.net/agent_proxy_check.php
```

## Rollback
If issues occur, rollback to v2.4.0:
```bash
# Stop v2.5.0
pkill -f opnsense_agent_v2.5.0

# Restore cron-based v2.4.0
echo "*/5 * * * * /usr/local/bin/opnsense_agent.sh" | crontab -
```
