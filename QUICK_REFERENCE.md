# OPNManager Quick Reference

## Current Status (2025-10-09 00:45)
- **Version**: 3.1.0
- **Agent**: v3.1.0 (STABLE)
- **Firewall #21**: ✅ ONLINE, checking in every 120s
- **Backup**: `/home/administrator/opnsense_backup_2025-10-09_STABLE_v3.1.tar.gz`

## Key Files
```
/var/www/opnsense/
├── agent_checkin.php          # Agent endpoint (NO rate limiting)
├── downloads/
│   └── opnsense_agent_v3.1_fw21.sh  # Production agent
├── inc/
│   ├── header.php             # Global CSS (z-index fix, awaiting-data style)
│   └── footer.php             # Bootstrap JS includes
├── firewall_edit.php          # Edit page (dropdowns, agent info)
├── firewall_details.php       # Details page (FIXED JavaScript)
└── settings.php               # Settings (NO proxy section)
```

## Agent v3.1.0 Key Features
- **PID Locking**: Built-in from line 1, uses `/var/run/opnsense_agent.pid`
- **Explicit Paths**: `/usr/local/bin/curl`, `/sbin/ifconfig`
- **Inline JSON**: No heredoc issues in background execution
- **Interval**: 120 seconds (configurable via server response)
- **Logging**: `/var/log/opnsense_agent.log`

## Common Commands

### Check Agent Status
```bash
# On firewall (FreeBSD/csh):
ps aux | grep opnsense_agent
tail -20 /var/log/opnsense_agent.log
cat /var/run/opnsense_agent.pid

# On server:
php -r "require '/var/www/opnsense/inc/db.php'; \$stmt = \$DB->query('SELECT last_checkin, TIMESTAMPDIFF(SECOND, last_checkin, NOW()) as ago FROM firewalls WHERE id=21'); \$r = \$stmt->fetch(); echo \"Last: {\$r['last_checkin']} ({\$r['ago']}s ago)\n\";"
```

### Restart Agent
```bash
# On firewall:
pkill -9 -f opnsense_agent
rm -f /var/run/opnsense_agent.pid
/usr/local/bin/opnsense_agent.sh >& /dev/null &
```

### Check Recent Logs
```bash
# On server:
tail -50 /var/log/nginx/access.log | grep agent_checkin
grep "firewall_id=21" /var/www/opnsense/inc/db.php  # Check DB connection
```

### Emergency: Kill All Agents (REBUILD BLOCK)
See `/var/www/opnsense/EMERGENCY_FIX_OCT8.md` for full procedure

## FreeBSD/csh Shell Differences
```bash
# BASH                          # CSH/TCSH (FreeBSD)
cmd > file 2>&1                 cmd >& file
cmd 2>&1 | grep                 cmd |& grep  
export VAR=value                setenv VAR value
$VAR                            $VAR (same)
#!/bin/bash                     #!/bin/sh (use sh not csh for scripts)
```

## Database Schema
```sql
-- Key tables:
firewalls              # Main firewall records, last_checkin timestamp
firewall_agents        # Agent version tracking (primary/update)
firewall_commands      # Command queue: pending → sent → completed
system_logs            # All logging (category='agent' for check-ins)
backups                # Configuration backups
tags                   # Firewall tags
customers              # Customer organizations
```

## UI Styling Classes
```css
.awaiting-data         # Orange, italic, bold (line 185+ in header.php)
.dropdown-menu         # z-index: 9999 (prevents overlap issues)
```

## Known Issues & Workarounds
1. **Command queue needs active agent** - Catch-22 when agent down
   - Workaround: Manual SSH access or REBUILD BLOCK strategy
   
2. **Update agent not deployed** - Commands queued (#775-777, #812-816)
   - Next step: Deploy once primary stable 24h

3. **Proxy tunnels untested** - Connect Now button functionality unknown
   - Next step: Test with active agent

## Next Session TODO
- [ ] Test proxy tunnel (Connect Now button)
- [ ] Deploy update agent (wait 24h stability)
- [ ] Add agent to rc.local for auto-start on reboot
- [ ] Create remote management API (restart/update/die commands)
- [ ] Update enrollment downloads to v3.1 for all new firewalls
- [ ] Monitor firewall #21 for 24 hours (ensure no duplicates)

## Breadcrumbs for AI
- Agent v3.0 had heredoc/background execution issues → v3.1 uses inline JSON
- Rate limiting was removed - rely on PID locking instead
- All UI dropdowns use Bootstrap 5.3.3 (loaded via footer.php)
- FreeBSD uses csh/tcsh shell - watch syntax in command queue
- Check-ins log to system_logs with category='agent'

## Backup/Restore
```bash
# Backup (run as administrator):
cd /home/administrator
sudo tar -czf opnsense_backup_$(date +%Y-%m-%d).tar.gz -C /var/www opnsense

# Restore:
cd /var/www
sudo tar -xzf /home/administrator/opnsense_backup_2025-10-09_STABLE_v3.1.tar.gz
sudo chown -R administrator:administrator opnsense
sudo chmod 755 opnsense
```

## Support Contacts
- Main firewall: home.agit8or.net (73.35.46.112)
- Management URL: https://opn.agit8or.net
- Database: MySQL/MariaDB on localhost
- Web server: Nginx + PHP 8.3-FPM

---
**Last Updated**: 2025-10-09 01:10 UTC
**Session**: Major agent overhaul & UI fixes
**Status**: ✅ STABLE - All critical functions working
