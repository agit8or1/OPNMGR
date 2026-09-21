-- Record what a scan actually consumed.
--
-- Every provider reports its own token usage on the response. ai_scan.php
-- decoded that field and discarded it, so there was no way to say what a scan
-- cost - and the settings page could only offer a hand-written cost band, which
-- cannot be right for a given account because per-token pricing differs by
-- account and changes without the code changing.
--
-- Scans recorded before this migration have no figures. The columns are NULL
-- rather than 0 for exactly that reason, and the settings page filters on
-- `total_tokens IS NOT NULL` so a scan that was never measured is reported as
-- unmeasured rather than as free.

-- IF NOT EXISTS because database/schema.sql already carries these columns and
-- ships with an empty schema_migrations table: a fresh install loads the schema
-- and then runs every migration, so one that is not idempotent fails the install
-- with "Duplicate column name". Every other ALTER in this directory is written
-- the same way; this one was not, and CI caught it.
ALTER TABLE ai_scan_reports
  ADD COLUMN IF NOT EXISTS prompt_tokens INT NULL AFTER scan_duration,
  ADD COLUMN IF NOT EXISTS completion_tokens INT NULL AFTER prompt_tokens,
  ADD COLUMN IF NOT EXISTS total_tokens INT NULL AFTER completion_tokens;
