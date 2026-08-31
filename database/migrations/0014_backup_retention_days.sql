-- Backup retention expressed in days, and actually enforced.
--
-- `backup_retention_days` has existed since 0002 and nothing ever read it.
-- Meanwhile settings.php wrote `backup_retention_months` / `backup_retention_type`
-- / `backup_min_keep` / `backup_max_keep`, which nothing read either. The result
-- was a retention policy that appeared configured and pruned nothing: this
-- installation held 519 backups going back to 2025-11-10 under a nominal
-- 1-month policy.
--
-- cron/prune_backups.php (3.20.0) now enforces `backup_retention_days` together with
-- `backup_retention_min_keep`, and this migration makes those two the only
-- retention settings.

-- Seed both keys for installations that predate them.
INSERT IGNORE INTO settings (`name`, `value`) VALUES
    ('backup_retention_days',     '90'),
    ('backup_retention_min_keep', '3');

-- Carry an existing months-based policy across rather than silently widening it
-- to the 90-day default. Only applies where the operator had chosen time-based
-- retention; a count-based policy has no day equivalent and keeps the default.
UPDATE settings s
  JOIN (SELECT `value` AS months FROM settings WHERE `name` = 'backup_retention_months') m
  JOIN (SELECT `value` AS rtype  FROM settings WHERE `name` = 'backup_retention_type') t
   SET s.`value` = CAST(GREATEST(1, CAST(m.months AS UNSIGNED) * 30) AS CHAR)
 WHERE s.`name` = 'backup_retention_days'
   AND t.rtype  = 'time'
   AND m.months REGEXP '^[0-9]+$';

-- The superseded keys are no longer read by anything.
DELETE FROM settings
 WHERE `name` IN ('backup_retention_months', 'backup_retention_type',
                  'backup_min_keep', 'backup_max_keep');
