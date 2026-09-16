-- Retention for the telemetry and log tables, which nothing has ever pruned.
--
-- On the maintainer's installation - two firewalls, nine months - these four
-- tables were 96% of a 174 MB database and growing by roughly 1,800 rows per
-- firewall per day with no upper bound.
--
-- log_retention_days already existed here, set to 90, and was read by no code
-- at all: the only caller of cleanup_old_logs() is a manual endpoint passing a
-- hardcoded 30, and it is in no crontab. The setting is honoured now by
-- cron/prune_telemetry.php, which reports by default and deletes only with
-- --apply.
--
-- Idempotent: safe to re-run.

INSERT INTO settings (`name`, `value`)
VALUES ('telemetry_retention_days', '90')
ON DUPLICATE KEY UPDATE `name` = `name`;

-- log_retention_days may predate this; leave any existing value alone.
INSERT INTO settings (`name`, `value`)
VALUES ('log_retention_days', '90')
ON DUPLICATE KEY UPDATE `name` = `name`;

-- Register the job so the Scheduled Jobs page reports it once it is scheduled.
-- expected_interval_minutes is left NULL: it is not in any crontab by default,
-- so it must not be flagged overdue for an operator who has chosen not to run
-- it. Set it to 1440 when you add the daily cron entry.
INSERT INTO scheduled_tasks
    (task_name, description, schedule, script_path, expected_interval_minutes, enabled)
VALUES
    ('prune_telemetry',
     'Applies retention to the telemetry and system log tables.',
     'Not scheduled - add to crontab with --apply',
     'cron/prune_telemetry.php',
     NULL,
     1)
ON DUPLICATE KEY UPDATE
    description = VALUES(description),
    script_path = VALUES(script_path);
