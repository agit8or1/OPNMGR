-- ---------------------------------------------------------------------------
-- 0010_firewall_health
--
-- OPNsense-specific health telemetry (P1 #13): gateways, VPN tunnels, CARP/HA,
-- services and certificates.
--
-- Each "current state" table holds one row per object per firewall and is
-- replaced on each report. The paired *_events tables keep transition history,
-- which is what makes "this gateway has flapped six times today" answerable.
--
-- Certificates: metadata only. Private key material is never sent by the agent
-- and there is deliberately no column that could hold it.
-- ---------------------------------------------------------------------------

-- --- gateways ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS firewall_gateways (
    id            INT(11) NOT NULL AUTO_INCREMENT,
    firewall_id   INT(11) NOT NULL,
    name          VARCHAR(64) NOT NULL,
    interface     VARCHAR(64) NULL,
    address       VARCHAR(45) NULL,
    monitor       VARCHAR(45) NULL,
    status        VARCHAR(32) NULL COMMENT 'none/online, down, loss, delay, force_down',
    latency_ms    DECIMAL(8,2) NULL,
    stddev_ms     DECIMAL(8,2) NULL,
    loss_percent  DECIMAL(5,2) NULL,
    is_default    TINYINT(1) NOT NULL DEFAULT 0,
    gateway_group VARCHAR(64) NULL,
    priority      INT(11) NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_fw_gateway (firewall_id, name),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS firewall_gateway_events (
    id           BIGINT(20) NOT NULL AUTO_INCREMENT,
    firewall_id  INT(11) NOT NULL,
    gateway_name VARCHAR(64) NOT NULL,
    from_status  VARCHAR(32) NULL,
    to_status    VARCHAR(32) NULL,
    latency_ms   DECIMAL(8,2) NULL,
    loss_percent DECIMAL(5,2) NULL,
    occurred_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fw_time (firewall_id, occurred_at),
    KEY idx_gateway (firewall_id, gateway_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- VPN ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS firewall_vpn_tunnels (
    id               INT(11) NOT NULL AUTO_INCREMENT,
    firewall_id      INT(11) NOT NULL,
    vpn_type         ENUM('wireguard','openvpn','ipsec') NOT NULL,
    name             VARCHAR(128) NOT NULL,
    peer             VARCHAR(255) NULL COMMENT 'Peer name or public key fingerprint, never a private key',
    endpoint         VARCHAR(255) NULL,
    status           VARCHAR(32) NULL COMMENT 'up, down, connecting, disabled',
    enabled          TINYINT(1) NOT NULL DEFAULT 1,
    latest_handshake TIMESTAMP NULL DEFAULT NULL COMMENT 'WireGuard',
    connected_since  TIMESTAMP NULL DEFAULT NULL,
    rx_bytes         BIGINT(20) NULL,
    tx_bytes         BIGINT(20) NULL,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_fw_vpn (firewall_id, vpn_type, name),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS firewall_vpn_events (
    id          BIGINT(20) NOT NULL AUTO_INCREMENT,
    firewall_id INT(11) NOT NULL,
    vpn_type    VARCHAR(16) NOT NULL,
    name        VARCHAR(128) NOT NULL,
    from_status VARCHAR(32) NULL,
    to_status   VARCHAR(32) NULL,
    occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fw_time (firewall_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- CARP / HA ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS firewall_carp (
    id             INT(11) NOT NULL AUTO_INCREMENT,
    firewall_id    INT(11) NOT NULL,
    vhid           VARCHAR(16) NOT NULL,
    interface      VARCHAR(64) NULL,
    address        VARCHAR(45) NULL,
    state          VARCHAR(16) NULL COMMENT 'MASTER, BACKUP, INIT',
    advskew        INT(11) NULL,
    advbase        INT(11) NULL,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_fw_vhid (firewall_id, vhid),
    KEY idx_state (state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE firewalls
    ADD COLUMN IF NOT EXISTS carp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS carp_state VARCHAR(16) NULL COMMENT 'Overall MASTER/BACKUP for the node',
    ADD COLUMN IF NOT EXISTS carp_peer_host VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS carp_sync_status VARCHAR(32) NULL,
    ADD COLUMN IF NOT EXISTS ha_peer_firewall_id INT(11) NULL
        COMMENT 'Resolved HA partner, used to avoid updating both members at once';

ALTER TABLE firewalls
    ADD INDEX IF NOT EXISTS idx_carp_state (carp_state),
    ADD INDEX IF NOT EXISTS idx_ha_peer (ha_peer_firewall_id);

-- --- services ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS firewall_services (
    id          INT(11) NOT NULL AUTO_INCREMENT,
    firewall_id INT(11) NOT NULL,
    name        VARCHAR(64) NOT NULL,
    description VARCHAR(128) NULL,
    running     TINYINT(1) NOT NULL DEFAULT 0,
    enabled     TINYINT(1) NOT NULL DEFAULT 0,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_fw_service (firewall_id, name),
    KEY idx_running (running)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Only services the agent reported as present on that firewall';

-- --- certificates ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS firewall_certificates (
    id             INT(11) NOT NULL AUTO_INCREMENT,
    firewall_id    INT(11) NOT NULL,
    refid          VARCHAR(64) NOT NULL COMMENT 'OPNsense certificate reference id',
    name           VARCHAR(255) NULL,
    issuer         VARCHAR(255) NULL,
    subject        VARCHAR(255) NULL,
    cert_type      VARCHAR(32) NULL COMMENT 'server, user, ca',
    not_before     DATETIME NULL,
    not_after      DATETIME NULL,
    days_remaining INT(11) NULL,
    in_use         VARCHAR(255) NULL COMMENT 'Where the certificate is referenced',
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_fw_cert (firewall_id, refid),
    KEY idx_expiry (not_after),
    KEY idx_days (days_remaining)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Certificate METADATA only. No private key material is ever stored here.';

-- --- settings ----------------------------------------------------------------------
INSERT IGNORE INTO settings (`name`, `value`) VALUES
    ('cert_warn_days_critical', '7'),
    ('cert_warn_days_high',     '14'),
    ('cert_warn_days_medium',   '30'),
    ('health_gateway_loss_warn', '5'),
    ('health_gateway_latency_warn', '150');
