# Nightly Automatic Backups - Setup Complete

## Overview
Automatic nightly backups are now configured to run at 2:00 AM every night.

## What Was Fixed

### Problem
- No automated backup system existed
- Last backup: October 4, 2025 (4 days ago)
- Manual backups only

### Solution
- Created `/var/www/opnsense/cron/nightly_backups.php`
- Added cron job to run nightly at 2:00 AM
- Logs to `/var/www/opnsense/logs/nightly_backups.log`

## How It Works

1. **Script runs at 2:00 AM** via cron
2. **Finds active firewalls** (status = online/offline, not archived)
3. **Checks eligibility**:
   - Must have checked in within 24 hours
   - Must have API credentials
   - Must not already have backup today
4. **Queues backup command** via agent system
5. **Agent executes**:
   - Downloads backup from firewall
   - Uploads to management server
   - Cleans up temp files

## Backup Command Template
```bash
curl -k -u 'api_key:api_secret' \
  'https://<firewall_ip>/api/core/backup/download/this' > /tmp/backup.xml && \
curl -k -F 'backup_file=@/tmp/backup.xml' \
  -F 'firewall_id=<id>' \
  -F 'backup_type=automatic' \
  'https://opn.agit8or.net/api/upload_backup.php' && \
rm -f /tmp/backup.xml
```

## Cron Schedule
```cron
# Nightly automatic backups at 2 AM
0 2 * * * /usr/bin/php /var/www/opnsense/cron/nightly_backups.php >> /var/www/opnsense/logs/nightly_backups.log 2>&1
```

## Verification

### Check Cron Job
```bash
crontab -l | grep backup
```

### View Recent Log
```bash
tail -30 /var/www/opnsense/logs/nightly_backups.log
```

### Check Today's Backups
```sql
SELECT f.id, f.hostname, b.created_at, b.backup_type, b.file_size
FROM backups b
JOIN firewalls f ON b.firewall_id = f.id
WHERE DATE(b.created_at) = CURDATE()
ORDER BY b.created_at DESC;
```

### Manual Test Run
```bash
php /var/www/opnsense/cron/nightly_backups.php
```

## Backup Logic

### Firewalls Included
- Status: `online` or `offline` (not `archived`)
- Last check-in: Within 24 hours
- Has API credentials configured

### Firewalls Skipped
- ❌ Offline > 24 hours
- ❌ Missing API credentials
- ❌ Backup already exists for today
- ❌ Status = archived

### Current Status
- **FW #21 (home.agit8or.net)**: ✅ Backing up nightly
- **FW #3 (fw01.companyB.com)**: ❌ Skipped (offline >2 months)

## Log Output Example
```
[2025-10-08 02:00:01] === Starting Nightly Backup Job ===
[2025-10-08 02:00:01] Found 2 firewalls to process
[2025-10-08 02:00:01] Processing FW #3 (fw01.companyB.com) - Status: offline
[2025-10-08 02:00:01]   SKIP: Firewall offline for >24 hours
[2025-10-08 02:00:01] Processing FW #21 (home.agit8or.net) - Status: online
[2025-10-08 02:00:01]   SUCCESS: Backup command queued (command_id: 770)
[2025-10-08 02:00:02] === Backup Job Complete ===
[2025-10-08 02:00:02] Total: 2, Successful: 1, Failed: 0, Skipped: 1
```

## Files Created/Modified

### New Files
- `/var/www/opnsense/cron/nightly_backups.php` - Backup automation script
- `/var/www/opnsense/logs/nightly_backups.log` - Log file

### Modified Files
- Crontab: Added nightly backup job at 2:00 AM

## Backup Retention

Backups are stored in the `backups` table and files in `/var/www/opnsense/backups/`.

Current API (`/api/get_backups.php`) shows backups from last 60 days:
```sql
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
```

## Monitoring

### Check for Failed Backups
```sql
SELECT 
    fc.id,
    f.hostname,
    fc.description,
    fc.status,
    fc.result,
    fc.created_at
FROM firewall_commands fc
JOIN firewalls f ON fc.firewall_id = f.id
WHERE fc.description LIKE '%Nightly%'
AND fc.status = 'failed'
AND DATE(fc.created_at) = CURDATE();
```

### Check Backup Success Rate
```sql
SELECT 
    DATE(created_at) as date,
    COUNT(*) as backups_created,
    COUNT(DISTINCT firewall_id) as firewalls_backed_up
FROM backups
WHERE backup_type = 'automatic'
GROUP BY DATE(created_at)
ORDER BY date DESC
LIMIT 30;
```

### Alert on Missing Backups
```sql
-- Firewalls that should have backups but don't
SELECT 
    f.id,
    f.hostname,
    f.last_checkin,
    MAX(b.created_at) as last_backup
FROM firewalls f
LEFT JOIN backups b ON f.id = b.firewall_id
WHERE f.status = 'online'
AND f.last_checkin > DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY f.id
HAVING last_backup IS NULL 
    OR last_backup < DATE_SUB(NOW(), INTERVAL 2 DAY);
```

## Troubleshooting

### Backups Not Running
1. Check cron is active: `systemctl status cron`
2. Check crontab: `crontab -l`
3. Check permissions: `ls -la /var/www/opnsense/cron/nightly_backups.php`
4. Check log: `tail -50 /var/www/opnsense/logs/nightly_backups.log`

### Commands Failing
1. Check agent is online
2. Verify API credentials in database
3. Check firewall can reach itself: `curl -k https://localhost/api/core/backup/download/this`
4. Test upload endpoint: Check `/api/upload_backup.php`

### No Backups Created
1. Check if commands are being queued: `SELECT * FROM firewall_commands WHERE description LIKE '%Nightly%' ORDER BY id DESC LIMIT 5`
2. Check command status and results
3. Verify agent is processing commands
4. Check agent logs

## Next Steps

1. **Monitor first automatic run** (tomorrow at 2:00 AM)
2. **Verify backups appear** in database and web UI
3. **Set up alerts** for failed backups (optional)
4. **Configure retention** policy if needed (currently 60 days)

## Related Documentation
- `/var/www/opnsense/WORK_SUMMARY_OCT08.md` - Today's work summary
- `/var/www/opnsense/FIREWALL_STATUS_REPORT.md` - System status
- `/var/www/opnsense/firewall_details.php` - Backup management UI

---

**Status**: ✅ Nightly backups configured and tested  
**Next Run**: Tomorrow at 2:00 AM  
**Current Test**: Command #770 queued for FW #21
