-- ---------------------------------------------------------------------------
-- 0025_policy_templates
--
-- Shared policies and configuration templates (3.79).
--
-- A template is a named, versioned set of typed components (aliases, filter
-- rules, DNS/NTP servers, management access, remote syslog). Revisions move
-- draft -> published -> retired; a published revision is immutable and its
-- content_hash is checked whenever it is used.
--
-- Assignments attach a published revision at global / customer / site /
-- firewall scope. Exceptions waive one managed object on one firewall, with a
-- reason, an owner and an optional expiry. Deployments carry per-firewall plans
-- whose approval binds the target, revision, rendered plan hash and the base
-- configuration fingerprint.
--
-- Application to firewalls stays behind the platform capability `policy.apply`
-- (untested everywhere; lab firewalls only). Nothing here enables it.
--
-- Idempotent: safe to re-run.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS policy_templates (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(128) NOT NULL,
    description     TEXT NULL,
    is_retired      TINYINT(1) NOT NULL DEFAULT 0,
    created_by      VARCHAR(64) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS policy_template_revisions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    template_id     INT NOT NULL,
    revision        INT NOT NULL,
    status          ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
    content         MEDIUMTEXT NOT NULL COMMENT 'JSON: {variables:[...], components:[...]}',
    content_hash    CHAR(64) NOT NULL COMMENT 'sha256 of canonical content; frozen at publish',
    notes           VARCHAR(255) NULL,
    created_by      VARCHAR(64) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_by    VARCHAR(64) NULL,
    published_at    TIMESTAMP NULL DEFAULT NULL,
    retired_at      TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uniq_template_revision (template_id, revision),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS policy_assignments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    template_id     INT NOT NULL,
    revision_id     INT NOT NULL COMMENT 'Pinned published revision',
    scope_type      ENUM('global','customer','site','firewall') NOT NULL,
    scope_id        INT NULL COMMENT 'NULL for global',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_by      VARCHAR(64) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_scope (scope_type, scope_id),
    KEY idx_template (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS policy_variable_values (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    scope_type      ENUM('global','customer','site','firewall') NOT NULL,
    scope_id        INT NOT NULL DEFAULT 0 COMMENT '0 for global',
    template_id     INT NOT NULL DEFAULT 0 COMMENT '0 = applies to every template',
    name            VARCHAR(64) NOT NULL,
    value           TEXT NOT NULL COMMENT 'JSON-encoded value',
    updated_by      VARCHAR(64) NULL,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_value (scope_type, scope_id, template_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS policy_exceptions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    firewall_id     INT NOT NULL,
    template_id     INT NOT NULL,
    object_key      VARCHAR(64) NOT NULL COMMENT 'Component key, or * for the whole template',
    reason          VARCHAR(512) NOT NULL,
    owner           VARCHAR(128) NOT NULL,
    expires_at      DATETIME NULL,
    created_by      VARCHAR(64) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at      DATETIME NULL,
    revoked_by      VARCHAR(64) NULL,
    KEY idx_fw_tpl (firewall_id, template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS policy_object_mappings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    firewall_id     INT NOT NULL,
    template_id     INT NOT NULL,
    object_key      VARCHAR(64) NOT NULL,
    target_ref      VARCHAR(128) NOT NULL COMMENT 'Existing alias name, rule uuid or pfSense tracker to adopt',
    created_by      VARCHAR(64) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_mapping (firewall_id, template_id, object_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS policy_deployments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    template_id     INT NOT NULL,
    revision_id     INT NOT NULL,
    status          ENUM('planned','approved','running','completed','stopped','cancelled') NOT NULL DEFAULT 'planned',
    notes           VARCHAR(255) NULL,
    created_by      VARCHAR(64) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_by     VARCHAR(64) NULL,
    approved_at     DATETIME NULL,
    started_at      DATETIME NULL,
    finished_at     DATETIME NULL,
    detail          TEXT NULL,
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS policy_deployment_targets (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    deployment_id    INT NOT NULL,
    firewall_id      INT NOT NULL,
    order_index      INT NOT NULL DEFAULT 0 COMMENT 'Ring order, then hostname',
    ring             VARCHAR(16) NULL,
    ha_role          ENUM('standalone','primary','secondary_verify') NOT NULL DEFAULT 'standalone',
    status           ENUM('planned','blocked','stale','approved','pre_backup','applying','verifying',
                          'confirming','succeeded','failed','rolled_back','held','skipped','cancelled')
                     NOT NULL DEFAULT 'planned',
    approvable       TINYINT(1) NOT NULL DEFAULT 0,
    base_backup_id   INT NULL,
    base_fingerprint CHAR(64) NULL COMMENT 'sha256 of the raw base config bytes',
    plan_hash        CHAR(64) NULL,
    artifact_sha256  CHAR(64) NULL COMMENT 'sha256 of the rendered full config; the file itself is re-rendered on fetch, never stored',
    plan_json        MEDIUMTEXT NULL COMMENT 'Sanitized semantic plan (no secrets)',
    change_count     INT NOT NULL DEFAULT 0,
    blockers         TEXT NULL COMMENT 'JSON list of reasons the plan cannot be approved',
    approval_hash    CHAR(64) NULL,
    approved_plan_hash CHAR(64) NULL,
    approved_fingerprint CHAR(64) NULL,
    approved_by      VARCHAR(64) NULL,
    approved_at      DATETIME NULL,
    pre_backup_id    INT NULL,
    verify_backup_id INT NULL,
    apply_command_id INT NULL,
    confirm_command_id INT NULL,
    rollback_command_id INT NULL,
    fetch_token      CHAR(64) NULL,
    token_used_at    DATETIME NULL,
    checkin_before   DATETIME NULL,
    applied_at       DATETIME NULL,
    recovery_deadline DATETIME NULL,
    finished_at      DATETIME NULL,
    detail           TEXT NULL,
    UNIQUE KEY uniq_fetch_token (fetch_token),
    KEY idx_deployment (deployment_id),
    KEY idx_firewall (firewall_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS policy_firewall_locks (
    firewall_id     INT PRIMARY KEY,
    target_id       INT NOT NULL,
    acquired_at     DATETIME NOT NULL,
    expires_at      DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='One in-flight policy deployment per firewall';

CREATE TABLE IF NOT EXISTS policy_firewall_state (
    firewall_id               INT NOT NULL,
    template_id               INT NOT NULL,
    desired_revision_id       INT NULL,
    last_applied_revision_id  INT NULL,
    last_applied_at           DATETIME NULL,
    last_deployment_id        INT NULL,
    compliance                ENUM('compliant','drifted','pending','exception','conflict','invalid','unknown')
                              NOT NULL DEFAULT 'unknown',
    evaluated_backup_id       INT NULL,
    out_of_band_changes       INT NOT NULL DEFAULT 0,
    detail                    TEXT NULL COMMENT 'JSON summary, no secrets',
    last_evaluated_at         DATETIME NULL,
    PRIMARY KEY (firewall_id, template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (`name`, `value`) VALUES
    ('policy_max_backup_age_minutes', '60'),
    ('policy_recovery_window_seconds', '900'),
    ('policy_verify_minutes', '20'),
    ('policy_manager_source_ips', '');

INSERT INTO scheduled_tasks (task_name, description, schedule, script_path, expected_interval_minutes, enabled)
VALUES
  ('policy_reconcile',  'Advances policy deployments: verify read-back, confirm or roll back.', 'Every 2 minutes', 'cron/policy_reconcile.php', 2, 1),
  ('policy_compliance', 'Evaluates template compliance against the newest backups.',          'Hourly',          'cron/policy_compliance.php', 60, 1)
ON DUPLICATE KEY UPDATE
  description               = VALUES(description),
  schedule                  = VALUES(schedule),
  script_path               = VALUES(script_path),
  expected_interval_minutes = VALUES(expected_interval_minutes);
