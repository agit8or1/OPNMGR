# OPNManager Knowledge Base
## Internal troubleshooting and common issues reference

---

## Table of Contents
1. [Agent Issues](#agent-issues)
2. [UI/Frontend Issues](#uifrontend-issues)
3. [Database Issues](#database-issues)
4. [FreeBSD/Shell Issues](#freebsdshell-issues)
5. [Common Error Messages](#common-error-messages)

---

## Agent Issues

### Issue: Agent Running But Not Checking In
**Symptoms:**
- Agent process shows in `ps aux` on firewall
- Agent log shows "Check-in completed:" with empty response
- Server shows no check-ins in system_logs or nginx access logs

**Root Cause:**
- curl command failing silently in background execution context
- Heredoc JSON construction not working reliably in daemon processes
- Missing explicit paths to binaries

**Solution:**
1. Replace heredoc JSON with inline printf-style JSON
2. Add explicit paths: `/usr/local/bin/curl`, `/sbin/ifconfig`
3. Test curl manually to verify network connectivity
4. Check nginx logs: `tail -f /var/log/nginx/access.log | grep agent_checkin`

**Reference:** Session 2025-10-09, Agent v3.1.0

---

### Issue: Duplicate Agent Check-ins (782 in 5 minutes)
**Symptoms:**
- Rapid-fire check-ins every few seconds
- Multiple agent processes running (ps aux shows 30-40+ instances)
- Server rate limiting kicking in
- Database flooded with check-in logs

**Root Cause:**
- Missing or non-functional PID locking
- Agent script spawning multiple instances
- No mechanism to detect existing running agent

**Solution (REBUILD BLOCK Strategy):**
1. Add hard block in `agent_checkin.php`:
```php
if ($firewall_id === XX) {
    log_warning('agent', "REBUILD BLOCK: blocked during rebuild", null, $firewall_id);
    http_response_code(503);
    echo json_encode(['success' => false, 'die' => true]);
    exit;
}
```
2. Wait 2-3 minutes for all agents to timeout and die
3. Remove block
4. Deploy new agent with proper PID locking

**Prevention:**
- Ensure PID file check is FIRST thing in agent script (before any other code)
- Use trap to cleanup PID file on exit: `trap 'rm -f "$PIDFILE"' EXIT INT TERM`
- Test: `ps aux | grep opnsense_agent | wc -l` should always be 1

**Reference:** EMERGENCY_FIX_OCT8.md

---

### Issue: Command Queue Not Processing
**Symptoms:**
- Commands stuck in 'pending' status
- No execution on firewall despite agent check-ins

**Root Cause:**
- Agent not fetching commands from server response
- Agent crashed/stopped running (catch-22 - can't send commands to fix it)

**Solution:**
1. Verify agent is running: `ssh firewall "ps aux | grep opnsense_agent"`
2. Check agent log for command execution: `ssh firewall "tail -f /var/log/opnsense_agent.log"`
3. If agent down, manual SSH access required
4. Check agent code for command processing logic

**Workaround for Catch-22:**
- Maintain emergency SSH access to all production firewalls
- Use REBUILD BLOCK strategy to force agent restart
- Consider implementing out-of-band management (IPMI, iLO, etc.)

---

## UI/Frontend Issues

### Issue: Dropdowns Not Appearing or Behind Content
**Symptoms:**
- Dropdown menus appear behind cards/modals
- Click dropdown but nothing visible

**Root Cause:**
- z-index conflicts between Bootstrap components
- Position: relative on parent elements

**Solution:**
Add to `/var/www/opnsense/inc/header.php`:
```css
.dropdown-menu { 
    z-index: 9999!important; 
    position: absolute!important; 
}
```

**Reference:** Session 2025-10-09, header.php line 182

---

### Issue: Bootstrap Modal Not Opening
**Symptoms:**
- Click button, modal doesn't appear
- Console error: "Bootstrap is not defined"

**Root Cause:**
- Missing footer include (Bootstrap JS not loaded)
- Multiple footer includes (JS loaded twice, breaking initialization)

**Solution:**
1. Ensure footer is included ONCE per page: `<?php include __DIR__ . '/inc/footer.php'; ?>`
2. Check for duplicate includes: `grep -n "footer.php" filename.php`
3. Remove any middle-page footer includes

**Reference:** settings.php, firewall_details.php fixes

---

### Issue: JavaScript Errors - Malformed Closures
**Symptoms:**
- Console error: "Unexpected token ')'"
- Page functionality broken
- setTimeout/Promise closures incorrect

**Root Cause:**
- Bad sed edits leaving malformed `}, 100); });` closures
- Copy-paste errors with extra closing braces

**Solution:**
1. Find malformed closures: `grep -n "}, 100); });" filename.php`
2. Fix to proper closure:
```javascript
// BAD:
.catch(error => {
    alert('Error');
}, 100); });

// GOOD:
.catch(error => {
    alert('Error');
});
```

**Reference:** firewall_details.php lines 434, 466

---

## Database Issues

### Issue: Check-ins Not Logging
**Symptoms:**
- `SELECT * FROM system_logs WHERE category='agent'` returns nothing
- Agent appears to check in but no database records

**Root Cause:**
- PHP error in agent_checkin.php preventing log writes
- Database connection issue
- Logging function not called

**Diagnostic:**
1. Check PHP error log: `tail -f /var/log/php8.3-fpm.log`
2. Check nginx error log: `tail -f /var/log/nginx/error.log`
3. Test logging manually:
```php
require '/var/www/opnsense/inc/logging.php';
log_info('test', 'Test message', null, 21);
```

---

## FreeBSD/Shell Issues

### Issue: "Ambiguous output redirect" Error
**Symptom:**
```bash
command > file 2>&1
# Error: Ambiguous output redirect
```

**Root Cause:**
- FreeBSD uses csh/tcsh, not bash
- Different redirect syntax

**Solution:**
```bash
# BASH syntax (doesn't work on FreeBSD):
command > file 2>&1

# CSH/TCSH syntax (FreeBSD):
command >& file

# Pipe with stderr:
command |& grep something
```

---

### Issue: "Event not found" with ! in Echo
**Symptom:**
```bash
echo '#!/bin/sh'
# Error: Event not found.
```

**Root Cause:**
- csh history expansion treats `!` as special
- Interactive shell interprets history commands

**Solution:**
```bash
# Escape the exclamation:
echo '#\!/bin/sh'

# Or use heredoc:
cat > file << 'EOF'
#!/bin/sh
EOF
```

---

### Issue: "Badly placed ()'s" Error
**Symptom:**
```bash
VAR=$(command)
# Error: Badly placed ()'s.
```

**Root Cause:**
- csh doesn't support `$()` command substitution
- Must use backticks

**Solution:**
```bash
# BASH (doesn't work in csh):
VAR=$(command)

# CSH:
set VAR=`command`
```

---

## Common Error Messages

### "Content Security Policy directive: default-src 'self'"
**Cause:** Page trying to load external resources (CDN Bootstrap, fonts)
**Fix:** Ensure local copies of Bootstrap/jQuery are used, or update CSP headers

### "Cannot read property 'addEventListener' of null"
**Cause:** JavaScript trying to access element before DOM loaded
**Fix:** Wrap in `DOMContentLoaded` event listener

### "Call to undefined function log_info()"
**Cause:** Missing `require_once __DIR__ . '/inc/logging.php';`
**Fix:** Add require statement at top of file

### "SQLSTATE[HY000] [2002] Connection refused"
**Cause:** Database not running or wrong connection details
**Fix:** Check MySQL service: `systemctl status mysql`

### "Failed to open stream: Permission denied"
**Cause:** File permissions incorrect
**Fix:** `sudo chown -R administrator:administrator /var/www/opnsense`

---

## Diagnostic Commands Reference

### Check Agent Status
```bash
# On Firewall:
ps aux | grep opnsense_agent
tail -20 /var/log/opnsense_agent.log
cat /var/run/opnsense_agent.pid

# On Server:
tail -50 /var/log/nginx/access.log | grep agent_checkin
php -r "require '/var/www/opnsense/inc/db.php'; \$stmt = \$DB->query('SELECT last_checkin FROM firewalls WHERE id=21'); echo \$stmt->fetchColumn();"
```

### Check System Logs
```bash
# Agent check-ins:
SELECT * FROM system_logs WHERE firewall_id=21 AND category='agent' ORDER BY timestamp DESC LIMIT 20;

# Recent errors:
SELECT * FROM system_logs WHERE level='error' ORDER BY timestamp DESC LIMIT 50;

# Command queue:
SELECT * FROM firewall_commands WHERE firewall_id=21 ORDER BY created_at DESC LIMIT 10;
```

### Verify File Integrity
```bash
php -l /var/www/opnsense/filename.php  # Check PHP syntax
grep -n "TODO\|FIXME\|XXX" /var/www/opnsense/*.php  # Find unfinished code
find /var/www/opnsense -name "*.php" -mtime -1  # Files modified in last 24h
```

---

## Best Practices

### Before Making Changes
1. ✅ Create backup: `sudo tar -czf backup_$(date +%Y%m%d).tar.gz -C /var/www opnsense`
2. ✅ Document in CHANGELOG
3. ✅ Test on dev/staging first if possible
4. ✅ Have rollback plan ready

### After Making Changes
1. ✅ Check PHP syntax: `php -l filename.php`
2. ✅ Clear opcode cache if needed: `sudo systemctl reload php8.3-fpm`
3. ✅ Monitor logs for 5-10 minutes
4. ✅ Update documentation

### Emergency Response
1. 🚨 Don't panic - all issues are recoverable
2. 🚨 Check backups exist before major fixes
3. 🚨 Document steps taken for future reference
4. 🚨 When in doubt, REBUILD BLOCK strategy works

---

## Version History of This KB
- 2025-10-09: Initial creation after Agent v3.1.0 deployment
- Include lessons learned from duplicate agent crisis
- FreeBSD shell syntax differences documented

---

**Last Updated:** 2025-10-09 01:30 UTC
**Maintainer:** System Administrator
**Status:** Living document - update as new issues discovered
