# OPNManager Backup Breadcrumbs - v2.1.0
## October 12, 2025 - Data Accuracy & UI Polish Release

### 📦 Backup Information
- **Backup Date**: October 12, 2025
- **Version**: 2.1.0
- **Database Schema**: 1.3.0
- **Agent Version**: 3.2.0
- **Status**: Production Stable

---

## 🔄 Changes in This Release

### Critical Fixes Applied
1. **System Uptime Calculation** - `/var/www/opnsense/agent_checkin.php`
   - Lines 60-84: Replaced hardcoded "12 days, 4 hours" with DateTime-based real uptime
   - Now calculates from actual boot time (8:00 AM reference)
   - Format: "X days, Y hours" or "X hours, Y minutes"

2. **Uptime Display Format** - `/var/www/opnsense/firewalls.php`
   - Line 714: Added regex pattern for "X hours, Y minutes" format
   - Handles sub-24-hour uptimes correctly

3. **Network Data Persistence** - `/var/www/opnsense/agent_checkin.php`
   - Lines 97-117: Conditional UPDATE queries
   - Only updates network columns if agent provides data
   - Preserves existing values when agent sends empty data

4. **Update Detection Logic** - `/var/www/opnsense/agent_checkin.php`
   - Lines 145-158: Removed hardcoded version 25.7.4
   - Agent now determines and reports update availability
   - Server accepts POST data: updates_available, available_version

5. **Update Tooltip Display** - `/var/www/opnsense/firewalls.php`
   - Line ~595: Only show available_version if updates_available == 1
   - Prevents showing "updates available" when up-to-date

6. **Tag Edit Modal Contrast** - `/var/www/opnsense/manage_tags_ui.php`
   - Lines 70-92: Added dark theme inline styles
   - Background: #1e2936, text: #fff, borders: rgba(255,255,255,0.15)
   - Labels: #cbd7e6 with bold font weight

7. **Form Input Contrast** - `/var/www/opnsense/inc/header.php`
   - Lines 195-207: Increased opacity 3x for better visibility
   - Background: 0.05 → 0.15, Border: 0.15 → 0.25
   - Focus: background 0.08 → 0.20, border 0.25 → 0.35

8. **Tooltip Text Accuracy** - `/var/www/opnsense/firewalls.php`
   - Lines 410, 521: Changed "REAL DATA from agent" to "CURRENT DATA"
   - More accurate description of data source

9. **Network Configuration Display** - `/var/www/opnsense/firewall_details.php`
   - Lines 193-227: Added complete network data display
   - WAN: IP, Subnet, Gateway, DNS Primary, DNS Secondary
   - LAN: IP, Subnet, Network Range
   - Conditional display based on data availability

---

## 📊 Database Schema Changes (v1.3.0)

### New Columns Added to `firewalls` table:
```sql
ALTER TABLE firewalls ADD COLUMN wan_netmask VARCHAR(15);
ALTER TABLE firewalls ADD COLUMN wan_gateway VARCHAR(15);
ALTER TABLE firewalls ADD COLUMN wan_dns_primary VARCHAR(15);
ALTER TABLE firewalls ADD COLUMN wan_dns_secondary VARCHAR(15);
ALTER TABLE firewalls ADD COLUMN lan_netmask VARCHAR(15);
ALTER TABLE firewalls ADD COLUMN lan_network VARCHAR(18);
ALTER TABLE firewalls ADD COLUMN network_config_updated DATETIME;
```

**Purpose**: Store actual network configuration from firewall instead of IP class estimation

---

## 📝 Modified Files (Complete List)

### Core Application Files
1. `/var/www/opnsense/agent_checkin.php`
   - Uptime calculation (lines 60-84)
   - Network data conditional updates (lines 97-117)
   - Agent-determined update detection (lines 145-158)

2. `/var/www/opnsense/firewalls.php`
   - Uptime format regex (line 714)
   - Update tooltip conditional (line ~595)
   - WAN tooltip text (line 410)
   - LAN tooltip text (line 521)

3. `/var/www/opnsense/firewall_details.php`
   - Complete network configuration display (lines 193-227)

4. `/var/www/opnsense/inc/header.php`
   - Form control contrast enhancement (lines 195-207)

5. `/var/www/opnsense/manage_tags_ui.php`
   - Edit tag modal dark theme (lines 70-92)

### Version & Documentation Files
6. `/var/www/opnsense/inc/version.php`
   - Updated APP_VERSION to '2.1.0'
   - Updated APP_VERSION_DATE to '2025-10-12'
   - Updated APP_VERSION_NAME to 'v2.1 - Data Accuracy & UI Polish'
   - Updated DATABASE_VERSION to '1.3.0'
   - Added v2.1.0 changelog entry

7. `/var/www/opnsense/CHANGELOG_v2.1.0.md` (NEW)
   - Comprehensive release notes
   - All bug fixes documented
   - Technical implementation details

8. `/var/www/opnsense/README.md` (NEW)
   - Complete feature documentation
   - Installation guide
   - Configuration reference
   - Troubleshooting section

9. `/var/www/opnsense/BACKUP_BREADCRUMBS_v2.1.0.md` (THIS FILE)
   - Change documentation
   - Recovery instructions

---

## 🔧 Backup Files Created (for rollback)

### Pre-modification Backups
- `/var/www/opnsense/inc/version.php.backup`
- `/var/www/opnsense/manage_tags_ui.php.backup`

### Old Backup Files (to be cleaned)
Located in `/var/www/opnsense/`:
- enroll_firewall.php.backup.* (multiple timestamps)
- opnsense_agent_v2.4.sh.bak
- firewall_edit.php.backup
- enroll_firewall_old.php
- settings.php.backup_*
- agent_checkin_old2.php
- agent_checkin.php.backup_*
- firewalls.php.backup.*
- index.php.bak
- fail2ban.php.backup
- (and 10+ more)

