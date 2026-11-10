-- ---------------------------------------------------------------------------
-- 0035_customer_path_checks
--
-- Optional customer-side checks for update rings (3.81.0).
--
-- A firewall can check in while a workstation behind it cannot reach a
-- service: its own check-in proves the WAN works, not that the customer's
-- internal DNS, WireGuard/IPsec path or internet egress does. A probe sits on
-- a customer network (the standalone tools/opnmgr-probe, or Rem0te running the
-- same checks), runs only the checks configured here, and reports results.
--
-- Campaigns opt in (update_campaigns.customer_checks, default 0). When on, a
-- required check that fails, or whose probe stops reporting, holds promotion
-- to the next ring and holds the second member of a CARP pair.
--
-- Nothing here changes an existing campaign or rollout default.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS customer_probes (
    id INT NOT NULL AUTO_INCREMENT,
    customer_id INT NOT NULL COMMENT 'Tenant. The probe and every firewall it covers belong to it',
    firewall_id INT NOT NULL COMMENT 'The firewall (or one CARP member) the probe sits behind',
    name VARCHAR(100) NOT NULL,
    kind ENUM('standalone','rem0te') NOT NULL DEFAULT 'standalone',
    token_hash CHAR(64) NOT NULL COMMENT 'sha256 of the probe token; the token itself is shown once',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    interval_seconds INT NOT NULL DEFAULT 60,
    last_seen_at TIMESTAMP NULL DEFAULT NULL,
    last_seen_ip VARCHAR(45) NULL,
    probe_version VARCHAR(32) NULL,
    created_by VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_token (token_hash),
    KEY idx_customer (customer_id),
    KEY idx_firewall (firewall_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS customer_probe_checks (
    id INT NOT NULL AUTO_INCREMENT,
    probe_id INT NOT NULL,
    check_type ENUM('dns','vpn_service','internet_service') NOT NULL
        COMMENT 'dns: resolve a name; vpn_service: TCP to a private address across the VPN; internet_service: HTTPS to a public host',
    label VARCHAR(100) NOT NULL,
    target VARCHAR(255) NOT NULL COMMENT 'dns: hostname; vpn_service: host:port; internet_service: https URL',
    resolver VARCHAR(45) NULL COMMENT 'dns only: ask this internal resolver instead of the system one',
    expect VARCHAR(64) NULL COMMENT 'dns: address or CIDR the answer must be in; internet_service: HTTP status',
    required TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Gates ring promotion when the campaign uses customer checks',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_probe (probe_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS customer_probe_results (
    id BIGINT NOT NULL AUTO_INCREMENT,
    probe_id INT NOT NULL,
    check_id INT NOT NULL,
    ok TINYINT(1) NOT NULL,
    latency_ms INT NULL,
    detail VARCHAR(255) NULL,
    observed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the manager received it; the probe clock is not trusted',
    PRIMARY KEY (id),
    KEY idx_check_time (check_id, observed_at),
    KEY idx_probe_time (probe_id, observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE update_campaigns
    ADD COLUMN IF NOT EXISTS customer_checks TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Gate promotion and CARP sequencing on customer-side probe checks';

ALTER TABLE update_campaign_targets
    ADD COLUMN IF NOT EXISTS customer_baseline TEXT NULL
        COMMENT 'JSON: each customer check result just before dispatch';

INSERT IGNORE INTO settings (`name`, `value`) VALUES
    ('customer_check_stale_minutes', '10'),
    ('customer_check_settle_minutes', '3'),
    ('customer_check_result_retention_days', '14');
