-- ---------------------------------------------------------------------------
-- 0027_customer_reports
--
-- Customer reports: generation jobs, immutable generated reports, schedules,
-- recipients, deliveries, revocable share links and cached AI summaries.
--
-- Everything ships DISABLED: report_schedules_enabled, report_delivery_enabled,
-- report_share_enabled and report_ai_enabled are all seeded 0, and every
-- schedule row defaults to enabled = 0. An administrator must turn each on.
--
-- Idempotent: safe to re-run.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS report_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    report_type ENUM('firewall','site','customer','fleet') NOT NULL,
    scope_id INT NULL COMMENT 'firewall_id, site_id or customer_id per type; NULL for fleet',
    customer_id INT NULL COMMENT 'Owning customer, denormalised for scoping; NULL for fleet',
    cadence ENUM('weekly','monthly') NOT NULL,
    run_day TINYINT NOT NULL DEFAULT 1 COMMENT 'weekly: 1=Mon..7=Sun; monthly: 1..28',
    run_hour TINYINT NOT NULL DEFAULT 6 COMMENT 'Local hour in the scope timezone',
    sections TEXT NULL COMMENT 'JSON list of section keys',
    include_ai TINYINT(1) NOT NULL DEFAULT 0,
    deliver TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Email recipients when ready (also needs the global switch)',
    enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Off until an administrator enables it',
    next_run_at TIMESTAMP NULL DEFAULT NULL,
    last_run_at TIMESTAMP NULL DEFAULT NULL,
    created_by VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_due (enabled, next_run_at),
    KEY idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS report_jobs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    dedupe_key CHAR(64) NULL COMMENT 'sha256 of type/scope/period/sections/options; NULL once failed so a retry can be requested',
    dedupe_source CHAR(64) NOT NULL,
    report_type ENUM('firewall','site','customer','fleet') NOT NULL,
    scope_id INT NULL,
    customer_id INT NULL COMMENT 'Owning customer; NULL for fleet reports',
    params TEXT NOT NULL COMMENT 'JSON: period, timezone, sections, options',
    status ENUM('queued','running','succeeded','failed','cancelled') NOT NULL DEFAULT 'queued',
    attempts TINYINT NOT NULL DEFAULT 0,
    max_attempts TINYINT NOT NULL DEFAULT 3,
    next_attempt_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_error VARCHAR(512) NULL,
    report_id BIGINT NULL,
    schedule_id INT NULL,
    requested_by VARCHAR(64) NULL,
    requested_by_user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL DEFAULT NULL,
    finished_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uniq_dedupe (dedupe_key),
    KEY idx_status (status, next_attempt_at),
    KEY idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS reports (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    job_id BIGINT NULL,
    schedule_id INT NULL,
    report_type ENUM('firewall','site','customer','fleet') NOT NULL,
    scope_id INT NULL,
    customer_id INT NULL COMMENT 'Owning customer; NULL means fleet-wide (unrestricted staff only)',
    title VARCHAR(255) NOT NULL,
    timezone VARCHAR(64) NOT NULL,
    period_start INT UNSIGNED NOT NULL COMMENT 'Unix seconds, inclusive',
    period_end INT UNSIGNED NOT NULL COMMENT 'Unix seconds, exclusive',
    sections TEXT NOT NULL,
    generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    generated_by VARCHAR(64) NULL,
    html_path VARCHAR(512) NOT NULL,
    html_sha256 CHAR(64) NOT NULL,
    csv_path VARCHAR(512) NULL,
    csv_sha256 CHAR(64) NULL,
    pdf_path VARCHAR(512) NULL,
    pdf_sha256 CHAR(64) NULL,
    pdf_status ENUM('ok','unavailable','failed','not_requested') NOT NULL DEFAULT 'not_requested',
    pdf_error VARCHAR(255) NULL,
    snapshot_json LONGTEXT NOT NULL COMMENT 'Metric snapshot and provenance used to build the report',
    snapshot_sha256 CHAR(64) NOT NULL,
    ai_status ENUM('none','ok','failed','discarded') NOT NULL DEFAULT 'none',
    KEY idx_customer (customer_id, generated_at),
    KEY idx_generated (generated_at),
    KEY idx_job (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS report_recipients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL DEFAULT 0 COMMENT '0 = internal recipients for fleet reports',
    email VARCHAR(255) NOT NULL,
    name VARCHAR(128) NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_by VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_recipient (customer_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS report_shares (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT NOT NULL,
    token_hash CHAR(64) NOT NULL COMMENT 'sha256 of the token; the token itself is never stored',
    expires_at TIMESTAMP NOT NULL,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    revoked_by VARCHAR(64) NULL,
    created_by VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_accessed_at TIMESTAMP NULL DEFAULT NULL,
    access_count INT NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_token (token_hash),
    KEY idx_report (report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS report_deliveries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
    attempts TINYINT NOT NULL DEFAULT 0,
    next_attempt_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_error VARCHAR(255) NULL,
    share_id BIGINT NULL,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_delivery (report_id, recipient_email),
    KEY idx_status (status, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS report_ai_summaries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    input_hash CHAR(64) NOT NULL COMMENT 'sha256 of provider, model, prompt version and the exact facts sent',
    provider VARCHAR(50) NOT NULL,
    model VARCHAR(100) NULL,
    status ENUM('ok','discarded','failed') NOT NULL,
    narrative TEXT NULL,
    detail VARCHAR(255) NULL,
    prompt_tokens INT NULL,
    completion_tokens INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_hash (input_hash, status),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Global switches and limits. All off / conservative until an administrator acts.
INSERT IGNORE INTO settings (`name`,`value`) VALUES
    ('report_schedules_enabled', '0'),
    ('report_delivery_enabled',  '0'),
    ('report_share_enabled',     '0'),
    ('report_ai_enabled',        '0'),
    ('report_share_max_hours',   '168'),
    ('report_retention_days',    '400'),
    ('report_ai_monthly_token_budget', '200000');

INSERT INTO scheduled_tasks (task_name, description, schedule, script_path, expected_interval_minutes, enabled)
VALUES
  ('report_run',   'Queues due report schedules, builds queued reports, sends deliveries. Does nothing for schedules/delivery until enabled.', 'Every 10 minutes', 'cron/report_run.php',   10,   1),
  ('report_prune', 'Deletes generated reports past the retention setting.',                                                                   'Daily at 03:30',   'cron/report_prune.php', 1440, 1)
ON DUPLICATE KEY UPDATE
  description = VALUES(description), schedule = VALUES(schedule),
  script_path = VALUES(script_path), expected_interval_minutes = VALUES(expected_interval_minutes);
