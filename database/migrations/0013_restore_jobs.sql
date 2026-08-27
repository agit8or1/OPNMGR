-- ---------------------------------------------------------------------------
-- 0013_restore_jobs
--
-- Configuration restore safety (P2 #21 / Phase 8).
--
-- A restore overwrites a live firewall, so it is tracked as a job rather than a
-- fire-and-forget command: which backup, which pre-restore snapshot was taken
-- first, who asked for it, and what actually happened afterwards.
--
-- "Completed successfully" is not inferred from the command exiting zero. The
-- job stays in verifying until the agent has checked in again after the
-- restore, which is the only evidence the firewall actually came back.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS config_restores (
    id                 INT(11) NOT NULL AUTO_INCREMENT,
    firewall_id        INT(11) NOT NULL,
    backup_id          INT(11) NOT NULL COMMENT 'The configuration being restored',
    pre_restore_backup_id INT(11) NULL COMMENT 'Snapshot taken before overwriting',
    status             ENUM('pending','pre_backup','dispatched','verifying','succeeded','failed','cancelled')
                       NOT NULL DEFAULT 'pending',
    command_id         INT(11) NULL,
    fetch_token        CHAR(64) NULL
                       COMMENT 'Single-use token the agent presents to fetch the config',
    token_used_at      TIMESTAMP NULL DEFAULT NULL,
    requested_by_user_id INT(11) NULL,
    requested_by_username VARCHAR(64) NULL,
    requested_from_ip  VARCHAR(45) NULL,
    reason             VARCHAR(255) NULL,
    detail             TEXT NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    dispatched_at      TIMESTAMP NULL DEFAULT NULL,
    completed_at       TIMESTAMP NULL DEFAULT NULL,
    checkin_before     TIMESTAMP NULL DEFAULT NULL
                       COMMENT 'Last check-in at dispatch, so a later one proves the agent returned',
    PRIMARY KEY (id),
    UNIQUE KEY uniq_fetch_token (fetch_token),
    KEY idx_firewall (firewall_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (`name`, `value`) VALUES
    ('restore_require_pre_backup', '1'),
    ('restore_verify_minutes',     '20');
