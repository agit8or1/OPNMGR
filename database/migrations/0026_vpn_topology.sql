-- ---------------------------------------------------------------------------
-- 0026_vpn_topology
--
-- VPN topology and troubleshooting.
--
-- Everything here is derived or operator-supplied; nothing is a source of
-- truth for tunnel state. Tunnel state comes from the latest validated config
-- backup (what is configured) and firewall_vpn_tunnels (what the agent last
-- observed). These tables hold only what neither of those can say:
--
--   vpn_peer_pairings   operator decisions about which two managed endpoints
--                       are the two ends of one tunnel (or are not), and how
--                       an endpoint should be classified. Persisted so a
--                       correction survives a re-parse.
--   vpn_layout_prefs    per-user map layout and filters, kept server-side so
--                       they follow the person rather than the browser.
--   vpn_config_cache    the VPN-relevant slice of each firewall's normalized
--                       configuration, keyed to the backup it came from, so
--                       the topology page does not re-parse every backup on
--                       every view. Secrets never enter the normalized model.
--   vpn_activity        when a tunnel's byte counters last moved. The agent
--                       overwrites rx/tx each check-in, so without this a
--                       quiet remote-access user cannot be told from a dead
--                       one except by handshake age, which is the wrong test.
--   vpn_diagnostic_runs one row per probe leg queued from the guided
--                       workflow, with the source address used and the
--                       outcome. Legs of a coordinated check share run_group.
--
-- Idempotent: safe to re-run.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS vpn_peer_pairings (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    kind         ENUM('pair','unpair','classify') NOT NULL,
    endpoint_a   VARCHAR(191) NOT NULL COMMENT 'Endpoint key; for pair/unpair the lexically smaller of the two',
    endpoint_b   VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'Other endpoint key; empty for classify',
    classification VARCHAR(24) NULL COMMENT 'classify only: site_to_site | remote_access | external | unknown',
    note         VARCHAR(255) NULL,
    created_by   VARCHAR(64) NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_pairing (kind, endpoint_a, endpoint_b),
    KEY idx_a (endpoint_a),
    KEY idx_b (endpoint_b)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vpn_layout_prefs (
    user_id    INT NOT NULL,
    view_key   VARCHAR(32) NOT NULL DEFAULT 'topology',
    prefs      TEXT NOT NULL COMMENT 'JSON: node positions, filters, view mode',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, view_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vpn_config_cache (
    firewall_id  INT NOT NULL PRIMARY KEY,
    backup_id    INT NULL,
    fingerprint  CHAR(64) NULL COMMENT 'sha256 of the config file the slice was built from',
    config_time  DATETIME NULL COMMENT 'When that backup was taken',
    collected_at DATETIME NOT NULL,
    slice        MEDIUMTEXT NOT NULL COMMENT 'JSON: vpn, interfaces, routes, gateways, nat, filter_rules; no secrets',
    KEY idx_backup (backup_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vpn_activity (
    firewall_id    INT NOT NULL,
    vpn_type       VARCHAR(16) NOT NULL,
    name           VARCHAR(128) NOT NULL,
    last_rx        BIGINT NULL,
    last_tx        BIGINT NULL,
    first_seen_at  DATETIME NOT NULL,
    last_change_at DATETIME NULL COMMENT 'Counters last observed to increase; NULL if never seen to move',
    observed_at    DATETIME NOT NULL,
    PRIMARY KEY (firewall_id, vpn_type, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vpn_diagnostic_runs (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    run_group     CHAR(32) NOT NULL COMMENT 'Legs of one coordinated check share this',
    leg           VARCHAR(16) NOT NULL DEFAULT 'source' COMMENT 'source | peer',
    firewall_id   INT NOT NULL,
    tunnel_key    VARCHAR(191) NULL,
    method        VARCHAR(12) NOT NULL,
    target        VARCHAR(64) NOT NULL,
    port          INT NULL,
    source_address VARCHAR(64) NULL COMMENT 'Address the probe was bound to (-S / -s)',
    source_interface VARCHAR(64) NULL,
    source_reason VARCHAR(255) NULL,
    command_id    INT NULL,
    status        VARCHAR(16) NOT NULL DEFAULT 'queued' COMMENT 'queued | completed | failed | refused | expired',
    outcome       VARCHAR(16) NULL COMMENT 'reachable | unreachable | inconclusive',
    summary       VARCHAR(500) NULL,
    result_excerpt TEXT NULL,
    incident_id   BIGINT NULL,
    user_id       INT NULL,
    username      VARCHAR(64) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at  DATETIME NULL,
    KEY idx_group (run_group),
    KEY idx_fw_time (firewall_id, created_at),
    KEY idx_user_time (user_id, created_at),
    KEY idx_command (command_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (`name`, `value`) VALUES
    ('vpn_stale_minutes', '30'),
    ('vpn_probe_rate_user_per_hour', '30'),
    ('vpn_probe_rate_firewall_per_hour', '20'),
    ('vpn_ra_idle_days', '14');

INSERT INTO scheduled_tasks (task_name, description, schedule, script_path, expected_interval_minutes, enabled)
VALUES ('vpn_topology_refresh',
        'Refreshes the VPN configuration cache, records counter movement and prunes old diagnostic runs.',
        'Every 5 minutes', 'cron/vpn_topology_refresh.php', 5, 1)
ON DUPLICATE KEY UPDATE
    description = VALUES(description), schedule = VALUES(schedule),
    script_path = VALUES(script_path), expected_interval_minutes = VALUES(expected_interval_minutes);
