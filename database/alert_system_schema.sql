-- Alert System Database Schema for OpnMgr
-- Version: 1.0
-- Date: 2025-10-04
--
-- STATUS: historical, for installations predating the alert system.
-- Fresh installs do NOT need this -- database/schema.sql already contains
-- alert_settings and alert_history. Safe to re-run.
--
-- !! THIS FILE DROPS A TABLE. Back up your database before running it. !!
--
-- This file previously created an alert_recipients table. That table was
-- obsolete -- referenced by no code and absent from the reference installation
-- -- and recipient configuration now lives in alert_notifications. As well as
-- no longer creating it, this migration now DROPS it, discarding any rows it
-- still holds. See the cleanup section at the end of this file.

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

-- -----------------------------------------------------------------------------
-- Cleanup: remove the obsolete alert_recipients table
-- -----------------------------------------------------------------------------
-- DESTRUCTIVE. alert_recipients was superseded by alert_notifications and is
-- read by no code in the application. Dropping it discards any rows it still
-- holds -- if you configured recipients there before upgrading, export them
-- first:
--
--     SELECT * FROM alert_recipients;
--
-- and recreate them under Alerts > Notifications in the web UI.
--
-- No-op on databases that never had the table.
DROP TABLE IF EXISTS alert_recipients;
