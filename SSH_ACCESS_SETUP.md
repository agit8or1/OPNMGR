# SSH Access Setup for Remote Management

## Overview
For OPNManager to remotely manage firewalls (repair agents, run commands, etc.), SSH access must be configured on each firewall.

## Current Status
- ✅ SSH keys are automatically added during enrollment
- ❌ Firewall rules are NOT automatically created (manual step required)
- ✅ Server IP: `184.175.206.229`
- ✅ SSH Public Key: Already added to `/root/.ssh/authorized_keys` during enrollment

## Why SSH Access is Needed
- **Agent Repair**: Automatically fix/update agents without manual intervention
- **Remote Commands**: Execute commands for troubleshooting
- **Configuration Updates**: Apply changes remotely
- **Emergency Access**: Access firewalls even if web UI is down

## Manual Setup Required

### For Each Firewall:

1. **Log into OPNsense Web UI**
   - Go to `Firewall → Rules → WAN`

2. **Add New Rule**
   - Click the `+` button to add a new rule

3. **Configure Rule:**
   ```
   Action:              Pass
   Interface:           WAN
   Protocol:            TCP
   Source:              Single host - 184.175.206.229
   Destination:         WAN address (or "This Firewall")
   Destination Port:    22 (SSH)
   Description:         OPNManager Remote Access
   ```

4. **Save and Apply**
   - Click `Save`
   - Click `Apply Changes`

## Testing SSH Access

From OPNManager server, test connection:
```bash
sudo -u www-data ssh -i /var/www/opnsense/keys/id_firewall_<ID> -o StrictHostKeyChecking=no root@<FIREWALL_WAN_IP> 'echo "SSH Working"'
```

## Security Notes
- SSH access is restricted to OPNManager server IP only (184.175.206.229)
- Uses SSH key authentication (no password)
- Keys are unique per firewall
- Firewall rule can be disabled/removed at any time

## Alternative Methods

### Option 1: Allow from LAN Only
If OPNManager is on the same network:
- Change Source to: LAN subnet
- Interface: LAN (instead of WAN)

### Option 2: VPN Access
If using VPN:
- Create rule on VPN interface instead of WAN
- Source: VPN subnet or OPNManager VPN IP

## Troubleshooting

**Connection Refused:**
- Check firewall rule is enabled
- Verify Source IP is correct (184.175.206.229)
- Check SSH is enabled: `System → Settings → Administration → Enable Secure Shell`

**Connection Timeout:**
- Firewall rule not created
- Wrong WAN interface selected
- Firewall is offline or unreachable

**Permission Denied:**
- SSH key not in authorized_keys
- Re-run enrollment script to add key

## Automated Setup (Future)
Future versions may use OPNsense API to automatically create this firewall rule during enrollment.
