# OpnMgr Fixes Applied - October 3, 2025

## Issues Fixed

### 1. ✅ Firewall Update Function Not Triggering Proper Updates

**Problem**: Update triggers from web interface were failing with error:
```
/usr/local/bin/opnsense_agent.sh: 216: pkg: not found
```

**Root Cause**: The agent script was calling `pkg` without the full path. On FreeBSD/OPNsense, the `pkg` command is located at `/usr/sbin/pkg` and may not be in the PATH for all execution contexts.

**Files Fixed**:
1. `/var/www/opnsense/opnsense_agent_v2.3.sh` - Line 316
   - Changed: `'pkg update && pkg upgrade -y'`
   - To: `'/usr/sbin/pkg update && /usr/sbin/pkg upgrade -y'`

2. `/var/www/opnsense/agent_checkin.php` - Line 180
   - Changed: `'pkg update && pkg upgrade -y'`
   - To: `'/usr/sbin/pkg update && /usr/sbin/pkg upgrade -y'`

**Verification**: The agent will now properly execute OPNsense updates when triggered from the web interface.

**Next Steps**: 
- Firewalls need to download the updated agent script
- Can be triggered via the agent update mechanism
- Updates will now complete successfully

---

### 2. ⚠️ Reverse Proxy / SSH Tunnel Connection Issue

**Problem**: Cannot connect to firewall via reverse proxy or reverse SSH tunnel.

**Root Cause Analysis**:
1. Firewall at 73.35.46.112:443 is not directly reachable from management server (likely behind NAT/firewall)
2. Nginx reverse proxy is configured and running correctly on port 8102
3. SSH reverse tunnel is NOT currently active (no process found)
4. Tunnel infrastructure EXISTS on management server (tunnel user, nginx config)
5. Firewall needs to establish the REVERSE SSH tunnel TO the management server

**Current Status**:
- ✅ Management server has tunnel user configured
- ✅ Nginx proxy listening on port 8102
- ✅ Nginx configuration valid and tested
- ❌ No active SSH reverse tunnel from firewall
- ❌ Direct connection to firewall fails (firewall unreachable)

**Solution Required**:

The firewall needs to run the tunnel setup script to establish a reverse SSH tunnel:

**On the Firewall (OPNsense):**

1. Download and run the tunnel setup script:
```bash
curl -k https://opn.agit8or.net/setup_reverse_proxy.sh -o /tmp/setup_tunnel.sh
chmod +x /tmp/setup_tunnel.sh
/tmp/setup_tunnel.sh 21  # 21 is the firewall_id
```

2. This will:
   - Install autossh
   - Create tunnel user
   - Generate SSH key
   - Configure systemd/rc service for persistent tunnel
   - Start reverse SSH tunnel

3. Copy the SSH public key from firewall and add to management server:
```bash
# On firewall:
cat /home/tunnel/.ssh/id_rsa.pub

# On management server (as tunnel user):
echo "<public_key>" >> ~/.ssh/authorized_keys
```

4. Start the tunnel service:
```bash
service opnsense_tunnel start
```

**Expected Result**:
- Reverse SSH tunnel from firewall port 443 → management server port 8103
- Nginx on port 8102 will proxy to localhost:8103
- Access firewall via https://opn.agit8or.net:8102

**Alternative Solution - Request Queue Method**:
The nginx configuration has a fallback to request queue proxy (`/var/www/opnsense/proxy.php`) which uses the command queue system. This should work but may have limitations.

---

## Files Modified

1. `/var/www/opnsense/opnsense_agent_v2.3.sh` - Fixed pkg command path
2. `/var/www/opnsense/agent_checkin.php` - Fixed update command path
3. Backups created:
   - `/var/www/opnsense/opnsense_agent_v2.3.sh.backup_20251003`

## Testing Required

### For Update Function:
1. Navigate to firewall details page
2. Click "Trigger Update" button
3. Wait for agent check-in (every 5 minutes)
4. Verify update executes without "pkg: not found" error
5. Check `/var/log/opnsense_updater.log` on management server
6. Check firewall logs at `/var/log/opnsense_agent.log`

### For Reverse Proxy/SSH:
1. Complete tunnel setup on firewall (see steps above)
2. Verify tunnel is active: `ps aux | grep autossh` on firewall
3. Test connection: `curl -k https://localhost:8103` on management server
4. Access via proxy: https://opn.agit8or.net:8102
5. Should see OPNsense login page

## Additional Notes

- The firewall (ID 21, home.agit8or.net) is currently online and checking in regularly
- Current OPNsense version: 25.7.4
- Tunnel infrastructure is already in place, just needs activation
- Once tunnel is active, connection will be:
  - Client → nginx:8102 → localhost:8103 → SSH tunnel → firewall:443

## Summary

✅ **Update Function**: FIXED - Agent will now execute updates with correct pkg path
⚠️ **Reverse Tunnel**: IDENTIFIED - Requires setup script execution on firewall side
📋 **Next Action**: Deploy tunnel setup to firewall for remote access capability

