# OPNsense Manager - Documentation Index
**Last Updated:** 2025-11-11

---

## 🚀 New Feature Documentation

### DEPLOYMENT_SYSTEM_COMPLETE.md (Latest - Nov 11, 2025)
**Deployment Control Panel Documentation**
- Multi-server deployment system
- SSH authentication management
- Database schema (deployment_servers, deployment_logs)
- Step-by-step deployment workflow
- Dark theme UI implementation
- Security considerations
- Troubleshooting guide

---

## Session Documentation (Current Session)

### 1. SESSION_SUMMARY_2025-11-02.md (15KB)
**Comprehensive session summary including:**
- Complete overview of work accomplished
- System state and configuration
- Database structure and key tables
- File locations and API endpoints
- Agent update process
- Graph implementations
- Map implementation with Leaflet.js
- Speedtest configuration
- GeoIP implementation
- Troubleshooting steps
- Next session recommendations

### 2. DATABASE_SCHEMA_2025-11-02.sql (12KB)
**Database schema export for key tables:**
- firewalls
- firewall_agents
- firewall_commands
- bandwidth_tests
- system_logs
- backups
- ai_scan_reports
- ai_scan_findings

### 3. SYSTEM_STATE_2025-11-02.txt (737 bytes)
**Current system state snapshot:**
- Firewall status and agent versions
- Pending commands count
- Recent speedtest statistics
- System services status
- Log file sizes
- Database size

---

## Reference Documentation

### 4. QUICK_REFERENCE.md (1.6KB)
**Quick command reference:**
- Essential system status commands
- Log management
- Agent management
- Location management
- File locations
- Common SQL queries

### 5. GEOIP_LOCATION_GUIDE.md (4.6KB)
**GeoIP location documentation:**
- Understanding GeoIP accuracy
- How to set manual locations (3 methods)
- Common US city coordinates
- Troubleshooting location issues
- Files modified for map feature

---

## How to Use This Documentation

### Starting a New Session
1. Read **SESSION_SUMMARY_2025-11-02.md** first for complete context
2. Review **SYSTEM_STATE_2025-11-02.txt** for current status
3. Keep **QUICK_REFERENCE.md** open for common commands

### Making Changes
1. Check **DATABASE_SCHEMA_2025-11-02.sql** for table structure
2. Reference **SESSION_SUMMARY_2025-11-02.md** for file locations
3. Use **QUICK_REFERENCE.md** for testing commands

### Troubleshooting
1. Refer to "Known Issues & Pending Items" in SESSION_SUMMARY
2. Check "Common Issues & Solutions" in QUICK_REFERENCE
3. Review recent changes in SESSION_SUMMARY

---

## Key Accomplishments This Session

✅ **Agent Management**
- Upgraded agent from v3.7.6 to v3.7.7
- Implemented 4x daily speedtest schedule

✅ **Logging Improvements**
- Cleaned up nginx error log (removed informational logging)
- Fixed "Clear All Logs" functionality
- Fixed "Clear Log" functionality in nginx logs viewer

✅ **Dashboard Features** (from previous session)
- Interactive map with Leaflet.js
- GeoIP auto-location
- Statistics on all graphs (avg/peak/low)

✅ **Bug Fixes**
- Headers already sent error in nginx_logs.php
- API authentication for clear_all_logs.php
- Removed excessive debug logging from 4 files

---

## Quick Start Commands

### View Current Status
```bash
cat /var/www/opnsense/SYSTEM_STATE_2025-11-02.txt
```

### Check Agent Version
```bash
grep AGENT_VERSION /var/www/opnsense/inc/version.php | head -1
```

### View Recent Logs
```bash
sudo tail -50 /var/log/nginx/error.log
```

### Access Database
```bash
sudo mysql opnsense_fw
```

---

## File Locations Summary

### Documentation Files
- /var/www/opnsense/DEPLOYMENT_SYSTEM_COMPLETE.md (NEW - Nov 11, 2025)
- /var/www/opnsense/SESSION_SUMMARY_2025-11-02.md
- /var/www/opnsense/DATABASE_SCHEMA_2025-11-02.sql
- /var/www/opnsense/SYSTEM_STATE_2025-11-02.txt
- /var/www/opnsense/QUICK_REFERENCE.md
- /var/www/opnsense/GEOIP_LOCATION_GUIDE.md
- /var/www/opnsense/DOCUMENTATION_INDEX.md (this file)

### Key Application Files
- /var/www/opnsense/dashboard.php
- /var/www/opnsense/firewall_details.php
- /var/www/opnsense/agent_checkin.php
- /var/www/opnsense/downloads/opnsense_agent_v3.7.7.sh

### Configuration
- /etc/nginx/sites-enabled/opnsense
- /etc/sudoers.d/opnsense-manager
- /var/www/opnsense/inc/version.php

---

## Important Notes

⚠️ **Before Starting Next Session:**
1. Review SESSION_SUMMARY_2025-11-02.md completely
2. Check current system state has not changed unexpectedly
3. Verify all services are running (nginx, php-fpm, mysql)
4. Check for any new errors in nginx error log

⚠️ **When Making Changes:**
1. Always test nginx config: `sudo nginx -t`
2. Check PHP syntax: `php -l filename.php`
3. Reload services after changes
4. Monitor logs for errors

⚠️ **Security Reminders:**
1. All operations require authentication
2. Admin operations check isAdmin()
3. Use prepared statements for SQL
4. Use escapeshellarg() for shell commands

---

**End of Documentation Index**

For detailed information, refer to SESSION_SUMMARY_2025-11-02.md
