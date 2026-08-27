-- ---------------------------------------------------------------------------
-- 0012_fleet_updates_and_bulk
--
-- Fleet update management (P2 #16-18) and bulk operations (P2 #19).
--
-- Update rings are a rollout mechanism, NOT customer tiers: an MSP uses them to
-- prove an OPNsense release on a small set of its own managed firewalls before
-- rolling it out broadly. A canary firewall belongs to a customer like any
-- other; the ring says nothing about that customer's importance.
--
-- Progression between rings is manual by default. auto_progress exists but
-- defaults to 0, because "it worked on canary" is a judgement a technician
-- makes, not one a scheduler should make unattended.
-- ---------------------------------------------------------------------------

ALTER TABLE firewalls
    ADD COLUMN IF NOT EXISTS update_ring ENUM('canary','pilot','production')
        NOT NULL DEFAULT 'production'
        COMMENT 'Rollout ring. Not a customer tier.',
    ADD COLUMN IF NOT EXISTS last_update_result VARCHAR(32) NULL,
    ADD COLUMN IF NOT EXISTS last_update_error VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS last_update_attempt_at TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE firewalls
    ADD INDEX IF NOT EXISTS idx_update_ring (update_ring);

-- --- update campaigns ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS update_campaigns (
    id              INT(11) NOT NULL AUTO_INCREMENT,
    name            VARCHAR(255) NOT NULL,
    description     VARCHAR(512) NULL,
    target_version  VARCHAR(64) NULL COMMENT 'Informational: what we expect to end up on',
    operation       ENUM('check','install') NOT NULL DEFAULT 'install',
    status          ENUM('draft','running','paused','completed','cancelled')
                    NOT NULL DEFAULT 'draft',
    current_ring    ENUM('canary','pilot','production') NULL,
    auto_progress   TINYINT(1) NOT NULL DEFAULT 0
                    COMMENT 'Advance to the next ring without a human approving it',
    reboot_if_required TINYINT(1) NOT NULL DEFAULT 0,
    respect_maintenance TINYINT(1) NOT NULL DEFAULT 1
                    COMMENT 'Only dispatch inside a maintenance window when one is defined',
    ha_safe         TINYINT(1) NOT NULL DEFAULT 1
                    COMMENT 'Never dispatch to both members of a CARP pair at once',
    created_by_user_id INT(11) NULL,
    created_by_username VARCHAR(64) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at      TIMESTAMP NULL DEFAULT NULL,
    completed_at    TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS update_campaign_targets (
    id            INT(11) NOT NULL AUTO_INCREMENT,
    campaign_id   INT(11) NOT NULL,
    firewall_id   INT(11) NOT NULL,
    ring          ENUM('canary','pilot','production') NOT NULL,
    status        ENUM('pending','holding','dispatched','succeeded','failed','skipped')
                  NOT NULL DEFAULT 'pending',
    hold_reason   VARCHAR(255) NULL
                  COMMENT 'Why a target is not being dispatched yet, e.g. HA partner in progress',
    command_id    INT(11) NULL,
    version_before VARCHAR(64) NULL,
    version_after  VARCHAR(64) NULL,
    dispatched_at TIMESTAMP NULL DEFAULT NULL,
    completed_at  TIMESTAMP NULL DEFAULT NULL,
    result        TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_campaign_firewall (campaign_id, firewall_id),
    KEY idx_campaign_status (campaign_id, status),
    KEY idx_firewall (firewall_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- bulk operations -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS bulk_operations (
    id            INT(11) NOT NULL AUTO_INCREMENT,
    operation     VARCHAR(64) NOT NULL COMMENT 'Action id from the bulk catalogue',
    parameters    TEXT NULL COMMENT 'JSON, validated before dispatch',
    risk_level    ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'MEDIUM',
    target_count  INT(11) NOT NULL DEFAULT 0,
    succeeded     INT(11) NOT NULL DEFAULT 0,
    failed        INT(11) NOT NULL DEFAULT 0,
    status        ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    created_by_user_id INT(11) NULL,
    created_by_username VARCHAR(64) NULL,
    created_from_ip VARCHAR(45) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at  TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_created (created_at),
    KEY idx_operation (operation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bulk_operation_targets (
    id          INT(11) NOT NULL AUTO_INCREMENT,
    bulk_id     INT(11) NOT NULL,
    firewall_id INT(11) NOT NULL,
    status      ENUM('queued','succeeded','failed','skipped') NOT NULL DEFAULT 'queued',
    command_id  INT(11) NULL,
    detail      VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_bulk (bulk_id),
    KEY idx_firewall (firewall_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (`name`, `value`) VALUES
    ('update_ring_soak_hours',  '24'),
    ('update_ha_settle_minutes','10'),
    ('bulk_max_targets',        '200');
