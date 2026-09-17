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

ALTER TABLE ai_scan_reports
  ADD COLUMN prompt_tokens INT NULL AFTER scan_duration,
  ADD COLUMN completion_tokens INT NULL AFTER prompt_tokens,
  ADD COLUMN total_tokens INT NULL AFTER completion_tokens;
