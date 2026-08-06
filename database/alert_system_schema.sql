-- Alert System Database Schema for OpnMgr
-- Version: 1.0
-- Date: 2025-10-04
--
-- STATUS: historical, for installations predating the alert system.
-- Fresh installs do NOT need this -- database/schema.sql already contains
-- alert_settings and alert_history. Safe to re-run (all CREATEs are
-- IF NOT EXISTS and all INSERTs are ON DUPLICATE KEY UPDATE).
--
-- NOTE: the alert_recipients table below is obsolete. It is referenced by no
-- code in the application and does not exist in the reference installation;
-- recipient configuration now lives in alert_notifications. It is retained
-- only so this file still applies cleanly to an old database.

-- Table: alert_settings
-- Stores global configuration for email and Pushover
CREATE TABLE IF NOT EXISTS alert_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_name VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_setting_name (setting_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: alert_recipients
-- Stores users/recipients who should receive alerts at different levels
CREATE TABLE IF NOT EXISTS alert_recipients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    pushover_user_key VARCHAR(50),
    alert_level ENUM('info', 'warning', 'critical') NOT NULL,
    notification_method ENUM('email', 'pushover', 'both') NOT NULL DEFAULT 'email',
    enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_alert_level (alert_level),
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: alert_history
-- Logs all alerts sent for tracking and auditing
CREATE TABLE IF NOT EXISTS alert_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alert_level ENUM('info', 'warning', 'critical') NOT NULL,
    alert_type VARCHAR(100) NOT NULL COMMENT 'Type: firewall_offline, backup_failed, agent_timeout, cert_expiring, config_changed',
    firewall_id INT,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    recipients_count INT DEFAULT 0,
    notification_method ENUM('email', 'pushover', 'both') NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('sent', 'failed', 'partial') DEFAULT 'sent',
    error_message TEXT,
    INDEX idx_alert_level (alert_level),
    INDEX idx_alert_type (alert_type),
    INDEX idx_firewall_id (firewall_id),
    INDEX idx_sent_at (sent_at),
    FOREIGN KEY (firewall_id) REFERENCES firewalls(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default alert settings
INSERT INTO alert_settings (setting_name, setting_value) VALUES
    ('email_enabled', 'false'),
    ('email_from_address', ''),
    ('email_from_name', 'OpnMgr Alert System'),
    ('pushover_enabled', 'false'),
    ('pushover_api_token', ''),
    ('alerts_info_enabled', 'true'),
    ('alerts_warning_enabled', 'true'),
    ('alerts_critical_enabled', 'true')
ON DUPLICATE KEY UPDATE setting_name = setting_name;

-- Sample alert recipient (disabled by default)
INSERT INTO alert_recipients (name, email, alert_level, notification_method, enabled) VALUES
    ('Administrator', 'admin@example.com', 'critical', 'email', 0)
ON DUPLICATE KEY UPDATE name = name;
