# OPNManager Changelog - October 9, 2025

## Version 3.1.0 - MAJOR AGENT OVERHAUL

### Critical Fixes
- **Agent v3.0 → v3.1**: Complete rewrite to fix background execution issues
  - Replaced heredoc JSON construction with printf-style inline JSON
  - Added explicit paths to all binaries (`/usr/local/bin/curl`, `/sbin/ifconfig`)
  - Fixed shell context issues causing silent curl failures
  - Agent now successfully checks in every 120 seconds

### Agent Issues Resolved
- **FIXED**: Agent running but not checking in (curl failing in background context)
- **FIXED**: 782 duplicate check-ins in 5 minutes (39-41 concurrent agents)
- **FIXED**: PID locking now built-in from line 1 (not bolt-on)
- **FIXED**: Rate limiting removed (was bandaid, v3.1 has proper PID locking)

### UI Improvements
- **FIXED**: Backup retention modal not opening (footer.php now included)
- **FIXED**: Dropdown z-index issues (global fix in header.php)
- **FIXED**: Customer group dropdown now pulls from `customers` table
- **FIXED**: Tags now multi-select dropdown with colored bullets
- **FIXED**: "Awaiting Agent Data" styling - orange, italic, bold for visibility
- **FIXED**: Firewall details page JavaScript errors (malformed setTimeout closures)
- **FIXED**: Duplicate footer includes breaking Bootstrap
- **REMOVED**: Breadcrumb navigation from firewall edit page
- **REMOVED**: Proxy settings from settings page (hardcoded 8100-8200)

### Database Changes
- Agent check-ins now properly logged to `system_logs` table
- WAN IP, LAN IP, OPNsense version captured on every check-in
- Agent version tracking updated to 3.1.0

### Files Modified
1. `/var/www/opnsense/agent_checkin.php` - Removed rate limiting (lines 51-65)
2. `/var/www/opnsense/downloads/opnsense_agent_v3.1_fw21.sh` - New agent with explicit paths
3. `/var/www/opnsense/inc/header.php` - Added `.awaiting-data` styling (line 185+)
4. `/var/www/opnsense/firewall_edit.php` - Customer/tag dropdowns, agent info display
5. `/var/www/opnsense/firewall_details.php` - Fixed duplicate footer, malformed closures
6. `/var/www/opnsense/settings.php` - Removed proxy settings, added footer include

### Deployment Notes
- **Firewall #21 (home.agit8or.net)**: Running Agent v3.1.0 successfully
- Check-in interval: 120 seconds
- WAN IP captured: 73.35.46.112
- LAN IP captured: 10.0.0.1
- Status: ONLINE ✅

### Known Issues
- Update agent not yet deployed (commands queued but need successful check-in)
- Connect Now proxy tunnels not tested yet
- Command queue requires active agent (catch-22 when agent down)

### Next Steps
1. Deploy update agent once primary agent stable for 24 hours
2. Test proxy tunnel functionality
3. Add auto-start to firewall rc.local for persistence
4. Create remote management API for agent control
5. Update enrollment downloads to use Agent v3.1

### Emergency Procedures Documented
- `/var/www/opnsense/EMERGENCY_FIX_OCT8.md` - REBUILD BLOCK strategy
- Server-side block can kill all agents in ~2 minutes
- Manual deployment procedure for new agents
- FreeBSD/csh shell syntax differences documented

### Version Numbers
- **Agent**: 3.1.0
- **Management Server**: 1.2.0
- **Database Schema**: No changes
- **PHP**: 8.3-FPM
- **Bootstrap**: 5.3.3

---
**Session Duration**: ~4 hours
**Lines of Code Modified**: ~500+
**Files Touched**: 8
**Agent Restarts**: 15+
**Success Rate**: 100% after v3.1 deployment
