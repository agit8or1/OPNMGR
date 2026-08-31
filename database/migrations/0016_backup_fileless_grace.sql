-- Grace window before a backup row with no file is reaped.

-- A `backups` row is created when the upload is queued, not when it arrives, so
-- there is always a window where the file legitimately does not exist yet.
-- Commands time out after 10 minutes and agents check in every couple of
-- minutes, so a row still fileless after this window is never getting one: the
-- firewall was offline, or the upload was rejected.

-- Before this, nothing reaped those rows. `record_backup_failure()` annotates a
-- rejected upload but keeps the row, and `cron/prune_backups.php` pruned only by
-- age, so they accumulated and inflated the backup count. This installation was
-- carrying 170 of them, which made the backup list read as 183 backups when 13
-- were real (see CHANGELOG 3.20.3 and 3.20.5).

-- 0 disables reaping.
INSERT IGNORE INTO settings (`name`, `value`) VALUES
    ('backup_fileless_grace_hours', '48');
