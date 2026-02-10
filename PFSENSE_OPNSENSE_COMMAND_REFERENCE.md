# pfSense/OPNsense Command Reference
**Verified Commands and Syntax**

## Firewall Rule Management

### Add Temporary Rule (Lost on Reboot)
```bash
# Add rule to anchor (no variable expansion needed)
echo "pass in quick proto tcp from 1.2.3.4 to any port 22" | pfctl -a opnsense -f -

# Add rule with interface specified
echo "pass in quick on igc0 proto tcp from 1.2.3.4 to any port 22" | pfctl -a opnsense -f -
```

### View Rules in Anchor
```bash
# List rules in anchor
pfctl -sr -a opnsense

# List all anchors
pfctl -sA
```

### Add Persistent Rule via PHP CLI
```bash
# Use OPNsense's PHP CLI to add permanent rule
configctl filter reload
```

### Common Anchors
- `opnsense` - Main rules
- `opnsense/rules` - User rules (may not work on all versions)
- Root anchor `/` - System rules

## Interface Information

### List All Interfaces
```bash
ifconfig | grep -E "^[a-z]+"
```

### Common Interface Names
- **igc0, igc1, igc2, igc3** - Intel Gigabit NIC (modern)
- **em0, em1** - Intel Ethernet (older)
- **igb0, igb1** - Intel Gigabit (older)
- **vtnet0** - VirtIO (VMs)
- **lo0** - Loopback

### Get WAN Interface
```bash
# Method 1: Check config
cat /conf/config.xml | grep -A 5 "<wan>"

# Method 2: Check routing
netstat -rn | grep default | awk '{print $NF}'

# Method 3: OPNsense API
configctl interface list wan
```

## SSH Service Management

### Check SSH Status
```bash
service openssh status
```

### Start/Stop SSH
```bash
/usr/local/etc/rc.d/openssh start
/usr/local/etc/rc.d/openssh stop
/usr/local/etc/rc.d/openssh restart
```

### Check SSH Configuration
```bash
cat /etc/ssh/sshd_config | grep -E "^(Port|PermitRootLogin|PasswordAuthentication)"
```

## System Information

### Get OPNsense Version
```bash
# Method 1: Command (most reliable)
opnsense-version

# Method 2: Version files
cat /usr/local/opnsense/version/core
cat /usr/local/opnsense/version/core.name
cat /usr/local/opnsense/version/core.series

# Method 3: Package info
pkg info opnsense | grep Version
```

### Get System Info
```bash
# Hostname
hostname

# Uptime
uptime

# FreeBSD version
uname -r

# Hardware info
sysctl hw.model hw.ncpu hw.physmem
```

## Configuration Management

### Backup Configuration
```bash
# Create XML backup
cp /conf/config.xml /root/config-backup-$(date +%Y%m%d).xml

# Or use OPNsense tool
/usr/local/etc/rc.backup.sh
```

### Reload Configuration
```bash
# Reload all services
/usr/local/etc/rc.reload_all

# Reload specific services
configctl filter reload
configctl webgui restart
```

## Package Management

### List Installed Packages
```bash
pkg info | grep opnsense
```

### Update System
```bash
# Full system update (OPNsense)
/usr/local/sbin/opnsense-update -bkf

# FreeBSD package update
pkg update && pkg upgrade -y
```

## Network Diagnostics

### Test Connectivity
```bash
# Ping
ping -c 4 8.8.8.8

# Trace route
traceroute 8.8.8.8

# DNS lookup
host google.com
dig google.com

# Check listening ports
sockstat -4 -l | grep :22
```

### Check Active Connections
```bash
# All connections
sockstat -4

# SSH connections
sockstat -4 | grep :22
```

## Process Management

### List Processes
```bash
# All processes
ps aux

# Find specific process
ps aux | grep opnsense_agent

# Kill process by pattern
pkill -f "opnsense_agent"
pkill -9 -f "opnsense_agent"  # Force kill
```

## Cron Management

### View Crontab
```bash
crontab -l
```

