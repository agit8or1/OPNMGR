-- ---------------------------------------------------------------------------
-- 0001_agent_auth_and_audit
--
-- P0 security work:
--   * per-firewall API key + HMAC signing secret with a backward-compatible
--     provisioning path (existing agents keep checking in while they upgrade)
--   * nonce store for signed-request replay protection
--   * agent authentication failure tracking
--   * application-wide audit log
--
-- Idempotent: every statement uses IF NOT EXISTS / INSERT IGNORE so the file
-- can be re-run safely (MariaDB 10.2+ / MySQL 8 with equivalent DDL).
-- ---------------------------------------------------------------------------

-- --- firewalls: agent credential columns ----------------------------------
-- api_key / api_secret already exist but are VARCHAR(128), too small to hold
-- an XChaCha20-Poly1305 envelope. Widen to TEXT before anything encrypts them.
ALTER TABLE firewalls MODIFY COLUMN api_key TEXT NULL;
ALTER TABLE firewalls MODIFY COLUMN api_secret TEXT NULL;

ALTER TABLE firewalls
    ADD COLUMN IF NOT EXISTS api_key_issued_at TIMESTAMP NULL DEFAULT NULL
        COMMENT 'When the server last provisioned an API key to this agent',
    ADD COLUMN IF NOT EXISTS api_key_confirmed TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 once the agent has successfully presented its API key; auth then fails closed',
    ADD COLUMN IF NOT EXISTS agent_signing_supported TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 once the agent has sent a valid HMAC-signed request',
    ADD COLUMN IF NOT EXISTS agent_last_signed_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS agent_auth_failures INT(11) NOT NULL DEFAULT 0
        COMMENT 'Consecutive failed agent authentication attempts; reset on success',
    ADD COLUMN IF NOT EXISTS agent_last_auth_failure_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS agent_clock_skew_seconds INT(11) NULL DEFAULT NULL
        COMMENT 'Last observed difference between agent and server clocks';

-- --- replay protection for signed agent requests ---------------------------
CREATE TABLE IF NOT EXISTS agent_request_nonces (
    id           BIGINT(20) NOT NULL AUTO_INCREMENT,
    firewall_id  INT(11) NOT NULL,
    nonce        VARCHAR(128) NOT NULL,
    seen_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_firewall_nonce (firewall_id, nonce),
    KEY idx_seen_at (seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Short-lived nonce store; rows older than the signature window are pruned';

-- --- audit log -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id           BIGINT(20) NOT NULL AUTO_INCREMENT,
    occurred_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actor_type   ENUM('user','agent','system','anonymous') NOT NULL DEFAULT 'system',
    user_id      INT(11) NULL,
    username     VARCHAR(64) NULL COMMENT 'Denormalised so history survives user deletion',
    source_ip    VARCHAR(45) NULL,
    action       VARCHAR(64) NOT NULL COMMENT 'Stable machine-readable action key, e.g. command.raw',
    object_type  VARCHAR(32) NULL COMMENT 'firewall, customer, site, user, setting, ...',
    object_id    VARCHAR(64) NULL,
    firewall_id  INT(11) NULL,
    customer_id  INT(11) NULL,
    site_id      INT(11) NULL,
    success      TINYINT(1) NOT NULL DEFAULT 1,
    message      VARCHAR(512) NULL,
    metadata     TEXT NULL COMMENT 'JSON. Never contains passwords, keys or MFA secrets',
    PRIMARY KEY (id),
    KEY idx_occurred_at (occurred_at),
    KEY idx_action (action),
    KEY idx_user (user_id),
    KEY idx_firewall (firewall_id),
    KEY idx_customer (customer_id),
    KEY idx_success (success)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- settings --------------------------------------------------------------
-- Existing installations default to 'compatibility' so that already-deployed
-- agents keep checking in. Clean installs are switched to 'prefer_signed' by
-- the installer, not here.
INSERT IGNORE INTO settings (`name`, `value`) VALUES
    ('agent_auth_mode',            'compatibility'),
    ('agent_signature_window',     '300'),
    ('session_idle_timeout',       '3600'),
    ('session_absolute_timeout',   '43200'),
    ('require_mfa_for_admins',     '0'),
    ('raw_command_enabled',        '1');
