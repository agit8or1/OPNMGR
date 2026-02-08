# OPNManager Changelog - v1.0.0 Release

## Version 1.0.0 - Production Ready (2025-10-09)

### 🎉 MILESTONE: First Production Release

After extensive development, testing, and bug fixes, OPNManager has reached production stability and is now officially **v1.0.0**.

---

### ✅ Fixed Issues

#### Edit Firewall Page
- **FIXED**: "Column not found: 1054 Unknown column 'tag_names' in 'SET'" error
  - Root Cause: Attempted to UPDATE non-existent `tag_names` column
  - Solution: Use `firewall_tags` junction table for many-to-many tag relationships
  - Changes:
    - Removed `tag_names` from UPDATE query
    - Fixed variable names ($hostname instead of $name, $notes instead of $description)
    - Added proper tag handling via INSERT/DELETE on `firewall_tags` table
    - Fixed tag fetching to use JOIN query instead of computed column

#### Add Firewall Page
- **FIXED**: Page brightness and contrast issues
  - Root Cause: `bg-light` classes creating bright white backgrounds in dark theme
  - Solution: Replaced with `bg-dark` + proper border styling
  - Changes:
    - Command input: Changed from `bg-light` to `bg-dark border-secondary text-white`
    - Code blocks: Changed from `bg-light text-dark` to `bg-dark text-white border-secondary`
    - Added monospace fonts for better code readability

---

### 🚀 Improvements

#### Centralized Version Management
- Created `/inc/version.php` as single source of truth
- All version constants defined in one place:
  - `APP_VERSION = '1.0.0'`
  - `AGENT_VERSION = '3.1.0'`
  - `DATABASE_VERSION = '1.2.0'`
  - `API_VERSION = '1.0.0'`
- Helper functions for consistency:
  - `getVersionInfo()` - Returns structured version data
  - `getChangelogEntries()` - Returns version history
  - `getSystemHealth()` - Database health checks

#### Tag Management
- Proper many-to-many relationship implementation
- Tags stored in `tags` table
- Relationships in `firewall_tags` junction table
- Support for multiple tags per firewall
- Color-coded tag display

---

### 📚 Documentation

#### Session Documentation (2025-10-09)
- **CHANGELOG_2025-10-09.md** - Complete session changelog with all fixes
- **QUICK_REFERENCE.md** - Common commands and emergency procedures
- **SESSION_SUMMARY_2025-10-09.txt** - Full session details (6.6KB)
- **KNOWLEDGE_BASE.md** - Internal troubleshooting reference

#### Knowledge Base Includes:
1. Agent Issues
   - Agent running but not checking in
   - Duplicate agent check-ins (782 in 5 min crisis)
   - Command queue not processing
2. UI/Frontend Issues
   - Dropdown z-index conflicts
   - Bootstrap modal not opening
   - JavaScript malformed closures
3. Database Issues
   - Check-ins not logging diagnostics
4. FreeBSD/Shell Issues
   - Ambiguous output redirect (csh vs bash)
   - Event not found with `!`
   - Badly placed parentheses
5. Common Error Messages with quick fixes
6. Diagnostic Commands Reference
7. Best Practices for changes

---

### 🔧 Agent Status

#### Agent v3.1.0 (Stable)
- ✅ Checking in reliably every 120 seconds
- ✅ No duplicate check-ins (PID locking working)
- ✅ Background execution issues resolved
- ✅ Inline JSON format (no heredoc issues)
- ✅ Explicit binary paths (/usr/local/bin/curl)

---

### 📊 System Overview

#### Current State
- **Platform Version**: v1.0.0
- **Agent Version**: v3.1.0
- **Update Agent**: v1.0.0 (Not Deployed)
- **Database Version**: v1.2.0
- **API Version**: v1.0.0

#### Key Features Working
- ✅ Firewall enrollment (automated script)
- ✅ Agent check-ins (120s interval)
- ✅ Firewall details and editing
- ✅ Tag management (many-to-many)
- ✅ Customer grouping
- ✅ Backup and restore functionality
- ✅ System health monitoring
- ✅ Command queue system
- ✅ Changelog display

---

### 📦 Files Modified (v1.0.0 Release)

1. `/var/www/opnsense/inc/version.php`
   - Updated APP_VERSION from '3.1.0' to '1.0.0'
   - Updated APP_VERSION_NAME to 'v1.0 - Production Ready'
   - Added v1.0.0 changelog entry

2. `/var/www/opnsense/firewall_edit.php`
   - Fixed UPDATE query (removed tag_names column)
   - Fixed variable names ($hostname, $notes)
   - Added proper tag handling via firewall_tags junction table
   - Fixed tag fetching with JOIN query

3. `/var/www/opnsense/add_firewall_page.php`
   - Fixed command input styling (bg-dark, text-white)
   - Fixed code block styling (border-secondary)
   - Added monospace fonts for better readability

4. `/var/www/opnsense/CHANGELOG_v1.0.0.md` (NEW)
   - This file - comprehensive v1.0.0 release notes

---

### 🎯 Why v1.0.0?

The version jump from 3.1.0 (agent version) to 1.0.0 (platform version) reflects:

1. **Agent vs Platform Versioning**: Agent has its own version (3.1.0), platform needed separate versioning
2. **Production Stability**: All major bugs fixed, system running reliably
3. **Feature Completeness**: Core features implemented and working
4. **Comprehensive Documentation**: Full knowledge base, quick reference, session summaries
5. **Centralized Management**: Single source of truth for versions
6. **Proper Release Milestone**: Time to mark the platform as production-ready

---

### 🔜 Next Steps (Future Releases)

- Deploy Update Agent (currently at v1.0.0, not deployed)
- Proxy tunnel testing and documentation
- rc.local auto-start configuration guide
- Remote management API enhancements
- Automated backup scheduling
- Health monitoring alerts

---

### 📞 Support

For issues or questions, refer to:
- **KNOWLEDGE_BASE.md** - Troubleshooting guide
- **QUICK_REFERENCE.md** - Common commands
- **SESSION_SUMMARY_2025-10-09.txt** - Session details

---

**Version**: 1.0.0  
**Release Date**: 2025-10-09  
**Status**: Production Ready ✅  
**Agent Version**: 3.1.0 (Stable)
