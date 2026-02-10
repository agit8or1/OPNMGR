# Tunnel Proxy Quick Reference Card

## Version: 2.2.0 (October 20, 2025)
## Status: ✅ FULLY WORKING

---

## 🚀 How to Use

1. **Access Firewall**: Click "Access via Tunnel Proxy" on firewall dashboard
2. **Wait 2-3 seconds**: System creates tunnel automatically
3. **Login to OPNsense**: Use your normal credentials
4. **See Badge**: "🔒 Tunnel Mode" appears in top-right corner
5. **Session auto-expires**: 15 min idle OR 30 min max

---

## 🔧 Architecture

```
Browser (HTTPS)
    ↓
Nginx HTTPS Proxy (port 8100, 8102, 8104...)
    ↓ SSL Termination
    ↓ Inject "Tunnel Mode" Badge
    ↓
SSH Tunnel (port 8101, 8103, 8105...)
    ↓ Encrypted SSH
    ↓
Firewall Web Interface (port 80)
```

**Port Strategy**: Nginx on (tunnel_port - 1), SSH on (tunnel_port)

---

## 🛠️ Troubleshooting Commands

### Check Active Tunnels
```bash
ps aux | grep "ssh.*-L.*810"
```

### Check Active Sessions
```bash
php -r "require '/var/www/opnsense/inc/db.php'; \$r = \$DB->query('SELECT id, tunnel_port, status FROM ssh_access_sessions WHERE status=\"active\"'); foreach(\$r as \$s) echo \"Session {\$s['id']} on port {\$s['tunnel_port']}\n\";"
```

### Kill Orphaned Tunnels
```bash
ps aux | grep "ssh.*-L.*810" | grep -v grep | awk '{print $2}' | xargs sudo kill -9
```

### Recreate Nginx Config
```bash
sudo /usr/bin/php /var/www/opnsense/scripts/manage_nginx_tunnel_proxy.php remove {session_id}
sudo /usr/bin/php /var/www/opnsense/scripts/manage_nginx_tunnel_proxy.php create {session_id}
```

### Manual Cleanup
```bash
sudo /usr/bin/php /var/www/opnsense/scripts/manage_ssh_access.php cleanup_expired
```

### Check Nginx
```bash
sudo nginx -t
sudo systemctl status nginx
```

### View Logs
```bash
tail -f /var/log/nginx/access.log | grep tunnel
tail -f /var/log/nginx/error.log
```

---

## 📁 Key Files

| File | Purpose |
|------|---------|
| `/var/www/opnsense/start_tunnel_async.php` | Creates tunnel, returns URL |
| `/var/www/opnsense/scripts/manage_ssh_access.php` | Session management, cleanup |
| `/var/www/opnsense/scripts/manage_nginx_tunnel_proxy.php` | Nginx config generation |
| `/etc/nginx/sites-enabled/tunnel-session-*` | Active nginx configs |
| `/etc/sudoers.d/nginx-tunnel-proxy` | Sudo permissions |
| `TUNNEL_PROXY_FIX_DOCUMENTATION.md` | Full technical docs |

---

## 🔐 Security

- ✅ HTTPS encryption (Let's Encrypt SSL)
- ✅ SSH tunnel encryption
- ✅ Session timeout (15min idle / 30min max)
- ✅ Automatic cleanup of expired sessions
- ✅ Per-session isolation
- ✅ No credentials stored (uses SSH keys)

---

## 📊 Monitoring

### Session Timeouts
- **Idle timeout**: 15 minutes (last_activity)
- **Max session**: 30 minutes (created_at)

### Automatic Cleanup
- **Frequency**: Every 5 minutes (cron)
- **Actions**: 
  - Mark expired sessions as closed
  - Kill SSH tunnels
  - Remove nginx configs
  - Reload nginx

---

## 🎯 Success Indicators

- ✅ "🔒 Tunnel Mode" badge visible
- ✅ Login succeeds on first try
- ✅ No redirect loops
- ✅ AJAX/API calls work
- ✅ Page loads in 2-3 seconds

---

## 🆘 Emergency Recovery

If everything breaks:

```bash
# 1. Kill all tunnels
ps aux | grep "ssh.*-L.*810" | grep -v grep | awk '{print $2}' | xargs sudo kill -9

# 2. Remove all nginx configs
sudo rm -f /etc/nginx/sites-enabled/tunnel-session-*
sudo rm -f /etc/nginx/sites-available/tunnel-session-*
sudo systemctl reload nginx

# 3. Mark all sessions closed
mysql opnsense_fw -e "UPDATE ssh_access_sessions SET status='closed' WHERE status='active';"

# 4. Try creating fresh tunnel from web interface
```

---

## 📞 Support

- **Documentation**: `TUNNEL_PROXY_FIX_DOCUMENTATION.md`
- **Logs**: `/var/log/nginx/*.log`
- **Database Backup**: `/var/www/opnsense/backups/opnsense_fw_v2.2.0_*.sql`
- **Version**: `cat /var/www/opnsense/VERSION`

---

**Last Updated**: October 20, 2025  
**Status**: ✅ Working perfectly!  
**Developer**: GitHub Copilot + Administrator