### Edit Crontab
```bash
crontab -e
```

### Set Crontab from Script
```bash
# WRONG - This overwrites entire crontab:
echo "*/2 * * * * /path/to/script" | crontab -

# RIGHT - Add to existing crontab:
(crontab -l 2>/dev/null; echo "*/2 * * * * /path/to/script") | crontab -
```

## File Operations

### Download Files
```bash
# Using fetch (FreeBSD native)
fetch -o /tmp/file.txt https://example.com/file.txt
fetch -qo /tmp/file.txt https://example.com/file.txt  # Quiet mode

# Using curl (if installed)
curl -o /tmp/file.txt https://example.com/file.txt
curl -k -o /tmp/file.txt https://example.com/file.txt  # Ignore SSL errors
```

### File Permissions
```bash
chmod +x /path/to/script.sh
chmod 755 /path/to/script.sh
chown root:wheel /path/to/file
```

## Common Pitfalls

### 1. Variable Expansion in pfctl
❌ **WRONG**: `echo "pass in quick on $WAN ..."` - Shell tries to expand $WAN  
✅ **RIGHT**: `echo "pass in quick on igc0 ..."` - Use actual interface name

### 2. Anchor Names
- Some OPNsense versions use `opnsense` anchor
- Others use `opnsense/rules` for user rules
- Always test with `pfctl -sr -a <anchor_name>` to verify

### 3. Persistent vs Temporary Rules
- Rules added via `pfctl` are **temporary** (lost on reboot/reload)
- For persistent rules, use OPNsense GUI or edit `/conf/config.xml`

### 4. Crontab Overwrites
- `echo "..." | crontab -` **replaces entire crontab**
- Always append, don't replace

### 5. Background Processes
- Use `nohup` or daemon for long-running processes
- Check with `ps aux` to verify they're running

## Testing Commands Safely

### Test Before Applying
```bash
# Check syntax without applying
echo "rule here" | pfctl -nf -

# Dry-run mode (if available)
pfctl -n -a opnsense -f /path/to/rules
```

### Recovery
If you lock yourself out:
1. Physical/console access required
2. Reboot clears temporary pfctl rules
3. Boot into single-user mode if needed

## Examples for Common Tasks

### Allow SSH from Specific IP
```bash
# Temporary rule
echo "pass in quick proto tcp from 184.175.206.229 to any port 22" | pfctl -a opnsense -f -

# Verify
pfctl -sr -a opnsense | grep 184.175.206.229
```

### Check if SSH is Accessible
```bash
# From another machine
ssh -o ConnectTimeout=5 root@firewall_ip "echo test"

# Check locally
sockstat -4 -l | grep :22
```

### Update Agent Script
```bash
# Download
fetch -qo /usr/local/bin/opnsense_agent_v2.sh https://manager/downloads/agent.sh

# Set permissions
chmod +x /usr/local/bin/opnsense_agent_v2.sh

# Test
/usr/local/bin/opnsense_agent_v2.sh --test  # If test mode exists

# Deploy
pkill -f opnsense_agent
/usr/local/bin/opnsense_agent_v2.sh &
```

---

## Quick Reference Card

| Task | Command |
|------|---------|
| Add firewall rule | `echo "pass in ..." \| pfctl -a opnsense -f -` |
| List rules | `pfctl -sr -a opnsense` |
| Start SSH | `/usr/local/etc/rc.d/openssh start` |
| Check SSH | `sockstat -4 -l \| grep :22` |
| Get version | `opnsense-version` |
| Update system | `/usr/local/sbin/opnsense-update -bkf` |
| List interfaces | `ifconfig \| grep -E "^[a-z]+"` |
| Check process | `ps aux \| grep process_name` |
| Kill process | `pkill -9 -f process_name` |
| Download file | `fetch -qo /path/to/file URL` |
| View cron | `crontab -l` |
| Check uptime | `uptime` |

---

**Last Updated**: October 14, 2025  
**Tested On**: OPNsense 25.7.5, FreeBSD 14.3-RELEASE
