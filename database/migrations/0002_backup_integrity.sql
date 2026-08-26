-- ---------------------------------------------------------------------------
-- 0002_backup_integrity
--
-- Moves configuration backups out of the document root and records integrity
-- metadata for them (Phase 8).
--
-- Historically backups were written to /var/www/opnsense/backups, which nginx
-- serves and executes PHP from. New backups are stored under
-- /var/lib/opnmgr/backups with a server-generated name; storage_path records
-- where a row's bytes actually live. Rows with a NULL storage_path fall back to
-- the legacy directory so existing backups stay downloadable.
-- ---------------------------------------------------------------------------

ALTER TABLE backups
    ADD COLUMN IF NOT EXISTS storage_path VARCHAR(512) NULL
        COMMENT 'Absolute path outside the document root; NULL means legacy /var/www/opnsense/backups',
    ADD COLUMN IF NOT EXISTS checksum_sha256 CHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS validated TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 when the stored file parsed as a usable OPNsense config',
    ADD COLUMN IF NOT EXISTS validation_error VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS uploaded_at TIMESTAMP NULL DEFAULT NULL
        COMMENT 'When bytes actually arrived, as opposed to when the row was queued',
    ADD COLUMN IF NOT EXISTS source_filename VARCHAR(255) NULL
        COMMENT 'Agent-supplied name, kept as a label only; never used as a path';

ALTER TABLE backups
    ADD INDEX IF NOT EXISTS idx_firewall_created (firewall_id, created_at),
    ADD INDEX IF NOT EXISTS idx_uploaded_at (uploaded_at);

-- Track the last successful backup per firewall so the dashboard can flag gaps
-- without scanning the whole backups table.
ALTER TABLE firewalls
    ADD COLUMN IF NOT EXISTS last_backup_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS last_backup_status VARCHAR(32) NULL,
    ADD COLUMN IF NOT EXISTS last_backup_error VARCHAR(255) NULL;

INSERT IGNORE INTO settings (`name`, `value`) VALUES
    ('backup_storage_path',   '/var/lib/opnmgr/backups'),
    ('backup_retention_days', '90'),
    ('backup_max_bytes',      '52428800');
