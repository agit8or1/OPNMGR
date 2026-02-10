# Claude Coder Handoff Package
## Complete Technical Transfer for OPNManager System

### 📦 HANDOFF PACKAGE CONTENTS

This directory contains everything Claude Coder needs to immediately take over and resolve all OPNManager issues:

```
/var/www/opnsense/
├── 📄 TECHNICAL_HANDOFF.md     # Complete system documentation
├── 📄 SYSTEM_FLOWCHART.md      # Visual architecture & flow diagrams  
├── 📄 CLAUDE_CODER_GUIDE.md    # This file - Quick start guide
├── 🔧 emergency_agent_fix.php   # Ready-to-deploy agent fix
├── 🔧 create_correct_tables.sql # Database schema fixes
├── 📁 .archive/                # Backup files for rollback
└── 📁 api/                     # API endpoints needing fixes
```

### 🎯 IMMEDIATE PRIORITIES (Start Here)

#### Priority 1: Agent Consolidation (30 min)
**Problem**: Two agents running (v3.5.2, v3.6.1), updates ignored  
**File**: `/var/www/opnsense/agent_checkin.php` Line 407  
**Fix**: Add HTTP 500 response for old versions to force restart

#### Priority 2: Chart Authentication (30 min)  
**Problem**: Latency/SpeedTest charts empty due to auth errors  
**Files**: `api/get_latency_stats.php`, `api/get_speedtest_results.php`  
**Fix**: Add local authentication bypass

#### Priority 3: Hanging API (20 min)
**Problem**: System stats API hangs, charts show "Loading..."  
**File**: `api/get_system_stats.php`  
**Fix**: Add execution timeouts and error handling

### 🔍 CRITICAL CONTEXT

**System**: OPNManager - OPNsense Firewall Management Platform  
**Environment**: Ubuntu 22.04, nginx 1.24.0, PHP 8.3-FPM, MySQL 8.0  
**Domain**: https://opn.agit8or.net  
**Firewall**: 73.35.46.112 (OPNsense with multiple agent versions)

**Current State**: 
- ✅ Traffic charts working (fixed blur issues)  
- ❌ Multiple agents running, updates ignored
- ❌ Latency/SpeedTest charts empty (auth errors)
- ❌ System charts hanging (API timeout)
- ❌ Agent audit trail missing (database issues)

### 🚀 QUICK START SEQUENCE

1. **Read TECHNICAL_HANDOFF.md** (5 min)
   - Complete system architecture
   - Database schema  
   - Issue analysis

2. **Review SYSTEM_FLOWCHART.md** (5 min)
   - Visual flow diagrams
   - Problem dependencies
   - Solution architecture

3. **Execute fixes in priority order** (90 min)
   - Use exact code provided in TECHNICAL_HANDOFF.md
   - Test after each fix using provided commands
   - Verify success criteria

4. **Validate complete solution** (15 min)
   - All charts displaying data
   - Single agent running v3.7.0
   - No errors in logs

### 🛠️ DEVELOPMENT ENVIRONMENT READY

**Access**: SSH to administrator@192.168.22.210  
**Web Root**: `/var/www/opnsense/`  
**Database**: MySQL socket auth (no password needed with sudo)  
**Logs**: `/var/log/nginx/error.log`, `/var/log/php8.3-fpm.log`  
**Services**: `sudo systemctl restart php8.3-fpm nginx mysql`

**Testing URLs**:
- Main dashboard: https://opn.agit8or.net/firewall_details.php?id=21
- API testing: https://opn.agit8or.net/api/get_*_stats.php?firewall_id=21&days=1

### 📋 SUCCESS CHECKLIST

#### Agent Management ✅
- [ ] Only one agent version (v3.7.0) in logs
- [ ] Checkin interval exactly 120 seconds  
- [ ] No "FORCE UPDATE" error messages
- [ ] `firewall_agents` table shows single record

#### Chart Functionality ✅
- [ ] Latency chart displays data points
- [ ] SpeedTest chart displays data points
- [ ] System charts (CPU/Memory/Disk) load quickly
- [ ] No authentication errors in browser console

#### System Health ✅  
- [ ] All API endpoints respond within 5 seconds
- [ ] No hanging PHP processes
- [ ] Database queries complete successfully
- [ ] Clean error logs (no critical issues)

### 🆘 EMERGENCY CONTACTS

**If Stuck**: All information is in TECHNICAL_HANDOFF.md  
**Rollback**: Backup files in `.archive/` directory  
**Database**: Use socket authentication with sudo  
**Logs**: Real-time monitoring with `sudo tail -f /var/log/nginx/error.log`

### 📊 ESTIMATED EFFORT

**Total Time**: 2 hours focused work  
**Complexity**: Medium (well-documented, clear fixes)  
**Risk**: Low (stable system, reversible changes)  
**Impact**: High (resolves all major user-facing issues)

---

**Ready for Claude Coder takeover. All technical context, exact fixes, and validation procedures are documented. Start with TECHNICAL_HANDOFF.md for complete details.**