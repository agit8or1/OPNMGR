# OPNManager v2.2.3 Release Notes
**Release Date: 2025-10-21**

## 🎯 Highlights

- **Fixed Tunnel Connection Issues**: Eliminated CONNECTION_REFUSED errors on first attempt
- **Session Conflict Resolution**: OPNManager and firewall sessions no longer conflict
- **Improved Cleanup**: Zombie tunnel cleanup now works reliably
- **Enhanced Security**: Better session isolation and security documentation

## 🔧 Technical Changes

### Tunnel System
- Implemented wait logic: SSH tunnel creation now waits up to 10 seconds for port to respond
- Nginx configs only created AFTER tunnel is verified ready
- Prevents race condition where nginx forwards to non-existent backend

### Session Management
- Changed OPNManager session name from `PHPSESSID` to `OPNMGR_SESSION`
- Prevents firewall cookies from overwriting OPNManager session
- Users can stay logged into both simultaneously

### Cleanup System
- Fixed cleanup script to call correct PHP files
- Cron job runs every 2 minutes
- Automatic zombie detection and termination
- Manual "Master Reset" button for emergency cleanup

### Security
- Disabled cookie domain/path rewriting (was breaking authentication)
- Custom session names provide sufficient isolation
- Added comprehensive security assessment documentation
- All changes audited and documented

## 📋 Migration Notes

### ⚠️ Breaking Changes
- **All users will be logged out** due to session name change
- Users must log in again (one-time occurrence)
- Existing tunnel sessions will be closed

### Upgrade Steps
1. Backup database: `mysqldump opnsense_fw > backup.sql`
2. Pull latest code: `git pull origin main`
3. Restart PHP-FPM: `sudo systemctl restart php8.3-fpm`
4. Reload nginx: `sudo systemctl reload nginx`
5. Clear any zombie tunnels: Settings → Tunnel Management → Master Reset
6. Test tunnel creation with one firewall

### Rollback Procedure
If issues occur:
1. Revert code: `git checkout v2.2.2`
2. Restore database: `mysql opnsense_fw < backup.sql`
3. Restart services: `sudo systemctl restart php8.3-fpm nginx`

## 🐛 Bug Fixes

- Fixed CONNECTION_REFUSED on first tunnel attempt
- Fixed session logout when accessing firewall through tunnel
- Fixed cleanup script calling non-existent `manage_tunnel_proxy.php`
- Fixed zombie tunnels not being killed automatically
- Fixed cookie isolation breaking firewall login

## 🚀 Performance Improvements

- Tunnel readiness now verified before nginx config creation
- Reduced failed connection attempts
- Faster overall tunnel establishment (no retry delay)

## 📚 Documentation

- Added comprehensive security assessment (SECURITY_ASSESSMENT.md)
- Updated CHANGELOG.md with detailed changes
- Documented cookie isolation workaround
- Added penetration testing recommendations

## 🔒 Security Notes

- Cookie isolation temporarily disabled but session names provide separation
- All tunnel traffic still encrypted (HTTPS + SSH)
- Key-based authentication unchanged
- No new vulnerabilities introduced

## ✅ Testing Checklist

- [x] Tunnel creation works without CONNECTION_REFUSED
- [x] OPNManager session persists while using firewall
- [x] Firewall login works without redirect loops
- [x] Automatic cleanup kills zombie tunnels
- [x] Manual Master Reset clears all tunnels
- [x] Multiple simultaneous tunnels work
- [x] Session expiry after 30 minutes
- [x] Nginx configs cleaned up properly

## 📞 Support

- **Issues**: GitHub Issues or OPNsense Forum
- **Documentation**: README.md and wiki
- **Security**: See SECURITY_ASSESSMENT.md

## 🙏 Acknowledgments

Thanks to all users who reported tunnel and session issues!

---

**Full Changelog**: See CHANGELOG.md for complete version history
