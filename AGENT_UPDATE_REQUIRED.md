# 🤖 Agent Update Required - Installation Instructions

## ⚠️ **YES, You Need to Update the Agent**

The current agent on your firewall needs to be updated to support:

1. **Command Processing** - Handle firmware update commands from management server
2. **Improved Version Reporting** - Send proper JSON version data for accurate tracking  
3. **Command Result Reporting** - Report back execution results to management server

## 📥 **Agent Installation Steps**

### 1. Download Updated Agent to Firewall

```bash
# SSH to your OPNsense firewall (10.0.0.1)
ssh root@10.0.0.1

# Download the new agent script
curl -k -o /usr/local/bin/opnsense_agent.sh https://opn.agit8or.net/opnsense_agent_v2.sh

# Make it executable
chmod +x /usr/local/bin/opnsense_agent.sh
```

### 2. Install/Update Agent Service

```bash
# Stop any existing agent
killall opnsense_agent.sh 2>/dev/null

# Remove old cron jobs
crontab -l | grep -v opnsense_agent | crontab -

# Set up new cron job (every 5 minutes)
echo "*/5 * * * * /usr/local/bin/opnsense_agent.sh" | crontab -

# Run agent immediately to test
/usr/local/bin/opnsense_agent.sh
```

### 3. Verify Installation

```bash
# Check if agent is running
tail -f /var/log/opnsense_agent.log

# Check cron job is set
crontab -l
```

You should see output like:
```
2025-09-12 15:30:15 - Starting agent checkin...
2025-09-12 15:30:16 - Checkin successful: {"success":true,"message":"Check-in successful"...}
2025-09-12 15:30:16 - Next checkin in 300 seconds
```

## 🔧 **What the New Agent Does**

### Enhanced Features:
- **Real Version Detection**: Extracts actual OPNsense version from system
- **Command Processing**: Handles update commands from management server
- **Result Reporting**: Reports command execution results back
- **Error Handling**: Better logging and error recovery
- **JSON Communication**: Structured data exchange with server

### Command Support:
- `firmware_update` - Executes real OPNsense firmware updates
- `api_test` - Tests local API connectivity
- More commands can be added easily

### Version Reporting:
```json
{
    "product_version": "25.7.2",
    "firmware_version": "25.7.2-amd64",
    "system_version": "14.1-RELEASE-p3",
    "architecture": "amd64",
    "last_updated": "2025-09-12T15:30:15Z"
}
```

## 🚀 **After Agent Update**

### Test the System:
1. **Check Version Reporting**: Should show accurate 25.7.2 in management interface
2. **Test Update Function**: Click update button - should queue real firmware update
3. **Monitor Logs**: Watch `/var/log/opnsense_agent.log` for activity

### Expected Behavior:
- **Version Accurate**: Current version shows 25.7.2, available 25.7.3
- **Updates Work**: Update button triggers real firmware update on firewall
- **Status Updates**: Agent reports new version after successful update

## 📋 **Installation Verification**

### On Management Server:
```bash
# Check if agent is checking in
mysql -u opnsense_user -p'password' opnsense_fw -e "
SELECT id, hostname, current_version, last_checkin 
FROM firewalls 
WHERE hostname = 'home.agit8or.net';"
```

### Expected Result:
- `last_checkin` should be recent (within 5 minutes)
- `current_version` should show "25.7.2" 
- Updates should be detected properly

## ⚡ **Quick Install Command**

Run this single command on your OPNsense firewall:

```bash
curl -k https://opn.agit8or.net/opnsense_agent_v2.sh | sh -s install
```

This will download, install, and configure the new agent automatically.

---

**Bottom Line**: Yes, update the agent for proper update functionality and accurate version reporting! 🎯