-- Drop the licensing subsystem.
--
-- OPNManager is MIT licensed and self-hosted, and the project has never had a
-- licence server: the four license_* tables this references were only ever
-- created by db/migrations/create_license_tables.sql, which scripts/migrate.php
-- does not read (it reads database/migrations/ only). They therefore did not
-- exist on any installation, including the maintainer's, so license_server.php
-- threw wherever it was opened.
--
-- deployed_instances did exist. Nothing reads it now that license_server.php and
-- inc/license_utils.php are gone.
--
-- Idempotent: DROP TABLE IF EXISTS is a no-op on a fresh schema, which is what
-- CI asserts about migrations.

DROP TABLE IF EXISTS `license_activity_log`;
DROP TABLE IF EXISTS `license_checkins`;
DROP TABLE IF EXISTS `license_api_keys`;
DROP TABLE IF EXISTS `license_tiers`;
DROP TABLE IF EXISTS `deployed_instances`;
