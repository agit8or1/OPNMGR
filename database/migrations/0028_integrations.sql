-- ---------------------------------------------------------------------------
-- 0028_integrations
--
-- Ticketing and notification integrations: destinations, routing rules, a
-- transactional outbox, per-destination deliveries with attempts, ticket
-- mappings, and the stores that make inbound webhooks safe (nonces, log).
--
-- Nothing here sends anything by itself. With no destination and no route
-- configured the pipeline is inert, so installing this changes no behaviour.
--
-- Idempotent: safe to re-run.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS integration_destinations (
    id            INT(11) NOT NULL AUTO_INCREMENT,
    name          VARCHAR(100) NOT NULL,
    type          ENUM('email','webhook','slack','teams','ticket_webhook') NOT NULL,
    enabled       TINYINT(1) NOT NULL DEFAULT 1,
    customer_id   INT(11) NULL COMMENT 'NULL = global; scoped staff see only their customers',
    config_json   TEXT NULL COMMENT 'Non-secret settings (JSON)',
    secrets_enc   TEXT NULL COMMENT 'Secrets (JSON) encrypted with opnmgr_encrypt: url, signing/auth/inbound secrets',
    allow_internal TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Permit private/loopback targets (internal helpdesks)',
    ip_change_policy ENUM('warn','block') NOT NULL DEFAULT 'warn',
    concurrency_limit INT(11) NOT NULL DEFAULT 2,
    max_attempts  INT(11) NOT NULL DEFAULT 6,
    timeout_seconds INT(11) NOT NULL DEFAULT 10,
    on_resolve    ENUM('resolve','comment','none') NOT NULL DEFAULT 'resolve',
    on_recurrence ENUM('reopen','new_ticket') NOT NULL DEFAULT 'new_ticket',
    reopen_window_minutes INT(11) NOT NULL DEFAULT 1440,
    on_external_close ENUM('note','reopen_ticket') NOT NULL DEFAULT 'note',
    inbound_ack_enabled TINYINT(1) NOT NULL DEFAULT 0,
    inbound_comment_enabled TINYINT(1) NOT NULL DEFAULT 0,
    inbound_assign_enabled TINYINT(1) NOT NULL DEFAULT 0,
    last_ip       VARCHAR(45) NULL,
    last_ip_changed_at DATETIME NULL,
    ip_change_pending TINYINT(1) NOT NULL DEFAULT 0,
    paused_until  DATETIME NULL COMMENT 'Set from Retry-After on a 429',
    created_by    VARCHAR(64) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_name (name),
    KEY idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS integration_routes (
    id            INT(11) NOT NULL AUTO_INCREMENT,
    name          VARCHAR(100) NOT NULL,
    enabled       TINYINT(1) NOT NULL DEFAULT 1,
    destination_id INT(11) NOT NULL,
    event_types   VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'csv; empty = all',
    customer_id   INT(11) NULL,
    site_id       INT(11) NULL,
    severities    VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'csv; empty = all',
    incident_types VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'csv of alert types; empty = all',
    tag_ids       VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'csv; any-of; empty = all',
    schedule_json TEXT NULL COMMENT '{"timezone","days":[1-7],"start":"HH:MM","end":"HH:MM"}',
    schedule_outside ENUM('skip','defer') NOT NULL DEFAULT 'skip',
    escalate_after_minutes INT(11) NULL COMMENT 'Set = escalation route: fires only if still unacknowledged after N minutes',
    created_by    VARCHAR(64) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_destination (destination_id),
    KEY idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Transactional outbox: one row per emitted event, written in the caller's
-- transaction. dedupe_key makes emission idempotent.
CREATE TABLE IF NOT EXISTS integration_events (
    id            BIGINT(20) NOT NULL AUTO_INCREMENT,
    event_id      CHAR(36) NOT NULL,
    event_type    VARCHAR(64) NOT NULL,
    event_version INT(11) NOT NULL DEFAULT 1,
    dedupe_key    VARCHAR(191) NULL,
    occurred_at   DATETIME NOT NULL,
    customer_id   INT(11) NULL,
    site_id       INT(11) NULL,
    firewall_id   INT(11) NULL,
    incident_id   BIGINT(20) NULL,
    severity      VARCHAR(16) NULL,
    origin_kind   VARCHAR(16) NOT NULL DEFAULT 'local',
    origin_destination_id INT(11) NULL COMMENT 'Destination that caused this event (inbound sync); never delivered back to it',
    forced_route_id INT(11) NULL COMMENT 'Escalation events go only to their route',
    correlation_id VARCHAR(64) NULL,
    envelope_json MEDIUMTEXT NOT NULL,
    status        ENUM('pending','routed','failed') NOT NULL DEFAULT 'pending',
    routed_at     DATETIME NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_event_id (event_id),
    UNIQUE KEY uniq_dedupe (dedupe_key),
    KEY idx_status (status, id),
    KEY idx_incident (incident_id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS integration_deliveries (
    id            BIGINT(20) NOT NULL AUTO_INCREMENT,
    event_row_id  BIGINT(20) NOT NULL,
    event_id      CHAR(36) NOT NULL,
    event_type    VARCHAR(64) NOT NULL,
    destination_id INT(11) NOT NULL,
    route_id      INT(11) NULL,
    idempotency_key CHAR(64) NOT NULL,
    action        VARCHAR(24) NOT NULL DEFAULT 'notify',
    status        ENUM('pending','leased','sending','retry','succeeded','dead',
                       'needs_reconciliation','suppressed','cancelled') NOT NULL DEFAULT 'pending',
    suppress_reason VARCHAR(128) NULL,
    attempts      INT(11) NOT NULL DEFAULT 0,
    rate_limit_hits INT(11) NOT NULL DEFAULT 0,
    max_attempts  INT(11) NOT NULL DEFAULT 6,
    next_attempt_at DATETIME NOT NULL,
    lease_until   DATETIME NULL,
    lease_token   CHAR(32) NULL,
    last_http_status INT(11) NULL,
    last_error    VARCHAR(500) NULL COMMENT 'Redacted',
    resolved_ip   VARCHAR(45) NULL,
    customer_id   INT(11) NULL,
    site_id       INT(11) NULL,
    firewall_id   INT(11) NULL,
    incident_id   BIGINT(20) NULL,
    severity      VARCHAR(16) NULL,
    is_test       TINYINT(1) NOT NULL DEFAULT 0,
    replay_count  INT(11) NOT NULL DEFAULT 0,
    replay_of     BIGINT(20) NULL,
    delivered_at  DATETIME NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_idem (destination_id, idempotency_key),
    KEY idx_due (status, next_attempt_at),
    KEY idx_dest_status (destination_id, status),
    KEY idx_incident_dest (incident_id, destination_id),
    KEY idx_customer (customer_id),
    KEY idx_event (event_row_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS integration_delivery_attempts (
    id            BIGINT(20) NOT NULL AUTO_INCREMENT,
    delivery_id   BIGINT(20) NOT NULL,
    attempt_no    INT(11) NOT NULL,
    started_at    DATETIME NOT NULL,
    duration_ms   INT(11) NULL,
    outcome       VARCHAR(24) NOT NULL,
    http_status   INT(11) NULL,
    resolved_ip   VARCHAR(45) NULL,
    error         VARCHAR(500) NULL COMMENT 'Redacted',
    PRIMARY KEY (id),
    KEY idx_delivery (delivery_id, attempt_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One ongoing incident = one external ticket per destination. workflow state
-- of the ticket (ticket_state) is stored apart from the observed state of the
-- incident (incident_state): closing a ticket never changes the incident.
CREATE TABLE IF NOT EXISTS integration_ticket_links (
    id            BIGINT(20) NOT NULL AUTO_INCREMENT,
    destination_id INT(11) NOT NULL,
    incident_id   BIGINT(20) NOT NULL,
    active_key    VARCHAR(40) NULL COMMENT 'dest:incident while the link is live; NULL once closed (UNIQUE)',
    link_state    ENUM('creating','active','needs_reconciliation','closed','abandoned') NOT NULL DEFAULT 'creating',
    outcome_unknown TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'A create was attempted and its result is unknown',
    create_idempotency_key CHAR(64) NULL,
    create_attempted_at DATETIME NULL,
    external_id   VARCHAR(191) NULL,
    external_url  VARCHAR(500) NULL,
    ticket_state  ENUM('unknown','open','acknowledged','closed') NOT NULL DEFAULT 'unknown',
    incident_state VARCHAR(16) NULL COMMENT 'Observed local incident state at last sync',
    closed_while_incident_active TINYINT(1) NOT NULL DEFAULT 0,
    external_assignee VARCHAR(128) NULL,
    reopened_from_link_id BIGINT(20) NULL,
    last_auto_reopen_at DATETIME NULL,
    last_synced_at DATETIME NULL,
    last_inbound_at DATETIME NULL,
    customer_id   INT(11) NULL,
    site_id       INT(11) NULL,
    firewall_id   INT(11) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_active (active_key),
    KEY idx_incident (incident_id),
    KEY idx_external (destination_id, external_id),
    KEY idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS integration_escalations (
    route_id      INT(11) NOT NULL,
    incident_id   BIGINT(20) NOT NULL,
    fired_at      DATETIME NOT NULL,
    PRIMARY KEY (route_id, incident_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Replay protection for inbound webhooks. Only a hash is kept.
CREATE TABLE IF NOT EXISTS integration_inbound_nonces (
    destination_id INT(11) NOT NULL,
    nonce_hash    CHAR(64) NOT NULL,
    seen_at       DATETIME NOT NULL,
    PRIMARY KEY (destination_id, nonce_hash),
    KEY idx_seen (seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS integration_inbound_log (
    id            BIGINT(20) NOT NULL AUTO_INCREMENT,
    destination_id INT(11) NULL,
    received_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    remote_ip     VARCHAR(45) NULL,
    result        VARCHAR(32) NOT NULL,
    action        VARCHAR(32) NULL,
    external_id   VARCHAR(191) NULL,
    incident_id   BIGINT(20) NULL,
    customer_id   INT(11) NULL,
    detail        VARCHAR(300) NULL,
    PRIMARY KEY (id),
    KEY idx_dest (destination_id, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Local ownership, set only when a destination has inbound assignment enabled.
ALTER TABLE alert_incidents ADD COLUMN IF NOT EXISTS owner VARCHAR(128) NULL;

INSERT IGNORE INTO settings (`name`, `value`) VALUES
    ('integrations_legacy_email_owner', 'legacy'),
    ('integrations_reopen_window_minutes', '1440'),
    ('integrations_inbound_window_seconds', '300');

INSERT INTO scheduled_tasks (task_name, description, schedule, script_path, expected_interval_minutes, enabled)
VALUES
 ('integration_dispatch', 'Routes outbox events, fires escalations and delivers to integration destinations.',
  'Every minute', 'cron/integration_dispatch.php', 1, 1),
 ('integration_poll', 'Polls ticketing destinations for status changes and prunes integration history.',
  'Every 5 minutes', 'cron/integration_poll.php', 5, 1)
ON DUPLICATE KEY UPDATE
    description = VALUES(description), schedule = VALUES(schedule),
    script_path = VALUES(script_path), expected_interval_minutes = VALUES(expected_interval_minutes);
