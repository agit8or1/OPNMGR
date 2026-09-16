-- Register every scheduled job, not just the six someone remembered.
--
-- 0018 rebuilt `scheduled_tasks` around the jobs that run, and listed six. The
-- crontab on the maintainer's installation carries fourteen. The eight that
-- were absent reported nothing, appeared nowhere, and could stop without
-- anything saying so - which is what happened:
--
--   monitor_agent_health and auto_reset_stale_agents stopped running on
--   2026-09-13. Their crontab lines redirect to a file in /var/log, and after
--   a rotation the user running them could no longer create it. cron logged
--   the command every cycle, the shell failed on the redirect, and the PHP
--   never started. Nothing noticed for three days. monitor_agent_health is the
--   only thing that maintains `firewalls.status`, so a firewall silent since
--   noon still read "online".
--
-- A job is watched only once it has run at least once. This application does
-- not own the crontab and cannot know which jobs an operator chose to schedule,
-- so the first recorded run arms the check and an unscheduled job stays quiet.
--
-- Idempotent: safe to re-run.

INSERT INTO scheduled_tasks (task_name, description, schedule, script_path, expected_interval_minutes, enabled)
VALUES
  ('nightly_backups',         'Configuration backup, second pass.',                      'Daily at 02:00',    'cron/nightly_backups.php',                1440, 1),
  ('monitor_agent_health',    'Marks firewalls offline and reports stuck commands.',     'Every 5 minutes',   'scripts/monitor_agent_health.php',           5, 1),
  ('auto_reset_stale_agents', 'Detects stale agents and attempts to restart them.',      'Hourly',            'scripts/auto_reset_stale_agents.php',       60, 1),
  ('tunnel_health_monitor',   'Re-establishes dead SSH tunnels and reaps expired ones.', 'Every 2 minutes',   'cron/tunnel_health_monitor.php',             2, 1),
  ('ssh_access_cleanup',      'Expires temporary SSH access sessions.',                  'Every 5 minutes',   'scripts/manage_ssh_access.php',              5, 1),
  ('nginx_tunnel_cleanup',    'Removes nginx proxy configs for finished sessions.',      'Every 5 minutes',   'scripts/manage_nginx_tunnel_proxy.php',      5, 1),
  ('schedule_speedtest',      'Queues speedtests that are due per firewall interval.',   'Hourly',            'api/schedule_speedtest.php',                60, 1),
  ('run_auto_scans',          'Runs AI security scans for firewalls that enabled them.', 'Daily at 02:00',    'scripts/run_auto_scans.php',              1440, 1)
ON DUPLICATE KEY UPDATE
  description               = VALUES(description),
  schedule                  = VALUES(schedule),
  script_path               = VALUES(script_path),
  expected_interval_minutes = VALUES(expected_interval_minutes);
