-- ---------------------------------------------------------------------------
-- 0009_config_drift
--
-- Configuration drift management (P1 #12), built on the existing configuration
-- backups rather than a parallel snapshot system.
--
-- A baseline is a specific backup an operator has declared correct. Drift is
-- the comparison of the newest backup against that baseline, using the
-- canonical fingerprint from inc/config_drift.php (which ignores serialisation
-- noise and volatile fields such as the <revision> block OPNsense stamps on
-- every save).
--
-- Drift is never acted on automatically: detecting a change records a finding,
-- it does not restore anything.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS config_baselines (
    id             INT(11) NOT NULL AUTO_INCREMENT,
    firewall_id    INT(11) NOT NULL,
    backup_id      INT(11) NOT NULL COMMENT 'The backups row declared correct',
    config_hash    CHAR(64) NOT NULL COMMENT 'Canonical fingerprint of that config',
    section_hashes TEXT NULL COMMENT 'JSON map of section => hash, for section-level drift',
    set_by_user_id INT(11) NULL,
    set_by_username VARCHAR(64) NULL,
    set_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes          VARCHAR(255) NULL,
    is_current     TINYINT(1) NOT NULL DEFAULT 1
                   COMMENT 'Superseded baselines are retained for history',
    PRIMARY KEY (id),
    KEY idx_firewall_current (firewall_id, is_current),
    KEY idx_backup (backup_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS config_drift (
    id                INT(11) NOT NULL AUTO_INCREMENT,
    firewall_id       INT(11) NOT NULL,
    baseline_id       INT(11) NOT NULL,
    current_backup_id INT(11) NULL,
    current_hash      CHAR(64) NULL,
    status            ENUM('match','drifted','unknown','error') NOT NULL DEFAULT 'unknown',
    sections_changed  TEXT NULL COMMENT 'JSON: added / removed / modified section names',
    change_count      INT(11) NOT NULL DEFAULT 0,
    first_detected_at TIMESTAMP NULL DEFAULT NULL,
    last_checked_at   TIMESTAMP NULL DEFAULT NULL,
    acknowledged_at   TIMESTAMP NULL DEFAULT NULL,
    acknowledged_by   VARCHAR(64) NULL,
    acknowledged_note VARCHAR(255) NULL,
    detail            TEXT NULL COMMENT 'Error text when status = error',
    PRIMARY KEY (id),
    UNIQUE KEY uniq_firewall (firewall_id),
    KEY idx_status (status),
    KEY idx_first_detected (first_detected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Current drift state per firewall; one row per firewall';

INSERT IGNORE INTO settings (`name`, `value`) VALUES
    ('drift_check_enabled', '1'),
    ('drift_alert_enabled', '1');
