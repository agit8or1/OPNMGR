-- Database migration for Agent v3.4.0
-- Adds support for WAN interface auto-detection and monitoring
--
-- STATUS: upgrade migration, for installations predating v3.4.0.
-- Fresh installs do NOT need this -- database/schema.sql already contains the
-- wan_* columns, the firewall_wan_interfaces table and the
-- v_firewall_wan_status view. Safe to re-run either way (all statements are
-- IF NOT EXISTS / OR REPLACE).

-- Add WAN interface columns to firewalls table
ALTER TABLE firewalls
ADD COLUMN IF NOT EXISTS wan_interfaces VARCHAR(255) DEFAULT NULL COMMENT 'Comma-separated list of WAN interface names',
ADD COLUMN IF NOT EXISTS wan_groups VARCHAR(255) DEFAULT NULL COMMENT 'Comma-separated list of WAN gateway groups',
ADD COLUMN IF NOT EXISTS wan_interface_stats JSON DEFAULT NULL COMMENT 'JSON array of WAN interface statistics';

-- Create index for faster queries
ALTER TABLE firewalls
ADD INDEX IF NOT EXISTS idx_wan_interfaces (wan_interfaces);

-- Optional: Create dedicated table for detailed WAN interface tracking
CREATE TABLE IF NOT EXISTS firewall_wan_interfaces (
    id INT AUTO_INCREMENT PRIMARY KEY,
    firewall_id INT NOT NULL,
    interface_name VARCHAR(50) NOT NULL,
    status VARCHAR(20) DEFAULT NULL COMMENT 'up, down, no_carrier',
    ip_address VARCHAR(50) DEFAULT NULL,
    media VARCHAR(100) DEFAULT NULL COMMENT 'Interface speed/type',
    rx_packets BIGINT DEFAULT 0,
    rx_errors BIGINT DEFAULT 0,
    rx_bytes BIGINT DEFAULT 0,
    tx_packets BIGINT DEFAULT 0,
    tx_errors BIGINT DEFAULT 0,
    tx_bytes BIGINT DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (firewall_id) REFERENCES firewalls(id) ON DELETE CASCADE,
    UNIQUE KEY unique_firewall_interface (firewall_id, interface_name),
    INDEX idx_firewall_id (firewall_id),
    INDEX idx_interface_name (interface_name),
    INDEX idx_status (status),
    INDEX idx_last_updated (last_updated)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks individual WAN interface statistics per firewall';

-- Create view for easy access to current WAN interface status
CREATE OR REPLACE VIEW v_firewall_wan_status AS
SELECT
    f.id AS firewall_id,
    f.hostname,
    f.wan_ip,
    f.status AS firewall_status,
    f.wan_interfaces,
    f.wan_groups,
    w.interface_name,
    w.status AS interface_status,
    w.ip_address AS interface_ip,
    w.media,
    w.rx_bytes,
    w.tx_bytes,
    w.rx_errors,
    w.tx_errors,
    w.last_updated
FROM firewalls f
LEFT JOIN firewall_wan_interfaces w ON f.id = w.firewall_id
WHERE f.status != 'deleted'
ORDER BY f.id, w.interface_name;

-- Insert sample query for monitoring
-- SELECT * FROM v_firewall_wan_status WHERE firewall_id = 21;

-- Migration complete
SELECT 'Migration v3.4.0 completed successfully' AS status;
