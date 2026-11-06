-- ---------------------------------------------------------------------------
-- 0031_auto_reset_default_off
--
-- scripts/auto_reset_stale_agents.php (hourly cron) could never complete a
-- reset before 3.79: a scope bug made it throw first. Fixing that would have
-- silently switched on automatic kill-and-reinstall of stale agents, including
-- SSH to the firewall. Automatic resets stay off until enabled here; resets an
-- operator requests from admin/reset_agent.php are unaffected.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO settings (`name`, `value`) VALUES ('auto_reset_stale_agents_enabled', '0');
