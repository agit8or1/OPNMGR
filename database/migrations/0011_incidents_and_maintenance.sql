-- ---------------------------------------------------------------------------
-- 0011_incidents_and_maintenance
--
-- Alert incidents (P1 #14) and maintenance windows (P1 #15).
--
-- The pre-existing alerting sent an email whenever a condition was true and a
-- crude 60-minute timer had elapsed. It had no notion of an ongoing problem, so
-- it could not say "still down", never said "back online", and had nothing to
-- acknowledge.
--
-- An incident is one ongoing problem: opened once, updated while it persists,
-- resolved when the condition clears. Notification is a separate decision from
-- detection, which is what allows suppression during maintenance without
-- losing the record that the condition occurred.
--
-- Deduplication uses dedupe_key, which is UNIQUE and set only while an incident
-- is active. Resolving nulls it, so the same condition recurring later opens a
-- new incident instead of colliding with the closed one.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS alert_incidents (
    id               BIGINT(20) NOT NULL AUTO_INCREMENT,
    dedupe_key       VARCHAR(191) NULL
                     COMMENT 'Set while active, NULL once resolved, so UNIQUE dedupes only open incidents',
    dedupe_source    VARCHAR(191) NOT NULL COMMENT 'The key this incident was raised under, kept for history',
    alert_type       VARCHAR(64) NOT NULL COMMENT 'e.g. firewall.offline, gateway.down',
    object_key       VARCHAR(128) NULL COMMENT 'Sub-object, e.g. the gateway or tunnel name',
    severity         ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
    status           ENUM('open','acknowledged','resolved') NOT NULL DEFAULT 'open',

    firewall_id      INT(11) NULL,
    customer_id      INT(11) NULL,
    site_id          INT(11) NULL,

    title            VARCHAR(255) NOT NULL,
    detail           TEXT NULL,
    metadata         TEXT NULL COMMENT 'JSON, credential material scrubbed',

    first_seen_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at      TIMESTAMP NULL DEFAULT NULL,
    occurrence_count INT(11) NOT NULL DEFAULT 1,

    acknowledged_at  TIMESTAMP NULL DEFAULT NULL,
    acknowledged_by  VARCHAR(64) NULL,
    acknowledged_note VARCHAR(255) NULL,

    notify_count     INT(11) NOT NULL DEFAULT 0,
    last_notified_at TIMESTAMP NULL DEFAULT NULL,
    suppressed       TINYINT(1) NOT NULL DEFAULT 0
                     COMMENT '1 when notification was withheld, e.g. during maintenance',
    suppressed_reason VARCHAR(128) NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_active_dedupe (dedupe_key),
    KEY idx_status (status),
    KEY idx_type (alert_type),
    KEY idx_firewall (firewall_id),
    KEY idx_customer (customer_id),
    KEY idx_first_seen (first_seen_at),
    KEY idx_severity (severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every state change is recorded. "Do not silently discard events" applies to
-- suppression too: a suppressed notification still leaves a trail.
CREATE TABLE IF NOT EXISTS alert_incident_events (
    id          BIGINT(20) NOT NULL AUTO_INCREMENT,
    incident_id BIGINT(20) NOT NULL,
    event       ENUM('opened','updated','acknowledged','resolved','notified',
                     'suppressed','reopened','escalated') NOT NULL,
    detail      VARCHAR(512) NULL,
    actor       VARCHAR(64) NULL COMMENT 'Username, or NULL for system',
    occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_incident (incident_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- maintenance windows -----------------------------------------------------
CREATE TABLE IF NOT EXISTS maintenance_windows (
    id             INT(11) NOT NULL AUTO_INCREMENT,
    scope          ENUM('firewall','site','customer') NOT NULL,
    scope_id       INT(11) NOT NULL COMMENT 'firewall_id, site_id or customer_id per scope',
    starts_at      DATETIME NOT NULL,
    ends_at        DATETIME NOT NULL,
    reason         VARCHAR(255) NULL,
    status         ENUM('scheduled','active','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    suppress_alerts TINYINT(1) NOT NULL DEFAULT 1
                   COMMENT 'Data collection and health display continue regardless',
    created_by_user_id INT(11) NULL,
    created_by_username VARCHAR(64) NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_scope (scope, scope_id),
    KEY idx_window (starts_at, ends_at),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (`name`, `value`) VALUES
    ('alert_notify_repeat_minutes', '60'),
    ('alert_notify_max_repeats',    '4'),
    ('alert_auto_resolve_enabled',  '1'),
    ('alert_cpu_threshold',         '90'),
    ('alert_memory_threshold',      '90'),
    ('alert_disk_threshold',        '90'),
    ('alert_sustained_minutes',     '15'),
    ('alert_agent_min_version',     '1.5.0');
