# SSH Tunnel Management for OPNsense Manager

## Overview
SSH tunnels provide secure access to firewall web interfaces through the manager server. This eliminates the need to expose firewall management interfaces to the internet.

## Architecture

### Components
1. **SSH Key Authentication** - Passwordless authentication using ED25519 keys
2. **Database Storage** - SSH private keys stored encrypted (base64) in database
3. **Tunnel Manager** - PHP CLI script for tunnel lifecycle management
4. **API Endpoint** - REST API for web interface integration

### Data Flow
```
Manager → SSH Tunnel → Firewall :443
   |
   ↓
User → https://opnmgr:9443 → localhost:443 on firewall
```

## Database Schema

### firewalls table additions:
```sql
ALTER TABLE firewalls ADD COLUMN ssh_private_key TEXT NULL;
ALTER TABLE firewalls ADD COLUMN ssh_tunnel_port INT DEFAULT NULL;
```

- `ssh_private_key`: Base64-encoded SSH private key for authentication
- `ssh_tunnel_port`: Local port on manager for tunnel (default: 9443)

## SSH Key Setup

### 1. Generate Key Pair
```bash
ssh-keygen -t ed25519 -f ~/.ssh/id_opnsense_manager -N "" -C "opnsense-manager-automation"
```

### 2. Deploy Public Key to Firewall
Via agent command:
```bash
mysql -u opnsense_user -p'password' opnsense_fw << 'SQLEOF'
INSERT INTO firewall_commands (firewall_id, command, description, status) 
VALUES (21, 
'mkdir -p /root/.ssh && chmod 700 /root/.ssh && echo "SSH_PUBLIC_KEY_HERE" >> /root/.ssh/authorized_keys && chmod 600 /root/.ssh/authorized_keys',
'Add manager SSH public key', 
'pending');
SQLEOF
```

### 3. Store Private Key in Database
```bash
SSH_KEY_BASE64=$(cat ~/.ssh/id_opnsense_manager | base64 -w 0)
mysql -u opnsense_user -p'password' opnsense_fw << SQLEOF
UPDATE firewalls SET 
  ssh_private_key='${SSH_KEY_BASE64}', 
  ssh_tunnel_port=9443 
WHERE id=21;
SQLEOF
```

## Tunnel Management

### CLI Commands

#### Start Tunnel
```bash
php /var/www/opnsense/scripts/manage_ssh_tunnel.php start <firewall_id>
```

Output:
```json
{
    "success": true,
    "port": 9443,
    "url": "https://opnmgr:9443",
    "message": "Tunnel established on port 9443"
}
```

#### Stop Tunnel
```bash
php /var/www/opnsense/scripts/manage_ssh_tunnel.php stop <firewall_id>
```

#### Check Status
```bash
php /var/www/opnsense/scripts/manage_ssh_tunnel.php status <firewall_id>
```

Output:
```json
{
    "active": true,
    "port": 9443,
    "url": "https://opnmgr:9443"
}
```

### API Endpoints

Base URL: `/api/ssh_tunnel.php`

#### Get Tunnel Status
```
GET /api/ssh_tunnel.php?action=status&firewall_id=21
```

Response:
```json
{
    "active": true,
    "port": 9443,
    "url": "https://opnmgr:9443"
}
```

#### Start Tunnel
```
POST /api/ssh_tunnel.php?action=start&firewall_id=21
```

#### Stop Tunnel
```
POST /api/ssh_tunnel.php?action=stop&firewall_id=21
```

## Manual Tunnel Creation

For testing or manual use:

```bash
ssh -i ~/.ssh/id_opnsense_manager \
    -o StrictHostKeyChecking=no \
    -o ServerAliveInterval=60 \
    -L 9443:localhost:443 \
    -N -f \
    root@<firewall_ip>
```

Parameters:
- `-i`: SSH private key file
- `-L 9443:localhost:443`: Forward local port 9443 to firewall's port 443
- `-N`: No remote command execution
- `-f`: Background the tunnel
- `-o ServerAliveInterval=60`: Keep connection alive

## Accessing Firewall Web Interface

Once tunnel is active:

1. **Direct Access**: `https://opnmgr:9443`
2. **IP Access**: `https://<manager_ip>:9443`
3. **Localhost** (from manager): `https://localhost:9443`

**Note**: Browser will show SSL certificate warning since the certificate is for the firewall's hostname, not the manager's. This is expected and safe to accept.

## Security Considerations

