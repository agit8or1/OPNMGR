# Remote Tunnel Deployment via Agent Command Queue

## You're Right! We Don't Need Console Access! 🎉

The OpnMgr agent supports:
1. ✅ **Shell command execution** via queued_commands
2. ✅ **Regular check-ins** every 5 minutes
3. ✅ **Command result reporting** back to management server

## Remote Deployment Method

### Option 1: Use the Deployment Script (Easiest)

```bash
sudo /tmp/deploy_tunnel_remotely.sh 21
```

This will:
1. Queue the tunnel setup command for firewall ID 21
2. Agent will download and execute setup script on next check-in
3. Results (including SSH public key) will be stored in firewall_commands table

### Option 2: Manual API Call

```bash
curl -k -X POST https://opn.agit8or.net/api/queue_command.php \
  -H "Content-Type: application/json" \
  -d '{
    "firewall_id": 21,
    "command": "fetch -o /tmp/setup.sh https://opn.agit8or.net/setup_reverse_proxy.sh && chmod +x /tmp/setup.sh && /tmp/setup.sh 21",
    "description": "Setup reverse SSH tunnel"
  }'
```

### Option 3: Via Web Interface (if implemented)

Navigate to firewall details → Execute custom command

## Monitoring Deployment

Check command status:
```bash
sudo mysql -u root -e "
  USE opnsense_fw; 
  SELECT id, description, status, created_at, completed_at, result 
  FROM firewall_commands 
  WHERE firewall_id=21 
  ORDER BY created_at DESC 
  LIMIT 5;"
```

Watch for completion:
```bash
watch -n 10 'sudo mysql -u root -e "USE opnsense_fw; SELECT id, status, LEFT(result,100) as result FROM firewall_commands WHERE firewall_id=21 ORDER BY id DESC LIMIT 3;"'
```

## Getting the SSH Public Key

After the command completes successfully:

```bash
sudo mysql -u root -e "
  USE opnsense_fw; 
  SELECT result 
  FROM firewall_commands 
  WHERE firewall_id=21 
    AND description='Setup reverse SSH tunnel' 
  ORDER BY id DESC 
  LIMIT 1;" | grep -A10 "SSH PUBLIC KEY"
```

Or check the full result:
```bash
sudo mysql -u root opnsense_fw -e "
  SELECT result 
  FROM firewall_commands 
  WHERE firewall_id=21 
  ORDER BY id DESC 
  LIMIT 1;" -s -N
```

## Add SSH Key to Management Server

```bash
# Extract the key from command result
KEY=$(sudo mysql -u root opnsense_fw -e "SELECT result FROM firewall_commands WHERE firewall_id=21 ORDER BY id DESC LIMIT 1;" -s -N | grep "^ssh-rsa\|^ecdsa" | head -1)

# Add to tunnel user's authorized_keys
sudo su - tunnel -c "echo '$KEY' >> ~/.ssh/authorized_keys"
sudo su - tunnel -c "chmod 600 ~/.ssh/authorized_keys"
```

## Verify Tunnel Connection

After key is added, the firewall's autossh should establish the tunnel automatically (within 1-2 minutes):

```bash
# Check if tunnel is active
ps aux | grep "[a]utossh.*8103"

# Test connection through tunnel
curl -k -I https://localhost:8103

# Access firewall via proxy
curl -k -I https://localhost:8102
```

## Timeline

1. **T+0**: Queue command via API
2. **T+0-5 min**: Agent checks in and receives command
3. **T+5-7 min**: Command executes, tunnel setup completes
4. **T+7-10 min**: SSH key in database, add to authorized_keys
5. **T+10-12 min**: Tunnel establishes automatically
6. **T+12 min**: Access firewall via https://opn.agit8or.net:8102

## Why This Is Better Than Console Access

✅ **Automated**: One command queues everything
✅ **Tracked**: Results stored in database
✅ **Remote**: No need for physical/console access
✅ **Scalable**: Deploy to multiple firewalls easily
✅ **Auditable**: Full command history in database
✅ **Safe**: Agent validates and reports results

## Troubleshooting

If command doesn't execute:
1. Check firewall is online: `SELECT hostname, status, last_checkin FROM firewalls WHERE id=21`
2. Check command was sent: `SELECT status FROM firewall_commands WHERE firewall_id=21 ORDER BY id DESC LIMIT 1`
3. Check agent logs on firewall: `/var/log/opnsense_agent.log`

If tunnel doesn't establish:
1. Verify command completed: `status='completed'` in firewall_commands
2. Check SSH key was added to tunnel user
3. Check firewall can reach management server on port 22/2222
4. Check autossh is running on firewall: `service opnsense_tunnel status`

## Deployment Script Location

Full deployment script: `/tmp/deploy_tunnel_remotely.sh`

Copy to project:
```bash
sudo cp /tmp/deploy_tunnel_remotely.sh /var/www/opnsense/scripts/
sudo chown www-data:www-data /var/www/opnsense/scripts/deploy_tunnel_remotely.sh
```
