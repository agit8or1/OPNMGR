# Manual Update Agent Deployment Guide

If automatic deployment via SSH fails, use this manual procedure to deploy the update agent.

## For Firewall #21 (home.agit8or.net)

### Step 1: Access Firewall Console
- Log into OPNsense web interface
- Go to **System > Firmware > Settings** or use SSH/console access

### Step 2: Download Update Agent
```bash
# Download the update agent from management server
curl -k -o /tmp/opnsense_update_agent.sh 'https://opn.agit8or.net/download_update_agent.php?firewall_id=21'

# Verify download
ls -lh /tmp/opnsense_update_agent.sh
```

### Step 3: Install Update Agent
```bash
# Install to /usr/local/bin/
mv /tmp/opnsense_update_agent.sh /usr/local/bin/opnsense_update_agent.sh
chmod +x /usr/local/bin/opnsense_update_agent.sh
```

### Step 4: Start Update Agent
```bash
# Start the update agent in background
nohup /usr/local/bin/opnsense_update_agent.sh > /dev/null 2>&1 &

# Get the PID
echo $!
```

### Step 5: Verify Both Agents Running
```bash
# Check processes
ps aux | grep opnsense_.*agent.sh | grep -v grep

# Should see TWO processes:
# - opnsense_agent.sh (primary)
# - opnsense_update_agent.sh (update/failsafe)
```

### Expected Output:
```
root  12345  0.0  0.1  /usr/local/bin/opnsense_agent.sh
root  12346  0.0  0.1  /usr/local/bin/opnsense_update_agent.sh
```

## Verification in Management Interface

After deployment, verify in the management web interface:

1. **Check Agent Status**:
   ```bash
   php /var/www/opnsense/check_dual_agents.php
   ```
   - Should show: `FW #21 (home.agit8or.net): ✅ OK`
   - Both primary and update agents should be listed

2. **Check Database**:
   ```sql
   SELECT agent_type, agent_version, last_checkin
   FROM firewall_agents 
   WHERE firewall_id = 21 
   ORDER BY agent_type;
   ```
   - Should show both `primary` and `update` entries

3. **Monitor Check-ins**:
   ```bash
   tail -f /var/www/opnsense/logs/agent_checkins.log | grep "FW #21"
   ```
   - Primary checks in every ~120 seconds
   - Update checks in every ~300 seconds (5 minutes)

## Troubleshooting

### Update Agent Not Starting
```bash
# Check for errors
/usr/local/bin/opnsense_update_agent.sh

# Verify file permissions
ls -l /usr/local/bin/opnsense_update_agent.sh

# Should be: -rwxr-xr-x root wheel
```

### Update Agent Exits Immediately
```bash
# Check if primary agent is running
ps aux | grep opnsense_agent.sh

# Update agent will exit if primary is dead (so it can restart it)
```

### Both Agents Not Visible in Management Interface
```bash
# Wait 5-10 minutes for initial check-ins
# Check firewall can reach management server:
curl -k -I https://opn.agit8or.net/agent_checkin.php
```

## Emergency Recovery

If you lose access to the firewall after update:

1. **Update agent will auto-recover** within ~8 minutes:
   - Detects dead primary agent
   - Downloads new primary agent
   - Installs and restarts primary agent
   - Management restored

2. **Manual recovery**:
   ```bash
   # Download fresh primary agent
   curl -k -o /usr/local/bin/opnsense_agent.sh \
     'https://opn.agit8or.net/download_tunnel_agent.php?firewall_id=21'
   
   # Make executable
   chmod +x /usr/local/bin/opnsense_agent.sh
   
   # Start it
   nohup /usr/local/bin/opnsense_agent.sh > /dev/null 2>&1 &
   ```

## Notes

- **Primary agent**: Full functionality, processes all commands, can be updated
- **Update agent**: Minimal code, only processes `is_update_command=1`, **never updates itself**
- **Check-in intervals**: Primary=120s, Update=300s
- **Agent types**: Must check in with `agent_type` parameter (`primary` or `update`)

## See Also
- `/var/www/opnsense/DUAL_AGENT_SYSTEM.md` - Full architecture documentation
- `/var/www/opnsense/deploy_dual_agent.sh` - Automated deployment script
- `/var/www/opnsense/check_dual_agents.php` - Verification tool
