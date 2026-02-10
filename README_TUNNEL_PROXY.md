# Tunnel Proxy System - Complete Guide

## �� Documentation Index

This directory contains complete documentation for the OPNManager Tunnel Proxy system.

### Quick Start
👉 **[TUNNEL_PROXY_QUICK_REFERENCE.md](TUNNEL_PROXY_QUICK_REFERENCE.md)** - Start here!
- How to use the tunnel proxy
- Quick troubleshooting commands
- Common issues and fixes

### Technical Documentation
📖 **[TUNNEL_PROXY_FIX_DOCUMENTATION.md](TUNNEL_PROXY_FIX_DOCUMENTATION.md)** - Complete technical details
- Architecture overview
- Root cause analysis of issues
- How the fix was implemented
- Troubleshooting guide
- Testing procedures

### Changelog
📝 **[CHANGELOG.md](CHANGELOG.md)** - Version history
- What changed in v2.2.0
- Previous versions
- Upgrade notes

---

## 🎯 Current Status

**Version**: 2.2.3 (tunnel_proxy.php v2.0.2)
**Release Date**: December 11, 2025
**Status**: ✅ **FULLY WORKING**

### What Works
- ✅ Create tunnel sessions via web interface
- ✅ Access OPNsense firewall through HTTPS tunnel
- ✅ Login successfully (no redirect loops!)
- ✅ Automatic cleanup of expired sessions
- ✅ "Tunnel Mode" badge on all sessions
- ✅ Fast tunnel creation (2-3 seconds)

### Key Features
- 🔐 Full HTTPS encryption
- 🎨 Visual "Tunnel Mode" indicator
- 🧹 Automatic cleanup every 5 minutes
- ⚡ Fast and reliable
- 🔒 Secure SSH key authentication

---

## 🚀 Usage

1. Navigate to firewall dashboard in OPNManager
2. Click **"Access via Tunnel Proxy"** button
3. Wait 2-3 seconds for tunnel creation
4. Login to OPNsense with your credentials
5. Look for **"🔒 Tunnel Mode"** badge in top-right corner

---

## 📦 Backup & Recovery

### Database Backup
Latest backup with tunnel proxy fix:
```
/var/www/opnsense/backups/opnsense_fw_v2.2.0_tunnel_fix_20251020_140855.sql
```

### Restore if Needed
```bash
mysql opnsense_fw < /var/www/opnsense/backups/opnsense_fw_v2.2.0_tunnel_fix_20251020_140855.sql
```

---

## 🛠️ Quick Troubleshooting

### Badge Not Showing?
```bash
# Regenerate nginx config (includes latest template with badge)
sudo /usr/bin/php /var/www/opnsense/scripts/manage_nginx_tunnel_proxy.php remove {session_id}
sudo /usr/bin/php /var/www/opnsense/scripts/manage_nginx_tunnel_proxy.php create {session_id}
```

### Can't Connect?
```bash
# Clean up orphaned tunnels
ps aux | grep "ssh.*-L.*810" | grep -v grep | awk '{print $2}' | xargs sudo kill -9

# Run cleanup
sudo /usr/bin/php /var/www/opnsense/scripts/manage_ssh_access.php cleanup_expired
```

### See Full Troubleshooting Guide
👉 [TUNNEL_PROXY_FIX_DOCUMENTATION.md](TUNNEL_PROXY_FIX_DOCUMENTATION.md#troubleshooting-guide)

---

## 📞 Need Help?

1. Check **Quick Reference**: [TUNNEL_PROXY_QUICK_REFERENCE.md](TUNNEL_PROXY_QUICK_REFERENCE.md)
2. Read **Full Documentation**: [TUNNEL_PROXY_FIX_DOCUMENTATION.md](TUNNEL_PROXY_FIX_DOCUMENTATION.md)
3. Check **Logs**: `/var/log/nginx/error.log`
4. Review **Changelog**: [CHANGELOG.md](CHANGELOG.md)

---

## 🎉 Success Story

After multiple attempts and different approaches, the tunnel proxy system was completely rewritten in v2.2.0. The solution was to **simplify**: use nginx as a direct HTTPS-to-HTTP proxy instead of a complex PHP reverse proxy. This eliminated URL rewriting issues, session problems, and made the system fast and reliable.

**Result**: Users can now successfully access firewalls through encrypted tunnels with zero login issues! 🎊

---

**Version**: 2.2.3 (tunnel_proxy.php v2.0.2)
**Last Updated**: December 11, 2025
**Status**: ✅ Production Ready

### Recent Fixes (v2.2.3)
- ✅ Fixed "Empty reply from server" errors after login
- ✅ HTTPS protocol now correctly used for all tunnel connections
- ✅ Redirect handler fixed to use HTTPS
- ✅ Duplicate SSH tunnel processes prevented
