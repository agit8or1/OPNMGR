-- ---------------------------------------------------------------------------
-- 0003_structured_commands
--
-- Separates remote operations into structured actions (validated action id +
-- parameters) and raw shell, and records who queued what against which
-- firewall (P0 #5 / Phase 7).
--
-- Existing rows keep working: action is NULL for anything queued before this
-- migration and is_raw defaults to 1, which is how legacy free-form commands
-- should be classified.
-- ---------------------------------------------------------------------------

ALTER TABLE firewall_commands
    ADD COLUMN IF NOT EXISTS action VARCHAR(64) NULL
        COMMENT 'Structured action id, e.g. service_restart; NULL for raw shell',
    ADD COLUMN IF NOT EXISTS parameters TEXT NULL
        COMMENT 'JSON parameters for the structured action, validated server-side',
    ADD COLUMN IF NOT EXISTS is_raw TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '1 = free-form shell (privileged); 0 = structured action',
    ADD COLUMN IF NOT EXISTS risk_level ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'MEDIUM',
    ADD COLUMN IF NOT EXISTS queued_by_user_id INT(11) NULL,
    ADD COLUMN IF NOT EXISTS queued_by_username VARCHAR(64) NULL
        COMMENT 'Denormalised so the audit trail survives user deletion',
    ADD COLUMN IF NOT EXISTS queued_from_ip VARCHAR(45) NULL;

ALTER TABLE firewall_commands
    ADD INDEX IF NOT EXISTS idx_action (action),
    ADD INDEX IF NOT EXISTS idx_is_raw (is_raw),
    ADD INDEX IF NOT EXISTS idx_queued_by (queued_by_user_id);

INSERT IGNORE INTO settings (`name`, `value`) VALUES
    ('raw_command_enabled',        '1'),
    ('raw_command_admin_only',     '1');