### 1. Private Key Protection
- Keys stored base64-encoded in database (not encrypted)
- Database access should be restricted
- Consider encrypting at application level for production

### 2. SSH Key Rotation
To rotate keys:
```bash
# Generate new key
ssh-keygen -t ed25519 -f ~/.ssh/id_opnsense_manager_new -N ""

# Deploy via agent
# ... (same as setup)

# Update database
# ... (same as setup)

# Remove old key from firewall
# Via agent: sed -i '/old_key_fingerprint/d' /root/.ssh/authorized_keys
```

### 3. Tunnel Monitoring
- Tunnels auto-reconnect via ServerAliveInterval
- Check tunnel status before accessing firewall
- Implement tunnel health checks in monitoring

### 4. Firewall Rules
Required firewall rule:
```xml
<rule>
  <type>pass</type>
  <interface>wan</interface>
  <protocol>tcp</protocol>
  <source><address>MANAGER_IP</address></source>
  <destination><network>(self)</network><port>22</port></destination>
  <descr>Allow SSH from management server</descr>
</rule>
```

**Important**: Ensure 1:1 NAT is configured if manager is behind NAT.

## Troubleshooting

### Tunnel Won't Start
1. Check SSH key is deployed: `ssh -i ~/.ssh/id_opnsense_manager root@<firewall_ip> "echo SUCCESS"`
2. Check firewall rule: `pfctl -sr | grep <manager_ip>`
3. Check port availability: `lsof -i :<port>`
4. Check SSH daemon: Via agent: `sockstat -4 -l | grep :22`

### Connection Refused
1. Verify tunnel is active: `php scripts/manage_ssh_tunnel.php status <id>`
2. Check tunnel process: `ps aux | grep 'ssh.*-L'`
3. Test local port: `curl -k https://localhost:<port>`

### Authentication Failed
1. Verify key in authorized_keys: Via agent: `cat /root/.ssh/authorized_keys`
2. Check key permissions: Via agent: `ls -la /root/.ssh/`
3. Check SSH logs: Via agent: `tail -20 /var/log/auth.log`

### Port Already in Use
```bash
# Find process using port
lsof -i :9443

# Kill old tunnel
kill <PID>

# Or use stop command
php scripts/manage_ssh_tunnel.php stop <firewall_id>
```

## Integration with Web Interface

### Display Tunnel Button
```php
<?php
$firewall = get_firewall_by_id($id);
if ($firewall['ssh_private_key']) {
    $tunnel_status = get_tunnel_status($firewall);
    if ($tunnel_status['active']) {
        echo '<a href="' . $tunnel_status['url'] . '" target="_blank" class="btn btn-success">Access Firewall</a>';
    } else {
        echo '<button onclick="startTunnel(' . $id . ')" class="btn btn-primary">Start Tunnel</button>';
    }
}
?>
```

### JavaScript Functions
```javascript
function startTunnel(firewallId) {
    fetch(`/api/ssh_tunnel.php?action=start&firewall_id=${firewallId}`, {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.open(data.url, '_blank');
        } else {
            alert('Failed to start tunnel: ' + data.error);
        }
    });
}

function stopTunnel(firewallId) {
    fetch(`/api/ssh_tunnel.php?action=stop&firewall_id=${firewallId}`, {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}
```

## Future Enhancements

1. **Key Encryption**: Encrypt private keys at rest using application-level encryption
2. **Dedicated User**: Create dedicated firewall user instead of using root
3. **Port Management**: Auto-assign available ports for multiple simultaneous tunnels
4. **Health Monitoring**: Periodic tunnel health checks and auto-restart
5. **Audit Logging**: Log all tunnel access attempts and durations
6. **Certificate Verification**: Store firewall SSL certificates to avoid browser warnings

## Files

- `/var/www/opnsense/scripts/manage_ssh_tunnel.php` - CLI tunnel manager
- `/var/www/opnsense/api/ssh_tunnel.php` - REST API endpoint
- `~/.ssh/id_opnsense_manager` - SSH private key (not in repo)
- `~/.ssh/id_opnsense_manager.pub` - SSH public key

## Testing Checklist

- [ ] SSH key authentication works without password
- [ ] Tunnel starts successfully via CLI
- [ ] Tunnel starts successfully via API
- [ ] Firewall web interface accessible through tunnel
- [ ] Tunnel status reports correctly
- [ ] Tunnel stops cleanly
- [ ] Multiple tunnels (different firewalls) work simultaneously
- [ ] Tunnel survives temporary network interruption
- [ ] Old tunnels cleaned up on restart