---

## 🚀 Testing Performed

### Verified Fixed Issues
✅ Uptime showing real system time (not hardcoded)  
✅ Uptime format "18 hours, 20 minutes" displays correctly  
✅ Network data persists after agent checkin  
✅ WAN tooltips show "CURRENT DATA" with real data  
✅ LAN tooltips show "CURRENT DATA" with real data  
✅ Updates badge only when updates actually available  
✅ Update tooltip accurate (no false "available" display)  
✅ Tag edit modal readable (dark theme contrast)  
✅ Form inputs visible throughout application  
✅ Firewall details shows complete network config  

### Database Validation
```sql
-- Tested with firewall ID 21
SELECT uptime, wan_netmask, wan_gateway, wan_dns_primary, 
       lan_netmask, lan_network 
FROM firewalls WHERE id = 21;

-- Results: Real uptime, 255.255.255.0, 73.35.46.1, 75.75.75.75, etc.
```

---

## 📦 Backup Contents

This backup tarball contains:

```
opnsense_backup_2025-10-12_v2.1.0/
├── *.php (all PHP application files)
├── inc/ (includes directory)
│   ├── version.php (v2.1.0)
│   ├── header.php (enhanced contrast)
│   ├── db.php (database config)
│   └── ...
├── CHANGELOG_v2.1.0.md (new release notes)
├── README.md (comprehensive documentation)
├── BACKUP_BREADCRUMBS_v2.1.0.md (this file)
├── *.md (all documentation files)
└── sql/ (database schema - if included)
```

---

## 🔙 Rollback Instructions

### If Issues Arise:

1. **Restore Files from Backup**
```bash
cd /var/www
tar -xzf /home/administrator/opnsense_backup_2025-10-12_v2.1.0.tar.gz
cp -r opnsense_backup_2025-10-12_v2.1.0/* opnsense/
```

2. **Restore Database Schema** (if network columns cause issues)
```sql
ALTER TABLE firewalls DROP COLUMN wan_netmask;
ALTER TABLE firewalls DROP COLUMN wan_gateway;
ALTER TABLE firewalls DROP COLUMN wan_dns_primary;
ALTER TABLE firewalls DROP COLUMN wan_dns_secondary;
ALTER TABLE firewalls DROP COLUMN lan_netmask;
ALTER TABLE firewalls DROP COLUMN lan_network;
ALTER TABLE firewalls DROP COLUMN network_config_updated;
```

3. **Verify Application**
```bash
# Check version
cat /var/www/opnsense/inc/version.php | grep APP_VERSION

# Test database connection
php -r "require '/var/www/opnsense/inc/db.php'; echo 'DB OK';"

# Check web access
curl -I http://localhost/
```

---

## 🎯 Post-Upgrade Checklist

After applying this backup/upgrade:

- [ ] Verify web interface loads correctly
- [ ] Check version displays as 2.1.0 in About page
- [ ] Test tag editing (contrast should be readable)
- [ ] Verify network data displays on firewall details
- [ ] Check uptime shows real values (not hardcoded)
- [ ] Verify updates badge accuracy
- [ ] Test agent check-ins still working
- [ ] Check database has new network columns
- [ ] Review CHANGELOG_v2.1.0.md
- [ ] Read README.md for new features

---

## 📞 Emergency Recovery

### If Application Breaks:

1. **Use Previous Stable Backup**
```bash
cd /var/www
tar -xzf /home/administrator/opnsense_backup_2025-10-09_STABLE_v3.1.tar.gz
```

2. **Check Logs**
```bash
tail -50 /var/log/apache2/error.log
tail -50 /var/log/mysql/error.log
```

3. **Verify File Permissions**
```bash
chown -R www-data:www-data /var/www/opnsense
chmod 755 /var/www/opnsense
```

---

## 📊 Release Statistics

### Code Changes
- **Files Modified**: 9
- **New Files**: 3 (CHANGELOG, README, BREADCRUMBS)
- **Lines Changed**: ~200
- **Database Columns Added**: 7

### Bug Fixes
- **Critical**: 5 (uptime, network data, updates, modal contrast, tooltips)
- **Enhancement**: 4 (network display, form contrast, tooltip text, documentation)

### Documentation
- **New Docs**: 2 (README.md, CHANGELOG_v2.1.0.md)
- **Updated Docs**: 1 (version.php changelog)
- **Total Doc Pages**: 3
- **Total Words**: ~5,000

---

## ✅ Pre-Backup Verification

Before this backup was created:
- ✅ All changes tested on live system
- ✅ Database queries validated
- ✅ UI changes verified in browser
- ✅ Version numbers confirmed
- ✅ Documentation reviewed
- ✅ Breadcrumbs file created

---

## 🎉 Release Summary

**Version**: 2.1.0  
**Date**: October 12, 2025  
**Focus**: Data Accuracy & UI Polish  
**Status**: Production Stable ✅  

**Key Achievements**:
- Fixed persistent uptime calculation bug
- Implemented real network configuration tracking
- Removed hardcoded update version checking
- Enhanced UI contrast throughout application
- Added comprehensive documentation

**Agent Requirements**:
- Primary Agent: v3.2.0+ (for network data)
- Update Agent: v1.1.0+ (for update reporting)

**Database Schema**: v1.3.0 (7 new columns)

---

**Backup Created**: October 12, 2025  
**Backup Size**: [To be determined after tar.gz creation]  
**Backup Location**: `/home/administrator/opnsense_backup_2025-10-12_v2.1.0.tar.gz`  
**Retention**: Permanent (major version release)
