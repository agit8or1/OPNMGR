-- =============================================================================
-- OPNManager - Database Schema
-- =============================================================================
-- Generated from the reference installation for OPNManager v3.79.3.
-- Regenerate with: scripts/generate_schema.sh
--
-- This file creates the database, every table, and the static reference data
-- the application needs to start. It contains no user accounts, firewalls,
-- credentials or customer data.
--
-- Import:
--     mysql -u root -p < database/schema.sql
--
-- Then create the application database user (see README.md):
--     CREATE USER 'opnsense_user'@'localhost' IDENTIFIED BY 'your-password';
--     GRANT ALL PRIVILEGES ON opnsense_fw.* TO 'opnsense_user'@'localhost';
--     FLUSH PRIVILEGES;
--
-- Finally create your first admin account:
--     php scripts/create_admin.php
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `opnsense_fw`
    DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `opnsense_fw`;

-- -----------------------------------------------------------------------------
-- Table structure
-- -----------------------------------------------------------------------------
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `agent_checkins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `agent_version` varchar(50) DEFAULT NULL,
  `checkin_time` timestamp NULL DEFAULT current_timestamp(),
  `wan_ip` varchar(45) DEFAULT NULL,
  `lan_ip` varchar(45) DEFAULT NULL,
  `opnsense_version` varchar(100) DEFAULT NULL,
  `uptime` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_firewall_time` (`firewall_id`,`checkin_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `agent_commands` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `command_id` varchar(64) NOT NULL,
  `command_type` varchar(32) NOT NULL,
  `command_data` text DEFAULT NULL,
  `status` enum('pending','executing','completed','failed') DEFAULT 'pending',
  `result` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `executed_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `command_id` (`command_id`),
  KEY `idx_firewall_id` (`firewall_id`),
  KEY `idx_status` (`status`),
  KEY `idx_command_id` (`command_id`),
  CONSTRAINT `agent_commands_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `agent_request_nonces` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `nonce` varchar(128) NOT NULL,
  `seen_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_firewall_nonce` (`firewall_id`,`nonce`),
  KEY `idx_seen_at` (`seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Short-lived nonce store; rows older than the signature window are pruned';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `agent_updates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `from_version` varchar(20) DEFAULT NULL,
  `to_version` varchar(20) NOT NULL,
  `status` enum('pending','downloading','installing','completed','failed') DEFAULT 'pending',
  `update_script` text DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_firewall_status` (`firewall_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ai_scan_findings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_id` int(11) NOT NULL,
  `source` enum('config','logs') DEFAULT 'config',
  `category` varchar(100) DEFAULT NULL,
  `severity` enum('info','low','medium','high','critical') DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `recommendation` text DEFAULT NULL,
  `affected_rules` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_report` (`report_id`),
  KEY `idx_severity` (`severity`),
  CONSTRAINT `ai_scan_findings_ibfk_1` FOREIGN KEY (`report_id`) REFERENCES `ai_scan_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ai_scan_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `config_snapshot_id` int(11) DEFAULT NULL,
  `scan_type` enum('config_only','config_with_logs') DEFAULT 'config_only',
  `provider` varchar(50) NOT NULL,
  `model` varchar(100) DEFAULT NULL,
  `overall_grade` varchar(5) DEFAULT NULL,
  `security_score` int(11) DEFAULT NULL,
  `risk_level` enum('low','medium','high','critical') DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `recommendations` text DEFAULT NULL,
  `concerns` text DEFAULT NULL,
  `improvements` text DEFAULT NULL,
  `full_report` longtext DEFAULT NULL,
  `scan_duration` int(11) DEFAULT NULL,
  `prompt_tokens` int(11) DEFAULT NULL,
  `completion_tokens` int(11) DEFAULT NULL,
  `total_tokens` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_firewall` (`firewall_id`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `ai_scan_reports_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ai_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `provider` varchar(50) NOT NULL,
  `api_key` text NOT NULL,
  `model` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_provider` (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `alert_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `alert_level` enum('info','warning','critical') NOT NULL,
  `alert_type` varchar(100) NOT NULL COMMENT 'Type: firewall_offline, backup_failed, agent_timeout, cert_expiring, config_changed',
  `firewall_id` int(11) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `recipients_count` int(11) DEFAULT 0,
  `notification_method` enum('email','pushover','both') NOT NULL,
  `sent_at` timestamp NULL DEFAULT current_timestamp(),
  `status` enum('sent','failed','partial') DEFAULT 'sent',
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_alert_level` (`alert_level`),
  KEY `idx_alert_type` (`alert_type`),
  KEY `idx_firewall_id` (`firewall_id`),
  KEY `idx_sent_at` (`sent_at`),
  CONSTRAINT `alert_history_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `alert_incident_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `incident_id` bigint(20) NOT NULL,
  `event` enum('opened','updated','acknowledged','resolved','notified','suppressed','reopened','escalated') NOT NULL,
  `detail` varchar(512) DEFAULT NULL,
  `actor` varchar(64) DEFAULT NULL COMMENT 'Username, or NULL for system',
  `occurred_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_incident` (`incident_id`,`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `alert_incidents` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `dedupe_key` varchar(191) DEFAULT NULL COMMENT 'Set while active, NULL once resolved, so UNIQUE dedupes only open incidents',
  `dedupe_source` varchar(191) NOT NULL COMMENT 'The key this incident was raised under, kept for history',
  `alert_type` varchar(64) NOT NULL COMMENT 'e.g. firewall.offline, gateway.down',
  `object_key` varchar(128) DEFAULT NULL COMMENT 'Sub-object, e.g. the gateway or tunnel name',
  `severity` enum('info','warning','critical') NOT NULL DEFAULT 'warning',
  `status` enum('open','acknowledged','resolved') NOT NULL DEFAULT 'open',
  `firewall_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `site_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `detail` text DEFAULT NULL,
  `metadata` text DEFAULT NULL COMMENT 'JSON, credential material scrubbed',
  `first_seen_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL,
  `occurrence_count` int(11) NOT NULL DEFAULT 1,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `acknowledged_by` varchar(64) DEFAULT NULL,
  `acknowledged_note` varchar(255) DEFAULT NULL,
  `notify_count` int(11) NOT NULL DEFAULT 0,
  `last_notified_at` timestamp NULL DEFAULT NULL,
  `suppressed` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 when notification was withheld, e.g. during maintenance',
  `suppressed_reason` varchar(128) DEFAULT NULL,
  `owner` varchar(128) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_active_dedupe` (`dedupe_key`),
  KEY `idx_status` (`status`),
  KEY `idx_type` (`alert_type`),
  KEY `idx_firewall` (`firewall_id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_first_seen` (`first_seen_at`),
  KEY `idx_severity` (`severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `alert_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `notification_name` varchar(100) NOT NULL,
  `notification_type` varchar(50) NOT NULL COMMENT 'email, pushover, webhook, slack, discord',
  `enabled` tinyint(1) DEFAULT 1,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Type-specific configuration (email addresses, API keys, URLs, etc)' CHECK (json_valid(`config`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `notification_name` (`notification_name`),
  KEY `idx_enabled` (`enabled`),
  KEY `idx_type` (`notification_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `alert_policies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `alert_type` varchar(50) NOT NULL,
  `firewall_id` int(11) NOT NULL DEFAULT 0,
  `object_key` varchar(191) NOT NULL DEFAULT '',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `threshold` varchar(50) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_scope` (`alert_type`,`firewall_id`,`object_key`),
  KEY `idx_lookup` (`alert_type`,`firewall_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `alert_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_name` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_name` (`setting_name`),
  KEY `idx_setting_name` (`setting_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `alert_trigger_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `trigger_id` int(11) NOT NULL,
  `notification_id` int(11) NOT NULL,
  `enabled` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_trigger_notification` (`trigger_id`,`notification_id`),
  KEY `idx_trigger` (`trigger_id`),
  KEY `idx_notification` (`notification_id`),
  CONSTRAINT `alert_trigger_notifications_ibfk_1` FOREIGN KEY (`trigger_id`) REFERENCES `alert_triggers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alert_trigger_notifications_ibfk_2` FOREIGN KEY (`notification_id`) REFERENCES `alert_notifications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `alert_triggers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `trigger_name` varchar(100) NOT NULL,
  `trigger_type` varchar(50) NOT NULL COMMENT 'firewall_down, high_cpu, high_memory, low_disk, cert_expiring, backup_failed, etc',
  `description` text DEFAULT NULL,
  `enabled` tinyint(1) DEFAULT 1,
  `threshold_value` varchar(50) DEFAULT NULL COMMENT 'Threshold for numeric triggers (e.g., 80 for 80% CPU)',
  `threshold_duration` int(11) DEFAULT NULL COMMENT 'Duration in minutes before triggering',
  `check_interval` int(11) DEFAULT 5 COMMENT 'How often to check this trigger in minutes',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `trigger_name` (`trigger_name`),
  KEY `idx_enabled` (`enabled`),
  KEY `idx_trigger_type` (`trigger_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `approved_commands` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `command_pattern` varchar(500) NOT NULL,
  `description` varchar(255) NOT NULL,
  `category` enum('system','network','packages','services','logs','security','agent','files','backup') NOT NULL,
  `risk_level` enum('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL,
  `requires_confirmation` tinyint(1) DEFAULT 0,
  `timeout_seconds` int(11) DEFAULT 30,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_pattern` (`command_pattern`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `occurred_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actor_type` enum('user','agent','system','anonymous') NOT NULL DEFAULT 'system',
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(64) DEFAULT NULL COMMENT 'Denormalised so history survives user deletion',
  `source_ip` varchar(45) DEFAULT NULL,
  `action` varchar(64) NOT NULL COMMENT 'Stable machine-readable action key, e.g. command.raw',
  `object_type` varchar(32) DEFAULT NULL COMMENT 'firewall, customer, site, user, setting, ...',
  `object_id` varchar(64) DEFAULT NULL,
  `firewall_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `site_id` int(11) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 1,
  `message` varchar(512) DEFAULT NULL,
  `metadata` text DEFAULT NULL COMMENT 'JSON. Never contains passwords, keys or MFA secrets',
  PRIMARY KEY (`id`),
  KEY `idx_occurred_at` (`occurred_at`),
  KEY `idx_action` (`action`),
  KEY `idx_user` (`user_id`),
  KEY `idx_firewall` (`firewall_id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_success` (`success`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `backups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) DEFAULT NULL,
  `backup_file` varchar(255) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `backup_type` enum('manual','automated') DEFAULT 'manual',
  `file_size` bigint(20) DEFAULT NULL,
  `storage_path` varchar(512) DEFAULT NULL COMMENT 'Absolute path outside the document root; NULL means legacy /var/www/opnsense/backups',
  `checksum_sha256` char(64) DEFAULT NULL,
  `validated` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 when the stored file parsed as a usable OPNsense config',
  `validation_error` varchar(255) DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT NULL COMMENT 'When bytes actually arrived, as opposed to when the row was queued',
  `source_filename` varchar(255) DEFAULT NULL COMMENT 'Agent-supplied name, kept as a label only; never used as a path',
  PRIMARY KEY (`id`),
  KEY `idx_firewall_created` (`firewall_id`,`created_at`),
  KEY `idx_uploaded_at` (`uploaded_at`),
  CONSTRAINT `backups_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `bandwidth_test_lock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `test_id` int(11) NOT NULL,
  `locked_at` timestamp NULL DEFAULT current_timestamp(),
  `lock_expires_at` timestamp NULL DEFAULT (current_timestamp() + interval 5 minute),
  PRIMARY KEY (`id`),
  KEY `idx_lock_expires` (`lock_expires_at`),
  KEY `bandwidth_test_lock_ibfk_1` (`firewall_id`),
  CONSTRAINT `bandwidth_test_lock_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `bandwidth_tests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `test_type` enum('scheduled','manual') NOT NULL DEFAULT 'scheduled',
  `download_speed` decimal(10,2) DEFAULT NULL COMMENT 'Download speed in Mbps',
  `upload_speed` decimal(10,2) DEFAULT NULL COMMENT 'Upload speed in Mbps',
  `latency` decimal(8,2) DEFAULT NULL COMMENT 'Latency in ms',
  `test_server` varchar(255) DEFAULT NULL COMMENT 'Speed test server used',
  `test_status` enum('running','completed','failed') NOT NULL DEFAULT 'running',
  `error_message` text DEFAULT NULL,
  `test_duration` int(11) DEFAULT NULL COMMENT 'Test duration in seconds',
  `tested_at` timestamp NULL DEFAULT current_timestamp(),
  `streams` smallint(6) DEFAULT NULL,
  `iperf_version` varchar(16) DEFAULT NULL,
  `local_cpu_percent` smallint(5) unsigned DEFAULT NULL COMMENT 'iperf3 host_total: summed across threads, can exceed 100',
  `remote_cpu_percent` smallint(5) unsigned DEFAULT NULL COMMENT 'iperf3 remote_total: summed across threads, can exceed 100',
  `command_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_firewall_tested` (`firewall_id`,`tested_at`),
  CONSTRAINT `bandwidth_tests_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `bug_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bug_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `author` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bug_id` (`bug_id`),
  CONSTRAINT `bug_comments_ibfk_1` FOREIGN KEY (`bug_id`) REFERENCES `bugs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `bugs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `severity` enum('low','medium','high','critical') DEFAULT 'medium',
  `status` enum('open','in-progress','testing','resolved','closed') DEFAULT 'open',
  `component` varchar(100) DEFAULT NULL,
  `assigned_to` varchar(100) DEFAULT NULL,
  `reported_by` varchar(100) DEFAULT NULL,
  `version_found` varchar(20) DEFAULT NULL,
  `version_fixed` varchar(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_severity` (`severity`),
  KEY `idx_component` (`component`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `bulk_operation_targets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bulk_id` int(11) NOT NULL,
  `firewall_id` int(11) NOT NULL,
  `status` enum('queued','succeeded','failed','skipped') NOT NULL DEFAULT 'queued',
  `command_id` int(11) DEFAULT NULL,
  `detail` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bulk` (`bulk_id`),
  KEY `idx_firewall` (`firewall_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `bulk_operations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `operation` varchar(64) NOT NULL COMMENT 'Action id from the bulk catalogue',
  `parameters` text DEFAULT NULL COMMENT 'JSON, validated before dispatch',
  `risk_level` enum('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'MEDIUM',
  `target_count` int(11) NOT NULL DEFAULT 0,
  `succeeded` int(11) NOT NULL DEFAULT 0,
  `failed` int(11) NOT NULL DEFAULT 0,
  `status` enum('running','completed','failed') NOT NULL DEFAULT 'running',
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_by_username` varchar(64) DEFAULT NULL,
  `created_from_ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_operation` (`operation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `change_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL,
  `change_type` enum('feature','bugfix','improvement','security','breaking','update_applied') DEFAULT NULL,
  `component` varchar(100) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `author` varchar(100) DEFAULT NULL,
  `commit_hash` varchar(40) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_version` (`version`),
  KEY `idx_change_type` (`change_type`),
  KEY `idx_component` (`component`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `changelog_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL,
  `release_date` date NOT NULL,
  `description` text NOT NULL,
  `changes` longtext NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_version` (`version`),
  KEY `idx_date` (`release_date`),
  KEY `idx_published` (`is_published`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `config_baselines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `backup_id` int(11) NOT NULL COMMENT 'The backups row declared correct',
  `config_hash` char(64) NOT NULL COMMENT 'Canonical fingerprint of that config',
  `section_hashes` text DEFAULT NULL COMMENT 'JSON map of section => hash, for section-level drift',
  `set_by_user_id` int(11) DEFAULT NULL,
  `set_by_username` varchar(64) DEFAULT NULL,
  `set_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` varchar(255) DEFAULT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Superseded baselines are retained for history',
  PRIMARY KEY (`id`),
  KEY `idx_firewall_current` (`firewall_id`,`is_current`),
  KEY `idx_backup` (`backup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `config_drift` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `baseline_id` int(11) NOT NULL,
  `current_backup_id` int(11) DEFAULT NULL,
  `current_hash` char(64) DEFAULT NULL,
  `status` enum('match','drifted','unknown','error') NOT NULL DEFAULT 'unknown',
  `sections_changed` text DEFAULT NULL COMMENT 'JSON: added / removed / modified section names',
  `change_count` int(11) NOT NULL DEFAULT 0,
  `first_detected_at` timestamp NULL DEFAULT NULL,
  `last_checked_at` timestamp NULL DEFAULT NULL,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `acknowledged_by` varchar(64) DEFAULT NULL,
  `acknowledged_note` varchar(255) DEFAULT NULL,
  `detail` text DEFAULT NULL COMMENT 'Error text when status = error',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_firewall` (`firewall_id`),
  KEY `idx_status` (`status`),
  KEY `idx_first_detected` (`first_detected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Current drift state per firewall; one row per firewall';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `config_restores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `backup_id` int(11) NOT NULL COMMENT 'The configuration being restored',
  `pre_restore_backup_id` int(11) DEFAULT NULL COMMENT 'Snapshot taken before overwriting',
  `status` enum('pending','pre_backup','dispatched','verifying','succeeded','failed','cancelled') NOT NULL DEFAULT 'pending',
  `command_id` int(11) DEFAULT NULL,
  `fetch_token` char(64) DEFAULT NULL COMMENT 'Single-use token the agent presents to fetch the config',
  `token_used_at` timestamp NULL DEFAULT NULL,
  `requested_by_user_id` int(11) DEFAULT NULL,
  `requested_by_username` varchar(64) DEFAULT NULL,
  `requested_from_ip` varchar(45) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `detail` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `dispatched_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `checkin_before` timestamp NULL DEFAULT NULL COMMENT 'Last check-in at dispatch, so a later one proves the agent returned',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fetch_token` (`fetch_token`),
  KEY `idx_firewall` (`firewall_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `config_snapshots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `config_hash` varchar(64) NOT NULL,
  `config_data` longtext DEFAULT NULL,
  `rule_count` int(11) DEFAULT NULL,
  `interface_count` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_firewall` (`firewall_id`),
  KEY `idx_hash` (`config_hash`),
  CONSTRAINT `config_snapshots_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `code` varchar(32) DEFAULT NULL COMMENT 'Short customer code used in search and reports',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `timezone` varchar(64) DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL COMMENT 'Comma-separated labels',
  `maintenance_window_start` time DEFAULT NULL,
  `maintenance_window_end` time DEFAULT NULL,
  `maintenance_window_days` varchar(32) DEFAULT NULL COMMENT 'Comma-separated day numbers, 0=Sunday',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `idx_customers_name` (`name`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `detected_threats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `log_analysis_id` int(11) NOT NULL,
  `threat_type` varchar(100) NOT NULL,
  `source_ip` varchar(45) DEFAULT NULL,
  `destination_ip` varchar(45) DEFAULT NULL,
  `port` int(11) DEFAULT NULL,
  `protocol` varchar(20) DEFAULT NULL,
  `severity` enum('info','low','medium','high','critical') DEFAULT 'medium',
  `description` text DEFAULT NULL,
  `first_seen` timestamp NULL DEFAULT current_timestamp(),
  `last_seen` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `occurrence_count` int(11) DEFAULT 1,
  `is_resolved` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `log_analysis_id` (`log_analysis_id`),
  KEY `idx_threat_type` (`threat_type`),
  KEY `idx_source_ip` (`source_ip`),
  KEY `idx_severity` (`severity`),
  KEY `idx_is_resolved` (`is_resolved`),
  CONSTRAINT `detected_threats_ibfk_1` FOREIGN KEY (`log_analysis_id`) REFERENCES `log_analysis_results` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `documentation_pages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `page_key` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` longtext NOT NULL,
  `category` varchar(50) NOT NULL,
  `display_order` int(11) DEFAULT 0,
  `last_updated` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `page_key` (`page_key`),
  KEY `idx_category` (`category`),
  KEY `idx_page_key` (`page_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `enrollment_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(64) NOT NULL,
  `firewall_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `used_at` timestamp NULL DEFAULT NULL,
  `hardware_id` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_token` (`token`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_firewall_id` (`firewall_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `features` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `category` varchar(100) NOT NULL,
  `status` enum('planned','development','production','deprecated') DEFAULT 'planned',
  `version` varchar(20) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `requires_agent` tinyint(1) DEFAULT 0,
  `api_enabled` tinyint(1) DEFAULT 0,
  `multi_tenant` tinyint(1) DEFAULT 0,
  `tech_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tech_details`)),
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_feature` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `firewall_agent_pings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `latency_ms` float NOT NULL,
  `ping_number` int(11) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_firewall_time` (`firewall_id`,`created_at`),
  CONSTRAINT `firewall_agent_pings_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `firewall_agents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `agent_version` varchar(20) DEFAULT NULL,
  `agent_type` varchar(20) DEFAULT 'primary',
  `last_checkin` timestamp NULL DEFAULT current_timestamp(),
  `status` enum('online','offline','unknown') DEFAULT 'unknown',
  `latency_ms` float DEFAULT 0,
  `wan_ip` varchar(45) DEFAULT NULL,
  `lan_ip` varchar(45) DEFAULT NULL,
  `lan_gateway` varchar(45) DEFAULT NULL,
  `ipv6_address` varchar(45) DEFAULT NULL,
  `opnsense_version` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `firewall_agent_type` (`firewall_id`,`agent_type`),
  CONSTRAINT `firewall_agents_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `firewall_ai_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `auto_scan_enabled` tinyint(1) DEFAULT 0,
  `scan_frequency` enum('daily','weekly','monthly') DEFAULT 'weekly',
  `scan_type` enum('config_only','config_with_logs') DEFAULT 'config_only',
  `include_logs` tinyint(1) DEFAULT 0,
  `last_scan_at` datetime DEFAULT NULL,
  `next_scan_at` datetime DEFAULT NULL,
  `preferred_provider` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `firewall_id` (`firewall_id`),
  KEY `idx_firewall` (`firewall_id`),
  KEY `idx_next_scan` (`next_scan_at`),
  CONSTRAINT `firewall_ai_settings_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `firewall_carp` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `vhid` varchar(16) NOT NULL,
  `interface` varchar(64) DEFAULT NULL,
  `address` varchar(45) DEFAULT NULL,
  `state` varchar(16) DEFAULT NULL COMMENT 'MASTER, BACKUP, INIT',
  `advskew` int(11) DEFAULT NULL,
  `advbase` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fw_vhid` (`firewall_id`,`vhid`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `firewall_certificates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `refid` varchar(64) NOT NULL COMMENT 'OPNsense certificate reference id',
  `name` varchar(255) DEFAULT NULL,
  `issuer` varchar(255) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `cert_type` varchar(32) DEFAULT NULL COMMENT 'server, user, ca',
  `not_before` datetime DEFAULT NULL,
  `not_after` datetime DEFAULT NULL,
  `days_remaining` int(11) DEFAULT NULL,
  `in_use` varchar(255) DEFAULT NULL COMMENT 'Where the certificate is referenced',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fw_cert` (`firewall_id`,`refid`),
  KEY `idx_expiry` (`not_after`),
  KEY `idx_days` (`days_remaining`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Certificate METADATA only. No private key material is ever stored here.';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `firewall_commands` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `command` text NOT NULL,
  `command_type` varchar(50) DEFAULT 'shell',
  `description` varchar(255) DEFAULT NULL,
  `is_update_command` tinyint(1) DEFAULT 0,
  `status` enum('pending','sent','completed','failed','cancelled') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `result` text DEFAULT NULL,
  `action` varchar(64) DEFAULT NULL COMMENT 'Structured action id, e.g. service_restart; NULL for raw shell',
  `parameters` text DEFAULT NULL COMMENT 'JSON parameters for the structured action, validated server-side',
  `is_raw` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = free-form shell (privileged); 0 = structured action',
  `risk_level` enum('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'MEDIUM',
  `queued_by_user_id` int(11) DEFAULT NULL,
  `queued_by_username` varchar(64) DEFAULT NULL COMMENT 'Denormalised so the audit trail survives user deletion',
  `queued_from_ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `firewall_id` (`firewall_id`),
  KEY `status` (`status`),
  KEY `idx_action` (`action`),
  KEY `idx_is_raw` (`is_raw`),
  KEY `idx_queued_by` (`queued_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `firewall_gateway_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `gateway_name` varchar(64) NOT NULL,
  `from_status` varchar(32) DEFAULT NULL,
  `to_status` varchar(32) DEFAULT NULL,
  `latency_ms` decimal(8,2) DEFAULT NULL,
  `loss_percent` decimal(5,2) DEFAULT NULL,
  `occurred_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fw_time` (`firewall_id`,`occurred_at`),
  KEY `idx_gateway` (`firewall_id`,`gateway_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `firewall_gateways` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `name` varchar(64) NOT NULL,
  `interface` varchar(64) DEFAULT NULL,
  `address` varchar(45) DEFAULT NULL,
  `monitor` varchar(45) DEFAULT NULL,
  `status` varchar(32) DEFAULT NULL COMMENT 'none/online, down, loss, delay, force_down',
  `latency_ms` decimal(8,2) DEFAULT NULL,
  `stddev_ms` decimal(8,2) DEFAULT NULL,
  `loss_percent` decimal(5,2) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `gateway_group` varchar(64) DEFAULT NULL,
  `priority` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fw_gateway` (`firewall_id`,`name`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `firewall_latency` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `latency_ms` float NOT NULL,
  `measured_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_firewall_time` (`firewall_id`,`measured_at`),
  CONSTRAINT `firewall_latency_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `firewall_services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `name` varchar(64) NOT NULL,
  `description` varchar(128) DEFAULT NULL,
  `running` tinyint(1) NOT NULL DEFAULT 0,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fw_service` (`firewall_id`,`name`),
  KEY `idx_running` (`running`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Only services the agent reported as present on that firewall';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `firewall_speedtest` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `download_mbps` float NOT NULL,
  `upload_mbps` float NOT NULL,
  `ping_ms` float DEFAULT NULL,
  `server_location` varchar(255) DEFAULT NULL,
  `test_date` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_firewall_time` (`firewall_id`,`test_date`),
  CONSTRAINT `firewall_speedtest_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `firewall_ssh_keys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `key_type` varchar(50) NOT NULL DEFAULT 'RSA',
  `fingerprint` varchar(255) NOT NULL,
  `key_bits` int(11) DEFAULT 4096,
  `created_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_firewall_id` (`firewall_id`),
  KEY `idx_is_active` (`is_active`),
  CONSTRAINT `firewall_ssh_keys_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `firewall_system_stats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `recorded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `cpu_load_1min` decimal(5,2) DEFAULT NULL,
  `cpu_load_5min` decimal(5,2) DEFAULT NULL,
  `cpu_load_15min` decimal(5,2) DEFAULT NULL,
  `memory_total_mb` int(11) DEFAULT NULL,
  `memory_used_mb` int(11) DEFAULT NULL,
  `memory_percent` decimal(5,2) DEFAULT NULL,
  `disk_total_gb` int(11) DEFAULT NULL,
  `disk_used_gb` int(11) DEFAULT NULL,
  `disk_percent` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_firewall_time` (`firewall_id`,`recorded_at`),
  CONSTRAINT `firewall_system_stats_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `firewall_tags` (
  `firewall_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  PRIMARY KEY (`firewall_id`,`tag_id`),
  KEY `tag_id` (`tag_id`),
  CONSTRAINT `firewall_tags_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `firewall_tags_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `firewall_traffic_stats` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `recorded_at` timestamp NULL DEFAULT current_timestamp(),
  `wan_interface` varchar(20) NOT NULL,
  `bytes_in` bigint(20) unsigned NOT NULL DEFAULT 0,
  `bytes_out` bigint(20) unsigned NOT NULL DEFAULT 0,
  `packets_in` bigint(20) unsigned NOT NULL DEFAULT 0,
  `packets_out` bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_firewall_time` (`firewall_id`,`recorded_at`),
  KEY `idx_recorded_at` (`recorded_at`),
  CONSTRAINT `firewall_traffic_stats_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='WAN interface traffic statistics';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `firewall_updaters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `updater_version` varchar(20) NOT NULL,
  `last_checkin` timestamp NULL DEFAULT NULL,
  `status` enum('active','inactive','error') DEFAULT 'inactive',
  `last_update_result` varchar(50) DEFAULT NULL,
  `last_update_message` text DEFAULT NULL,
  `last_update_time` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_firewall_updater` (`firewall_id`),
  KEY `idx_last_checkin` (`last_checkin`),
  KEY `idx_status` (`status`),
  CONSTRAINT `firewall_updaters_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `firewall_vpn_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `vpn_type` varchar(16) NOT NULL,
  `name` varchar(128) NOT NULL,
  `from_status` varchar(32) DEFAULT NULL,
  `to_status` varchar(32) DEFAULT NULL,
  `occurred_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fw_time` (`firewall_id`,`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `firewall_vpn_tunnels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `vpn_type` enum('wireguard','openvpn','ipsec') NOT NULL,
  `name` varchar(128) NOT NULL,
  `peer` varchar(255) DEFAULT NULL COMMENT 'Peer name or public key fingerprint, never a private key',
  `endpoint` varchar(255) DEFAULT NULL,
  `status` varchar(32) DEFAULT NULL COMMENT 'up, down, connecting, disabled',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `latest_handshake` timestamp NULL DEFAULT NULL COMMENT 'WireGuard',
  `connected_since` timestamp NULL DEFAULT NULL,
  `rx_bytes` bigint(20) DEFAULT NULL,
  `tx_bytes` bigint(20) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fw_vpn` (`firewall_id`,`vpn_type`,`name`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `firewall_wan_interfaces` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `interface_name` varchar(50) NOT NULL,
  `status` varchar(20) DEFAULT NULL COMMENT 'up, down, no_carrier',
  `ip_address` varchar(50) DEFAULT NULL,
  `netmask` varchar(50) DEFAULT NULL,
  `gateway` varchar(50) DEFAULT NULL,
  `media` varchar(100) DEFAULT NULL COMMENT 'Interface speed/type',
  `rx_packets` bigint(20) DEFAULT 0,
  `rx_errors` bigint(20) DEFAULT 0,
  `rx_bytes` bigint(20) DEFAULT 0,
  `tx_packets` bigint(20) DEFAULT 0,
  `tx_errors` bigint(20) DEFAULT 0,
  `tx_bytes` bigint(20) DEFAULT 0,
  `last_updated` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_firewall_interface` (`firewall_id`,`interface_name`),
  KEY `idx_firewall_id` (`firewall_id`),
  KEY `idx_interface_name` (`interface_name`),
  KEY `idx_status` (`status`),
  KEY `idx_last_updated` (`last_updated`),
  CONSTRAINT `firewall_wan_interfaces_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks individual WAN interface statistics per firewall';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `firewalls` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hostname` varchar(128) NOT NULL,
  `hardware_id` varchar(64) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `external_hostname` varchar(255) DEFAULT NULL,
  `external_port` int(11) DEFAULT 443,
  `reverse_proxy_url` varchar(255) DEFAULT NULL,
  `status` varchar(32) DEFAULT NULL,
  `enrolled_at` timestamp NULL DEFAULT current_timestamp(),
  `customer_name` varchar(128) DEFAULT NULL,
  `customer_group` varchar(128) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `wan_ip` varchar(45) DEFAULT NULL,
  `ipv6_address` varchar(45) DEFAULT NULL,
  `version` text DEFAULT NULL,
  `agent_version` varchar(100) DEFAULT NULL,
  `last_checkin` timestamp NULL DEFAULT NULL,
  `checkin_interval` int(11) DEFAULT 180,
  `updates_available` tinyint(1) DEFAULT 0,
  `reboot_required` tinyint(1) DEFAULT 0,
  `last_update_check` timestamp NULL DEFAULT NULL,
  `current_version` text DEFAULT NULL,
  `available_version` text DEFAULT NULL,
  `api_key` text DEFAULT NULL,
  `ssh_private_key` text DEFAULT NULL,
  `ssh_public_key` text DEFAULT NULL,
  `ssh_tunnel_port` int(11) DEFAULT NULL,
  `web_port` int(11) DEFAULT 443,
  `api_secret` text DEFAULT NULL,
  `proxy_port` int(11) DEFAULT NULL,
  `proxy_enabled` tinyint(1) DEFAULT 0,
  `tunnel_active` tinyint(1) DEFAULT 0,
  `tunnel_client_ip` varchar(45) DEFAULT NULL,
  `tunnel_type` varchar(20) DEFAULT 'request_queue',
  `tunnel_port` int(11) DEFAULT NULL,
  `tunnel_established` datetime DEFAULT NULL,
  `uuid` varchar(36) DEFAULT NULL,
  `lan_ip` varchar(45) DEFAULT NULL,
  `opnsense_version` text DEFAULT NULL,
  `uptime` varchar(100) DEFAULT NULL,
  `update_requested` tinyint(1) DEFAULT 0,
  `agent_cleanup_requested` tinyint(1) DEFAULT 0,
  `update_requested_at` timestamp NULL DEFAULT NULL,
  `update_type` enum('full','firmware','packages') DEFAULT 'full',
  `update_started_at` timestamp NULL DEFAULT NULL,
  `update_completed_at` timestamp NULL DEFAULT NULL,
  `alerts_enabled` tinyint(1) DEFAULT 1 COMMENT 'Enable alerts for this firewall',
  `offline_alert_threshold` int(11) DEFAULT 300 COMMENT 'Seconds before offline alert (default 5 min)',
  `wan_netmask` varchar(45) DEFAULT NULL,
  `wan_gateway` varchar(45) DEFAULT NULL,
  `wan_dns_primary` varchar(45) DEFAULT NULL,
  `wan_dns_secondary` varchar(45) DEFAULT NULL,
  `lan_netmask` varchar(45) DEFAULT NULL,
  `lan_network` varchar(45) DEFAULT NULL,
  `network_config_updated` timestamp NULL DEFAULT NULL,
  `wake_agent` tinyint(1) DEFAULT 0 COMMENT 'Flag to wake agent for immediate checkin',
  `wake_requested_at` datetime DEFAULT NULL COMMENT 'When wake was requested',
  `force_agent_upgrade` tinyint(1) DEFAULT 0,
  `secure_outbound_lockdown` tinyint(1) DEFAULT 0,
  `track_change_requested` tinyint(1) DEFAULT 0,
  `track_change_requested_at` datetime DEFAULT NULL,
  `track_change_target` varchar(20) DEFAULT NULL,
  `track_change_status` varchar(50) DEFAULT NULL,
  `allowed_webgui_ips` text DEFAULT NULL,
  `ssl_cert_fix_needed` tinyint(1) DEFAULT 0,
  `scheduled_speedtest_time` time DEFAULT NULL,
  `last_speedtest_date` date DEFAULT NULL,
  `onboarded` tinyint(1) DEFAULT 0,
  `onboard_started_at` datetime DEFAULT NULL,
  `onboarded_at` datetime DEFAULT NULL,
  `force_speedtest_next_checkin` tinyint(1) DEFAULT 0,
  `wan_interfaces` varchar(255) DEFAULT NULL COMMENT 'Comma-separated list of WAN interface names',
  `wan_groups` varchar(255) DEFAULT NULL COMMENT 'Comma-separated list of WAN gateway groups',
  `wan_interface_stats` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON array of WAN interface statistics' CHECK (json_valid(`wan_interface_stats`)),
  `speedtest_interval_hours` int(11) DEFAULT 4,
  `api_key_issued_at` timestamp NULL DEFAULT NULL COMMENT 'When the server last provisioned an API key to this agent',
  `api_key_confirmed` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 once the agent has successfully presented its API key; auth then fails closed',
  `agent_signing_supported` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 once the agent has sent a valid HMAC-signed request',
  `agent_last_signed_at` timestamp NULL DEFAULT NULL,
  `agent_auth_failures` int(11) NOT NULL DEFAULT 0 COMMENT 'Consecutive failed agent authentication attempts; reset on success',
  `agent_last_auth_failure_at` timestamp NULL DEFAULT NULL,
  `agent_clock_skew_seconds` int(11) DEFAULT NULL COMMENT 'Last observed difference between agent and server clocks',
  `last_backup_at` timestamp NULL DEFAULT NULL,
  `last_backup_status` varchar(32) DEFAULT NULL,
  `last_backup_error` varchar(255) DEFAULT NULL,
  `agent_api_key` text DEFAULT NULL COMMENT 'OPNManager agent bearer credential (encrypted at rest)',
  `agent_api_secret` text DEFAULT NULL COMMENT 'OPNManager agent HMAC signing secret (encrypted at rest)',
  `customer_id` int(11) DEFAULT NULL,
  `site_id` int(11) DEFAULT NULL,
  `carp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `carp_state` varchar(16) DEFAULT NULL COMMENT 'Overall MASTER/BACKUP for the node',
  `carp_peer_host` varchar(255) DEFAULT NULL,
  `carp_sync_status` varchar(32) DEFAULT NULL,
  `ha_peer_firewall_id` int(11) DEFAULT NULL COMMENT 'Resolved HA partner, used to avoid updating both members at once',
  `update_ring` enum('canary','pilot','production') NOT NULL DEFAULT 'production' COMMENT 'Rollout ring. Not a customer tier.',
  `last_update_result` varchar(32) DEFAULT NULL,
  `last_update_error` varchar(255) DEFAULT NULL,
  `last_update_attempt_at` timestamp NULL DEFAULT NULL,
  `agent_rollout_pilot` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Receives agent releases at rollout stage pilot',
  `platform_family` varchar(16) DEFAULT NULL COMMENT 'opnsense | pfsense | NULL (unknown)',
  `platform_edition` varchar(16) DEFAULT NULL COMMENT 'community | plus | business | unknown (pfSense); NULL for OPNsense',
  `platform_os_version` varchar(64) DEFAULT NULL COMMENT 'Product version as the platform reports it, e.g. 26.7.8 or 2.8.1-RELEASE',
  `platform_base_version` varchar(64) DEFAULT NULL COMMENT 'FreeBSD release. Recorded, never used on its own to identify the product',
  `platform_config_version` varchar(16) DEFAULT NULL COMMENT 'config.xml <version> schema revision',
  `platform_packages` text DEFAULT NULL COMMENT 'JSON list of relevant installed packages',
  `platform_probe` text DEFAULT NULL COMMENT 'JSON tool/mechanism probe results from the agent',
  `platform_detection_source` varchar(64) DEFAULT NULL,
  `platform_detection_confidence` varchar(8) DEFAULT NULL COMMENT 'high | medium | low',
  `platform_detection_conflict` varchar(255) DEFAULT NULL COMMENT 'Set when evidence sources disagree; forces read-only',
  `platform_verified_at` timestamp NULL DEFAULT NULL,
  `agent_flavor` varchar(32) DEFAULT NULL COMMENT 'opnsense-plugin | pfsense-agent',
  `speedtest_server` varchar(255) DEFAULT NULL COMMENT 'Comma-separated iperf3 host[:port] list for this firewall; NULL = global setting',
  `speedtest_streams` smallint(6) DEFAULT NULL COMMENT 'Parallel streams for this firewall; NULL = global setting',
  `wan_link_down_mbps` int(11) DEFAULT NULL COMMENT 'Provisioned downstream, for showing results as a share of the circuit',
  `wan_link_up_mbps` int(11) DEFAULT NULL COMMENT 'Provisioned upstream',
  PRIMARY KEY (`id`),
  UNIQUE KEY `hardware_id` (`hardware_id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `idx_wake_agent` (`wake_agent`,`wake_requested_at`),
  KEY `idx_wan_interfaces` (`wan_interfaces`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_site_id` (`site_id`),
  KEY `idx_carp_state` (`carp_state`),
  KEY `idx_ha_peer` (`ha_peer_firewall_id`),
  KEY `idx_update_ring` (`update_ring`),
  KEY `idx_platform_family` (`platform_family`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `geoip_blocks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `country_code` char(2) NOT NULL,
  `country_name` varchar(100) NOT NULL,
  `action` enum('block','allow') DEFAULT 'block',
  `enabled` tinyint(1) DEFAULT 1,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_country` (`country_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `global_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `integration_deliveries` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `event_row_id` bigint(20) NOT NULL,
  `event_id` char(36) NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `destination_id` int(11) NOT NULL,
  `route_id` int(11) DEFAULT NULL,
  `idempotency_key` char(64) NOT NULL,
  `action` varchar(24) NOT NULL DEFAULT 'notify',
  `status` enum('pending','leased','sending','retry','succeeded','dead','needs_reconciliation','suppressed','cancelled') NOT NULL DEFAULT 'pending',
  `suppress_reason` varchar(128) DEFAULT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `rate_limit_hits` int(11) NOT NULL DEFAULT 0,
  `max_attempts` int(11) NOT NULL DEFAULT 6,
  `next_attempt_at` datetime NOT NULL,
  `lease_until` datetime DEFAULT NULL,
  `lease_token` char(32) DEFAULT NULL,
  `last_http_status` int(11) DEFAULT NULL,
  `last_error` varchar(500) DEFAULT NULL COMMENT 'Redacted',
  `resolved_ip` varchar(45) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `site_id` int(11) DEFAULT NULL,
  `firewall_id` int(11) DEFAULT NULL,
  `incident_id` bigint(20) DEFAULT NULL,
  `severity` varchar(16) DEFAULT NULL,
  `is_test` tinyint(1) NOT NULL DEFAULT 0,
  `replay_count` int(11) NOT NULL DEFAULT 0,
  `replay_of` bigint(20) DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_idem` (`destination_id`,`idempotency_key`),
  KEY `idx_due` (`status`,`next_attempt_at`),
  KEY `idx_dest_status` (`destination_id`,`status`),
  KEY `idx_incident_dest` (`incident_id`,`destination_id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_event` (`event_row_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `integration_delivery_attempts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `delivery_id` bigint(20) NOT NULL,
  `attempt_no` int(11) NOT NULL,
  `started_at` datetime NOT NULL,
  `duration_ms` int(11) DEFAULT NULL,
  `outcome` varchar(24) NOT NULL,
  `http_status` int(11) DEFAULT NULL,
  `resolved_ip` varchar(45) DEFAULT NULL,
  `error` varchar(500) DEFAULT NULL COMMENT 'Redacted',
  PRIMARY KEY (`id`),
  KEY `idx_delivery` (`delivery_id`,`attempt_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `integration_destinations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `type` enum('email','webhook','slack','teams','ticket_webhook') NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `customer_id` int(11) DEFAULT NULL COMMENT 'NULL = global; scoped staff see only their customers',
  `config_json` text DEFAULT NULL COMMENT 'Non-secret settings (JSON)',
  `secrets_enc` text DEFAULT NULL COMMENT 'Secrets (JSON) encrypted with opnmgr_encrypt: url, signing/auth/inbound secrets',
  `allow_internal` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Permit private/loopback targets (internal helpdesks)',
  `ip_change_policy` enum('warn','block') NOT NULL DEFAULT 'warn',
  `concurrency_limit` int(11) NOT NULL DEFAULT 2,
  `max_attempts` int(11) NOT NULL DEFAULT 6,
  `timeout_seconds` int(11) NOT NULL DEFAULT 10,
  `on_resolve` enum('resolve','comment','none') NOT NULL DEFAULT 'resolve',
  `on_recurrence` enum('reopen','new_ticket') NOT NULL DEFAULT 'new_ticket',
  `reopen_window_minutes` int(11) NOT NULL DEFAULT 1440,
  `on_external_close` enum('note','reopen_ticket') NOT NULL DEFAULT 'note',
  `inbound_ack_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `inbound_comment_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `inbound_assign_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `last_ip` varchar(45) DEFAULT NULL,
  `last_ip_changed_at` datetime DEFAULT NULL,
  `ip_change_pending` tinyint(1) NOT NULL DEFAULT 0,
  `paused_until` datetime DEFAULT NULL COMMENT 'Set from Retry-After on a 429',
  `created_by` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`),
  KEY `idx_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `integration_escalations` (
  `route_id` int(11) NOT NULL,
  `incident_id` bigint(20) NOT NULL,
  `fired_at` datetime NOT NULL,
  PRIMARY KEY (`route_id`,`incident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `integration_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `event_id` char(36) NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `event_version` int(11) NOT NULL DEFAULT 1,
  `dedupe_key` varchar(191) DEFAULT NULL,
  `occurred_at` datetime NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `site_id` int(11) DEFAULT NULL,
  `firewall_id` int(11) DEFAULT NULL,
  `incident_id` bigint(20) DEFAULT NULL,
  `severity` varchar(16) DEFAULT NULL,
  `origin_kind` varchar(16) NOT NULL DEFAULT 'local',
  `origin_destination_id` int(11) DEFAULT NULL COMMENT 'Destination that caused this event (inbound sync); never delivered back to it',
  `forced_route_id` int(11) DEFAULT NULL COMMENT 'Escalation events go only to their route',
  `correlation_id` varchar(64) DEFAULT NULL,
  `envelope_json` mediumtext NOT NULL,
  `status` enum('pending','routed','failed') NOT NULL DEFAULT 'pending',
  `routed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_event_id` (`event_id`),
  UNIQUE KEY `uniq_dedupe` (`dedupe_key`),
  KEY `idx_status` (`status`,`id`),
  KEY `idx_incident` (`incident_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `integration_inbound_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `destination_id` int(11) DEFAULT NULL,
  `received_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `remote_ip` varchar(45) DEFAULT NULL,
  `result` varchar(32) NOT NULL,
  `action` varchar(32) DEFAULT NULL,
  `external_id` varchar(191) DEFAULT NULL,
  `incident_id` bigint(20) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `detail` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dest` (`destination_id`,`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `integration_inbound_nonces` (
  `destination_id` int(11) NOT NULL,
  `nonce_hash` char(64) NOT NULL,
  `seen_at` datetime NOT NULL,
  PRIMARY KEY (`destination_id`,`nonce_hash`),
  KEY `idx_seen` (`seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `integration_routes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `destination_id` int(11) NOT NULL,
  `event_types` varchar(255) NOT NULL DEFAULT '' COMMENT 'csv; empty = all',
  `customer_id` int(11) DEFAULT NULL,
  `site_id` int(11) DEFAULT NULL,
  `severities` varchar(64) NOT NULL DEFAULT '' COMMENT 'csv; empty = all',
  `incident_types` varchar(500) NOT NULL DEFAULT '' COMMENT 'csv of alert types; empty = all',
  `tag_ids` varchar(255) NOT NULL DEFAULT '' COMMENT 'csv; any-of; empty = all',
  `schedule_json` text DEFAULT NULL COMMENT '{"timezone","days":[1-7],"start":"HH:MM","end":"HH:MM"}',
  `schedule_outside` enum('skip','defer') NOT NULL DEFAULT 'skip',
  `escalate_after_minutes` int(11) DEFAULT NULL COMMENT 'Set = escalation route: fires only if still unacknowledged after N minutes',
  `created_by` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_destination` (`destination_id`),
  KEY `idx_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `integration_ticket_links` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `destination_id` int(11) NOT NULL,
  `incident_id` bigint(20) NOT NULL,
  `active_key` varchar(40) DEFAULT NULL COMMENT 'dest:incident while the link is live; NULL once closed (UNIQUE)',
  `link_state` enum('creating','active','needs_reconciliation','closed','abandoned') NOT NULL DEFAULT 'creating',
  `outcome_unknown` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'A create was attempted and its result is unknown',
  `create_idempotency_key` char(64) DEFAULT NULL,
  `create_attempted_at` datetime DEFAULT NULL,
  `external_id` varchar(191) DEFAULT NULL,
  `external_url` varchar(500) DEFAULT NULL,
  `ticket_state` enum('unknown','open','acknowledged','closed') NOT NULL DEFAULT 'unknown',
  `incident_state` varchar(16) DEFAULT NULL COMMENT 'Observed local incident state at last sync',
  `closed_while_incident_active` tinyint(1) NOT NULL DEFAULT 0,
  `external_assignee` varchar(128) DEFAULT NULL,
  `reopened_from_link_id` bigint(20) DEFAULT NULL,
  `last_auto_reopen_at` datetime DEFAULT NULL,
  `last_synced_at` datetime DEFAULT NULL,
  `last_inbound_at` datetime DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `site_id` int(11) DEFAULT NULL,
  `firewall_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_active` (`active_key`),
  KEY `idx_incident` (`incident_id`),
  KEY `idx_external` (`destination_id`,`external_id`),
  KEY `idx_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ip_reputation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `reputation_score` int(11) DEFAULT 0,
  `threat_count` int(11) DEFAULT 0,
  `blocked_count` int(11) DEFAULT 0,
  `countries` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`countries`)),
  `categories` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`categories`)),
  `is_blacklisted` tinyint(1) DEFAULT 0,
  `first_seen` timestamp NULL DEFAULT current_timestamp(),
  `last_seen` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_address` (`ip_address`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_reputation_score` (`reputation_score`),
  KEY `idx_is_blacklisted` (`is_blacklisted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `log_analysis_results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_id` int(11) NOT NULL,
  `log_type` varchar(50) NOT NULL,
  `lines_analyzed` int(11) DEFAULT 0,
  `active_threats` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`active_threats`)),
  `suspicious_ips` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`suspicious_ips`)),
  `blocked_attempts` int(11) DEFAULT 0,
  `failed_auth_attempts` int(11) DEFAULT 0,
  `anomaly_score` int(11) DEFAULT 0,
  `threat_level` enum('none','low','medium','high','critical') DEFAULT 'none',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_report_id` (`report_id`),
  KEY `idx_threat_level` (`threat_level`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `log_analysis_results_ibfk_1` FOREIGN KEY (`report_id`) REFERENCES `ai_scan_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `log_processing_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `job_type` enum('manual','scheduled','triggered') DEFAULT 'manual',
  `status` enum('pending','running','completed','failed') DEFAULT 'pending',
  `log_types` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`log_types`)),
  `lines_processed` int(11) DEFAULT 0,
  `threats_found` int(11) DEFAULT 0,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_firewall_id` (`firewall_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `log_processing_jobs_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `failed_attempts` int(11) DEFAULT 1,
  `last_failed` datetime NOT NULL,
  `locked_until` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_attempt` (`username`,`ip_address`),
  KEY `idx_locked_until` (`locked_until`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `maintenance_windows` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `scope` enum('firewall','site','customer') NOT NULL,
  `scope_id` int(11) NOT NULL COMMENT 'firewall_id, site_id or customer_id per scope',
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('scheduled','active','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  `suppress_alerts` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Data collection and health display continue regardless',
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_by_username` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_scope` (`scope`,`scope_id`),
  KEY `idx_window` (`starts_at`,`ends_at`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `platform_validations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `platform_family` varchar(16) NOT NULL,
  `platform_edition` varchar(16) NOT NULL DEFAULT '',
  `version_prefix` varchar(32) NOT NULL COMMENT 'Matched as a prefix of platform_os_version, e.g. 26.7',
  `capability` varchar(64) NOT NULL,
  `agent_version` varchar(32) DEFAULT NULL,
  `validated_at` datetime NOT NULL,
  `evidence` varchar(512) NOT NULL COMMENT 'What was run, on which device, with what result',
  `recorded_by` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_validation` (`platform_family`,`platform_edition`,`version_prefix`,`capability`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `platform_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL,
  `release_date` date NOT NULL,
  `status` enum('development','testing','released','deprecated') DEFAULT 'development',
  `description` text DEFAULT NULL,
  `changelog` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `policy_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) NOT NULL,
  `revision_id` int(11) NOT NULL COMMENT 'Pinned published revision',
  `scope_type` enum('global','customer','site','firewall') NOT NULL,
  `scope_id` int(11) DEFAULT NULL COMMENT 'NULL for global',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_scope` (`scope_type`,`scope_id`),
  KEY `idx_template` (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `policy_deployment_targets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `deployment_id` int(11) NOT NULL,
  `firewall_id` int(11) NOT NULL,
  `order_index` int(11) NOT NULL DEFAULT 0 COMMENT 'Ring order, then hostname',
  `ring` varchar(16) DEFAULT NULL,
  `ha_role` enum('standalone','primary','secondary_verify') NOT NULL DEFAULT 'standalone',
  `status` enum('planned','blocked','stale','approved','pre_backup','applying','verifying','confirming','succeeded','failed','rolled_back','held','skipped','cancelled') NOT NULL DEFAULT 'planned',
  `approvable` tinyint(1) NOT NULL DEFAULT 0,
  `base_backup_id` int(11) DEFAULT NULL,
  `base_fingerprint` char(64) DEFAULT NULL COMMENT 'sha256 of the raw base config bytes',
  `plan_hash` char(64) DEFAULT NULL,
  `artifact_sha256` char(64) DEFAULT NULL COMMENT 'sha256 of the rendered full config; the file itself is re-rendered on fetch, never stored',
  `plan_json` mediumtext DEFAULT NULL COMMENT 'Sanitized semantic plan (no secrets)',
  `change_count` int(11) NOT NULL DEFAULT 0,
  `blockers` text DEFAULT NULL COMMENT 'JSON list of reasons the plan cannot be approved',
  `approval_hash` char(64) DEFAULT NULL,
  `approved_plan_hash` char(64) DEFAULT NULL,
  `approved_fingerprint` char(64) DEFAULT NULL,
  `approved_by` varchar(64) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `pre_backup_id` int(11) DEFAULT NULL,
  `verify_backup_id` int(11) DEFAULT NULL,
  `apply_command_id` int(11) DEFAULT NULL,
  `confirm_command_id` int(11) DEFAULT NULL,
  `rollback_command_id` int(11) DEFAULT NULL,
  `fetch_token` char(64) DEFAULT NULL,
  `token_used_at` datetime DEFAULT NULL,
  `checkin_before` datetime DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL,
  `recovery_deadline` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `detail` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fetch_token` (`fetch_token`),
  KEY `idx_deployment` (`deployment_id`),
  KEY `idx_firewall` (`firewall_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `policy_deployments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) NOT NULL,
  `revision_id` int(11) NOT NULL,
  `status` enum('planned','approved','running','completed','stopped','cancelled') NOT NULL DEFAULT 'planned',
  `notes` varchar(255) DEFAULT NULL,
  `created_by` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_by` varchar(64) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `detail` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `policy_exceptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL,
  `object_key` varchar(64) NOT NULL COMMENT 'Component key, or * for the whole template',
  `reason` varchar(512) NOT NULL,
  `owner` varchar(128) NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_by` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `revoked_at` datetime DEFAULT NULL,
  `revoked_by` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fw_tpl` (`firewall_id`,`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `policy_firewall_locks` (
  `firewall_id` int(11) NOT NULL,
  `target_id` int(11) NOT NULL,
  `acquired_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`firewall_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='One in-flight policy deployment per firewall';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `policy_firewall_state` (
  `firewall_id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL,
  `desired_revision_id` int(11) DEFAULT NULL,
  `last_applied_revision_id` int(11) DEFAULT NULL,
  `last_applied_at` datetime DEFAULT NULL,
  `last_deployment_id` int(11) DEFAULT NULL,
  `compliance` enum('compliant','drifted','pending','exception','conflict','invalid','unknown') NOT NULL DEFAULT 'unknown',
  `evaluated_backup_id` int(11) DEFAULT NULL,
  `out_of_band_changes` int(11) NOT NULL DEFAULT 0,
  `detail` text DEFAULT NULL COMMENT 'JSON summary, no secrets',
  `last_evaluated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`firewall_id`,`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `policy_object_mappings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL,
  `object_key` varchar(64) NOT NULL,
  `target_ref` varchar(128) NOT NULL COMMENT 'Existing alias name, rule uuid or pfSense tracker to adopt',
  `created_by` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_mapping` (`firewall_id`,`template_id`,`object_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `policy_template_revisions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) NOT NULL,
  `revision` int(11) NOT NULL,
  `status` enum('draft','published','retired') NOT NULL DEFAULT 'draft',
  `content` mediumtext NOT NULL COMMENT 'JSON: {variables:[...], components:[...]}',
  `content_hash` char(64) NOT NULL COMMENT 'sha256 of canonical content; frozen at publish',
  `notes` varchar(255) DEFAULT NULL,
  `created_by` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `published_by` varchar(64) DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `retired_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_template_revision` (`template_id`,`revision`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `policy_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `description` text DEFAULT NULL,
  `is_retired` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `policy_variable_values` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `scope_type` enum('global','customer','site','firewall') NOT NULL,
  `scope_id` int(11) NOT NULL DEFAULT 0 COMMENT '0 for global',
  `template_id` int(11) NOT NULL DEFAULT 0 COMMENT '0 = applies to every template',
  `name` varchar(64) NOT NULL,
  `value` text NOT NULL COMMENT 'JSON-encoded value',
  `updated_by` varchar(64) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_value` (`scope_type`,`scope_id`,`template_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `report_ai_summaries` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `input_hash` char(64) NOT NULL COMMENT 'sha256 of provider, model, prompt version and the exact facts sent',
  `provider` varchar(50) NOT NULL,
  `model` varchar(100) DEFAULT NULL,
  `status` enum('ok','discarded','failed') NOT NULL,
  `narrative` text DEFAULT NULL,
  `detail` varchar(255) DEFAULT NULL,
  `prompt_tokens` int(11) DEFAULT NULL,
  `completion_tokens` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_hash` (`input_hash`,`status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `report_deliveries` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `report_id` bigint(20) NOT NULL,
  `recipient_address` varchar(255) NOT NULL,
  `status` enum('queued','sent','failed') NOT NULL DEFAULT 'queued',
  `attempts` tinyint(4) NOT NULL DEFAULT 0,
  `next_attempt_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_error` varchar(255) DEFAULT NULL,
  `share_id` bigint(20) DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_delivery` (`report_id`,`recipient_address`),
  KEY `idx_status` (`status`,`next_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `report_jobs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `dedupe_key` char(64) DEFAULT NULL COMMENT 'sha256 of type/scope/period/sections/options; NULL once failed so a retry can be requested',
  `dedupe_source` char(64) NOT NULL,
  `report_type` enum('firewall','site','customer','fleet') NOT NULL,
  `scope_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL COMMENT 'Owning customer; NULL for fleet reports',
  `params` text NOT NULL COMMENT 'JSON: period, timezone, sections, options',
  `status` enum('queued','running','succeeded','failed','cancelled') NOT NULL DEFAULT 'queued',
  `attempts` tinyint(4) NOT NULL DEFAULT 0,
  `max_attempts` tinyint(4) NOT NULL DEFAULT 3,
  `next_attempt_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_error` varchar(512) DEFAULT NULL,
  `report_id` bigint(20) DEFAULT NULL,
  `schedule_id` int(11) DEFAULT NULL,
  `requested_by` varchar(64) DEFAULT NULL,
  `requested_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_dedupe` (`dedupe_key`),
  KEY `idx_status` (`status`,`next_attempt_at`),
  KEY `idx_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `report_recipients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL DEFAULT 0 COMMENT '0 = internal recipients for fleet reports',
  `email` varchar(255) NOT NULL,
  `name` varchar(128) DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_recipient` (`customer_id`,`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `report_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `report_type` enum('firewall','site','customer','fleet') NOT NULL,
  `scope_id` int(11) DEFAULT NULL COMMENT 'firewall_id, site_id or customer_id per type; NULL for fleet',
  `customer_id` int(11) DEFAULT NULL COMMENT 'Owning customer, denormalised for scoping; NULL for fleet',
  `cadence` enum('weekly','monthly') NOT NULL,
  `run_day` tinyint(4) NOT NULL DEFAULT 1 COMMENT 'weekly: 1=Mon..7=Sun; monthly: 1..28',
  `run_hour` tinyint(4) NOT NULL DEFAULT 6 COMMENT 'Local hour in the scope timezone',
  `sections` text DEFAULT NULL COMMENT 'JSON list of section keys',
  `include_ai` tinyint(1) NOT NULL DEFAULT 0,
  `deliver` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Email recipients when ready (also needs the global switch)',
  `enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Off until an administrator enables it',
  `next_run_at` timestamp NULL DEFAULT NULL,
  `last_run_at` timestamp NULL DEFAULT NULL,
  `created_by` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_due` (`enabled`,`next_run_at`),
  KEY `idx_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `report_shares` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `report_id` bigint(20) NOT NULL,
  `token_hash` char(64) NOT NULL COMMENT 'sha256 of the token; the token itself is never stored',
  `expires_at` timestamp NOT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoked_by` varchar(64) DEFAULT NULL,
  `created_by` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_accessed_at` timestamp NULL DEFAULT NULL,
  `access_count` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token_hash`),
  KEY `idx_report` (`report_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `reports` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `job_id` bigint(20) DEFAULT NULL,
  `schedule_id` int(11) DEFAULT NULL,
  `report_type` enum('firewall','site','customer','fleet') NOT NULL,
  `scope_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL COMMENT 'Owning customer; NULL means fleet-wide (unrestricted staff only)',
  `title` varchar(255) NOT NULL,
  `timezone` varchar(64) NOT NULL,
  `period_start` int(10) unsigned NOT NULL COMMENT 'Unix seconds, inclusive',
  `period_end` int(10) unsigned NOT NULL COMMENT 'Unix seconds, exclusive',
  `sections` text NOT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `generated_by` varchar(64) DEFAULT NULL,
  `html_path` varchar(512) NOT NULL,
  `html_sha256` char(64) NOT NULL,
  `csv_path` varchar(512) DEFAULT NULL,
  `csv_sha256` char(64) DEFAULT NULL,
  `pdf_path` varchar(512) DEFAULT NULL,
  `pdf_sha256` char(64) DEFAULT NULL,
  `pdf_status` enum('ok','unavailable','failed','not_requested') NOT NULL DEFAULT 'not_requested',
  `pdf_error` varchar(255) DEFAULT NULL,
  `snapshot_json` longtext NOT NULL COMMENT 'Metric snapshot and provenance used to build the report',
  `snapshot_sha256` char(64) NOT NULL,
  `ai_status` enum('none','ok','failed','discarded') NOT NULL DEFAULT 'none',
  PRIMARY KEY (`id`),
  KEY `idx_customer` (`customer_id`,`generated_at`),
  KEY `idx_generated` (`generated_at`),
  KEY `idx_job` (`job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `request_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `tunnel_port` int(11) DEFAULT NULL,
  `tunnel_pid` int(11) DEFAULT NULL,
  `client_id` varchar(64) NOT NULL,
  `method` varchar(10) NOT NULL DEFAULT 'GET',
  `path` varchar(1024) NOT NULL,
  `headers` text DEFAULT NULL,
  `body` longtext DEFAULT NULL,
  `status` enum('pending','processing','completed','failed','timeout','cancelled') DEFAULT 'pending',
  `response_status` int(11) DEFAULT NULL,
  `response_headers` text DEFAULT NULL,
  `response_body` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `tunnel_started_at` datetime DEFAULT NULL,
  `last_heartbeat` datetime DEFAULT NULL,
  `connection_attempts` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_firewall_status` (`firewall_id`,`status`),
  KEY `idx_client_id` (`client_id`),
  CONSTRAINT `request_queue_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `scheduled_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `schedule` varchar(100) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'active',
  `enabled` tinyint(4) DEFAULT 1,
  `last_run` datetime DEFAULT NULL,
  `next_run` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `script_path` varchar(255) DEFAULT NULL,
  `expected_interval_minutes` int(11) DEFAULT NULL,
  `last_status` varchar(20) DEFAULT NULL,
  `last_duration_ms` int(11) DEFAULT NULL,
  `last_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `task_name` (`task_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `checksum` char(64) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `value` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `sites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `code` varchar(32) DEFAULT NULL,
  `timezone` varchar(64) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `maintenance_window_start` time DEFAULT NULL,
  `maintenance_window_end` time DEFAULT NULL,
  `maintenance_window_days` varchar(32) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_customer_site` (`customer_id`,`name`),
  KEY `idx_customer` (`customer_id`),
  CONSTRAINT `fk_sites_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Organisational grouping beneath a customer. Sites do not log in.';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `snyk_scan_results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `scan_type` enum('dependencies','code','full','container','iac','license') NOT NULL DEFAULT 'full',
  `status` enum('running','completed','failed') NOT NULL DEFAULT 'running',
  `total_vulnerabilities` int(11) DEFAULT 0,
  `critical_count` int(11) DEFAULT 0,
  `high_count` int(11) DEFAULT 0,
  `medium_count` int(11) DEFAULT 0,
  `low_count` int(11) DEFAULT 0,
  `duration_seconds` int(11) DEFAULT 0,
  `scan_output` longtext DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ssh_access_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `source_ip` varchar(45) NOT NULL,
  `ssh_port` int(11) DEFAULT 22,
  `tunnel_port` int(11) NOT NULL,
  `rule_label` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  `last_activity` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('active','expired','closed') DEFAULT 'active',
  `proxy_path` varchar(255) DEFAULT NULL,
  `closed_reason` varchar(255) DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL COMMENT 'MSP user who opened the session; NULL for rows predating 3.12.0',
  `created_by_username` varchar(64) DEFAULT NULL,
  `access_token` varchar(64) DEFAULT NULL COMMENT 'Unguessable per-session token, required by tunnel_proxy.php',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_created_by` (`created_by_user_id`),
  KEY `ssh_access_sessions_ibfk_1` (`firewall_id`),
  CONSTRAINT `ssh_access_sessions_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `ssh_tunnels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `session_id` varchar(64) NOT NULL,
  `proxy_port` int(11) NOT NULL,
  `tunnel_port` int(11) DEFAULT NULL,
  `status` enum('pending','establishing','active','failed','expired') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `established_at` timestamp NULL DEFAULT NULL,
  `last_activity` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `client_ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_id` (`session_id`),
  KEY `idx_session` (`session_id`),
  KEY `idx_firewall` (`firewall_id`),
  KEY `idx_status` (`status`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `ssh_tunnels_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `system_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `timestamp` timestamp NULL DEFAULT current_timestamp(),
  `level` enum('INFO','WARNING','ERROR','DEBUG') DEFAULT 'INFO',
  `category` varchar(50) NOT NULL,
  `message` text NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `firewall_id` int(11) DEFAULT NULL,
  `additional_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`additional_data`)),
  PRIMARY KEY (`id`),
  KEY `idx_timestamp` (`timestamp`),
  KEY `idx_level` (`level`),
  KEY `idx_category` (`category`),
  KEY `idx_firewall_id` (`firewall_id`),
  CONSTRAINT `system_logs_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `color` varchar(7) DEFAULT '#3b82f6',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `todo_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `todo_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `author` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_todo_id` (`todo_id`),
  CONSTRAINT `todo_comments_ibfk_1` FOREIGN KEY (`todo_id`) REFERENCES `todos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `todos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category` enum('feature','improvement','maintenance','documentation') DEFAULT 'feature',
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `status` enum('backlog','planned','in-progress','testing','completed','cancelled') DEFAULT 'backlog',
  `component` varchar(100) DEFAULT NULL,
  `assigned_to` varchar(100) DEFAULT NULL,
  `estimated_hours` int(11) DEFAULT NULL,
  `target_version` varchar(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority`),
  KEY `idx_component` (`component`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `update_campaign_targets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campaign_id` int(11) NOT NULL,
  `firewall_id` int(11) NOT NULL,
  `ring` enum('canary','pilot','production') NOT NULL,
  `status` enum('pending','holding','dispatched','succeeded','failed','skipped') NOT NULL DEFAULT 'pending',
  `hold_reason` varchar(255) DEFAULT NULL COMMENT 'Why a target is not being dispatched yet, e.g. HA partner in progress',
  `command_id` int(11) DEFAULT NULL,
  `version_before` varchar(64) DEFAULT NULL,
  `version_after` varchar(64) DEFAULT NULL,
  `dispatched_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `result` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_campaign_firewall` (`campaign_id`,`firewall_id`),
  KEY `idx_campaign_status` (`campaign_id`,`status`),
  KEY `idx_firewall` (`firewall_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `update_campaigns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` varchar(512) DEFAULT NULL,
  `target_version` varchar(64) DEFAULT NULL COMMENT 'Informational: what we expect to end up on',
  `operation` enum('check','install') NOT NULL DEFAULT 'install',
  `status` enum('draft','running','paused','completed','cancelled') NOT NULL DEFAULT 'draft',
  `current_ring` enum('canary','pilot','production') DEFAULT NULL,
  `auto_progress` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Advance to the next ring without a human approving it',
  `reboot_if_required` tinyint(1) NOT NULL DEFAULT 0,
  `respect_maintenance` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Only dispatch inside a maintenance window when one is defined',
  `ha_safe` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Never dispatch to both members of a CARP pair at once',
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_by_username` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `updater_checkins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `updater_version` varchar(50) DEFAULT NULL,
  `checkin_time` timestamp NULL DEFAULT current_timestamp(),
  `last_update_check` timestamp NULL DEFAULT NULL,
  `updates_available` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_firewall_time` (`firewall_id`,`checkin_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `updater_commands` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firewall_id` int(11) NOT NULL,
  `command_type` enum('AGENT_UPDATE','SYSTEM_UPDATE','COMMAND') NOT NULL,
  `command` text NOT NULL,
  `description` varchar(255) NOT NULL,
  `status` enum('pending','sent','success','failed') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `result` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_firewall_status` (`firewall_id`,`status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `updater_commands_ibfk_1` FOREIGN KEY (`firewall_id`) REFERENCES `firewalls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `user_customer_scopes` (
  `user_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`customer_id`),
  KEY `idx_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(128) DEFAULT NULL,
  `timezone` varchar(50) DEFAULT 'America/Chicago',
  `alert_levels` set('info','warning','critical') DEFAULT 'warning,critical',
  `twofa_secret` varchar(32) DEFAULT NULL,
  `role` enum('admin','technician','readonly') NOT NULL DEFAULT 'technician' COMMENT 'MSP staff role: admin (full), technician (operations), readonly (view only)',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `totp_secret` text DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `recovery_codes` text DEFAULT NULL,
  `last_login` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `mfa_enforced_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `session_version` int(11) NOT NULL DEFAULT 0 COMMENT 'Bumped to revoke every existing session for this account',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_role` (`role`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE OR REPLACE VIEW `v_firewall_wan_status` AS SELECT
 1 AS `firewall_id`,
  1 AS `hostname`,
  1 AS `wan_ip`,
  1 AS `firewall_status`,
  1 AS `wan_interfaces`,
  1 AS `wan_groups`,
  1 AS `interface_name`,
  1 AS `interface_status`,
  1 AS `interface_ip`,
  1 AS `media`,
  1 AS `rx_bytes`,
  1 AS `tx_bytes`,
  1 AS `rx_errors`,
  1 AS `tx_errors`,
  1 AS `last_updated` */;
SET character_set_client = @saved_cs_client;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `vpn_activity` (
  `firewall_id` int(11) NOT NULL,
  `vpn_type` varchar(16) NOT NULL,
  `name` varchar(128) NOT NULL,
  `last_rx` bigint(20) DEFAULT NULL,
  `last_tx` bigint(20) DEFAULT NULL,
  `first_seen_at` datetime NOT NULL,
  `last_change_at` datetime DEFAULT NULL COMMENT 'Counters last observed to increase; NULL if never seen to move',
  `observed_at` datetime NOT NULL,
  PRIMARY KEY (`firewall_id`,`vpn_type`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `vpn_config_cache` (
  `firewall_id` int(11) NOT NULL,
  `backup_id` int(11) DEFAULT NULL,
  `fingerprint` char(64) DEFAULT NULL COMMENT 'sha256 of the config file the slice was built from',
  `config_time` datetime DEFAULT NULL COMMENT 'When that backup was taken',
  `collected_at` datetime NOT NULL,
  `slice` mediumtext NOT NULL COMMENT 'JSON: vpn, interfaces, routes, gateways, nat, filter_rules; no secrets',
  PRIMARY KEY (`firewall_id`),
  KEY `idx_backup` (`backup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `vpn_diagnostic_runs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `run_group` char(32) NOT NULL COMMENT 'Legs of one coordinated check share this',
  `leg` varchar(16) NOT NULL DEFAULT 'source' COMMENT 'source | peer',
  `firewall_id` int(11) NOT NULL,
  `tunnel_key` varchar(191) DEFAULT NULL,
  `method` varchar(12) NOT NULL,
  `target` varchar(64) NOT NULL,
  `port` int(11) DEFAULT NULL,
  `source_address` varchar(64) DEFAULT NULL COMMENT 'Address the probe was bound to (-S / -s)',
  `source_interface` varchar(64) DEFAULT NULL,
  `source_reason` varchar(255) DEFAULT NULL,
  `command_id` int(11) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'queued' COMMENT 'queued | completed | failed | refused | expired',
  `outcome` varchar(16) DEFAULT NULL COMMENT 'reachable | unreachable | inconclusive',
  `summary` varchar(500) DEFAULT NULL,
  `result_excerpt` text DEFAULT NULL,
  `incident_id` bigint(20) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_group` (`run_group`),
  KEY `idx_fw_time` (`firewall_id`,`created_at`),
  KEY `idx_user_time` (`user_id`,`created_at`),
  KEY `idx_command` (`command_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `vpn_layout_prefs` (
  `user_id` int(11) NOT NULL,
  `view_key` varchar(32) NOT NULL DEFAULT 'topology',
  `prefs` text NOT NULL COMMENT 'JSON: node positions, filters, view mode',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`,`view_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE IF NOT EXISTS `vpn_peer_pairings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kind` enum('pair','unpair','classify') NOT NULL,
  `endpoint_a` varchar(191) NOT NULL COMMENT 'Endpoint key; for pair/unpair the lexically smaller of the two',
  `endpoint_b` varchar(191) NOT NULL DEFAULT '' COMMENT 'Other endpoint key; empty for classify',
  `classification` varchar(24) DEFAULT NULL COMMENT 'classify only: site_to_site | remote_access | external | unknown',
  `note` varchar(255) DEFAULT NULL,
  `created_by` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pairing` (`kind`,`endpoint_a`,`endpoint_b`),
  KEY `idx_a` (`endpoint_a`),
  KEY `idx_b` (`endpoint_b`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50001 DROP VIEW IF EXISTS `v_firewall_wan_status`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb3 */;
/*!50001 SET character_set_results     = utf8mb3 */;
/*!50001 SET collation_connection      = utf8mb3_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */

/*!50001 VIEW `v_firewall_wan_status` AS select `f`.`id` AS `firewall_id`,`f`.`hostname` AS `hostname`,`f`.`wan_ip` AS `wan_ip`,`f`.`status` AS `firewall_status`,`f`.`wan_interfaces` AS `wan_interfaces`,`f`.`wan_groups` AS `wan_groups`,`w`.`interface_name` AS `interface_name`,`w`.`status` AS `interface_status`,`w`.`ip_address` AS `interface_ip`,`w`.`media` AS `media`,`w`.`rx_bytes` AS `rx_bytes`,`w`.`tx_bytes` AS `tx_bytes`,`w`.`rx_errors` AS `rx_errors`,`w`.`tx_errors` AS `tx_errors`,`w`.`last_updated` AS `last_updated` from (`firewalls` `f` left join `firewall_wan_interfaces` `w` on(`f`.`id` = `w`.`firewall_id`)) where `f`.`status` <> 'deleted' order by `f`.`id`,`w`.`interface_name` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


-- -----------------------------------------------------------------------------
-- Reference data
-- -----------------------------------------------------------------------------
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

LOCK TABLES `approved_commands` WRITE;
/*!40000 ALTER TABLE `approved_commands` DISABLE KEYS */;
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (1,'/sbin/shutdown -r +1 %','Reboot with 1-minute delay','system','HIGH',1,300,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (2,'uptime','Show system uptime','system','LOW',0,10,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (3,'date','Show current system time','system','LOW',0,5,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (4,'whoami','Show current user','system','LOW',0,5,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (5,'id','Show user and group IDs','system','LOW',0,5,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (6,'uname -a','System information','system','LOW',0,10,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (7,'freebsd-version','FreeBSD version','system','LOW',0,10,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (8,'opnsense-version','OPNsense version','system','LOW',0,10,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (9,'df -h','Disk space usage','system','LOW',0,15,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (10,'ps aux | grep %','Process information','system','LOW',0,15,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (11,'ping -c 4 %','Network connectivity test','network','LOW',0,30,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (12,'netstat -rn','Routing table','network','MEDIUM',0,15,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (13,'ifconfig','Network interfaces','network','LOW',0,10,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (14,'nslookup %','DNS lookup','network','LOW',0,20,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (15,'pkg info | grep %','List packages','packages','LOW',0,30,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (16,'pkg update','Update package repository','packages','MEDIUM',1,120,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (17,'pkg upgrade -y','Upgrade packages','packages','HIGH',1,600,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (18,'opnsense-update -c','Check OPNsense updates','packages','LOW',0,60,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (19,'opnsense-update -bkf','OPNsense system update','packages','HIGH',1,1800,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (20,'service % status','Service status check','services','LOW',0,15,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (21,'service -e','List enabled services','services','LOW',0,15,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (22,'tail -% /var/log/%','View log files','logs','LOW',0,30,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (23,'grep % /var/log/%','Search log files','logs','LOW',0,45,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (24,'ls -la /var/log/','List log files','logs','LOW',0,10,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (25,'crontab -l','List cron jobs','agent','LOW',0,10,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (26,'ls -la /usr/local/bin/opnsense*','List agent files','agent','LOW',0,10,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (27,'chmod +x /usr/local/bin/%','Fix file permissions','agent','MEDIUM',0,10,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (28,'ls -la %','List directory contents','files','LOW',0,15,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (29,'stat %','File information','files','LOW',0,10,'2025-10-11 14:12:21');
INSERT IGNORE INTO `approved_commands` (`id`, `command_pattern`, `description`, `category`, `risk_level`, `requires_confirmation`, `timeout_seconds`, `created_at`) VALUES (30,'md5sum %','File checksum','files','LOW',0,30,'2025-10-11 14:12:21');
/*!40000 ALTER TABLE `approved_commands` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `features` WRITE;
/*!40000 ALTER TABLE `features` DISABLE KEYS */;
INSERT IGNORE INTO `features` (`id`, `name`, `category`, `status`, `version`, `description`, `requires_agent`, `api_enabled`, `multi_tenant`, `tech_details`, `created_at`, `updated_at`) VALUES (1,'Firewall Monitoring','Core Monitoring','production','2.0.0','Real-time monitoring of firewall status, health, and metrics',1,1,1,NULL,'2025-10-23 14:54:33','2025-10-23 14:54:33');
INSERT IGNORE INTO `features` (`id`, `name`, `category`, `status`, `version`, `description`, `requires_agent`, `api_enabled`, `multi_tenant`, `tech_details`, `created_at`, `updated_at`) VALUES (2,'Network Configuration Display','Core Monitoring','production','2.1.0','Display WAN/LAN configuration data with quality indicators',1,1,1,NULL,'2025-10-23 14:54:33','2025-10-23 14:54:33');
INSERT IGNORE INTO `features` (`id`, `name`, `category`, `status`, `version`, `description`, `requires_agent`, `api_enabled`, `multi_tenant`, `tech_details`, `created_at`, `updated_at`) VALUES (3,'SSH Tunnel System','Remote Access','production','2.1.0','On-demand SSH tunnels for secure firewall access',1,1,1,NULL,'2025-10-23 14:54:33','2025-10-23 14:54:33');
INSERT IGNORE INTO `features` (`id`, `name`, `category`, `status`, `version`, `description`, `requires_agent`, `api_enabled`, `multi_tenant`, `tech_details`, `created_at`, `updated_at`) VALUES (4,'Tunnel Proxy','Remote Access','production','2.1.0','HTTP reverse proxy for web UI access through tunnels',1,0,1,NULL,'2025-10-23 14:54:33','2025-10-23 14:54:33');
INSERT IGNORE INTO `features` (`id`, `name`, `category`, `status`, `version`, `description`, `requires_agent`, `api_enabled`, `multi_tenant`, `tech_details`, `created_at`, `updated_at`) VALUES (5,'AI Configuration Scanning','AI Features','production','2.2.0','AI-powered security analysis of firewall configurations',1,1,1,NULL,'2025-10-23 14:54:33','2025-10-23 14:54:33');
INSERT IGNORE INTO `features` (`id`, `name`, `category`, `status`, `version`, `description`, `requires_agent`, `api_enabled`, `multi_tenant`, `tech_details`, `created_at`, `updated_at`) VALUES (6,'AI Log Analysis','AI Features','production','2.2.0','AI-powered analysis of firewall logs for threat detection',1,1,1,NULL,'2025-10-23 14:54:33','2025-10-23 14:54:33');
INSERT IGNORE INTO `features` (`id`, `name`, `category`, `status`, `version`, `description`, `requires_agent`, `api_enabled`, `multi_tenant`, `tech_details`, `created_at`, `updated_at`) VALUES (7,'Automated Backups','Backup & Restore','production','2.0.0','Scheduled and manual configuration backups',1,1,1,NULL,'2025-10-23 14:54:33','2025-10-23 14:54:33');
INSERT IGNORE INTO `features` (`id`, `name`, `category`, `status`, `version`, `description`, `requires_agent`, `api_enabled`, `multi_tenant`, `tech_details`, `created_at`, `updated_at`) VALUES (8,'Command Execution','Management','production','2.0.0','Remote command queue and execution system',1,1,1,NULL,'2025-10-23 14:54:33','2025-10-23 14:54:33');
INSERT IGNORE INTO `features` (`id`, `name`, `category`, `status`, `version`, `description`, `requires_agent`, `api_enabled`, `multi_tenant`, `tech_details`, `created_at`, `updated_at`) VALUES (9,'Traffic Graphs','Monitoring','production','2.1.0','Real-time traffic visualization with Chart.js',1,1,1,NULL,'2025-10-23 14:54:33','2025-10-23 14:54:33');
INSERT IGNORE INTO `features` (`id`, `name`, `category`, `status`, `version`, `description`, `requires_agent`, `api_enabled`, `multi_tenant`, `tech_details`, `created_at`, `updated_at`) VALUES (10,'System Resource Graphs','Monitoring','production','2.1.0','CPU, Memory, Disk usage graphs',1,1,1,NULL,'2025-10-23 14:54:33','2025-10-23 14:54:33');
INSERT IGNORE INTO `features` (`id`, `name`, `category`, `status`, `version`, `description`, `requires_agent`, `api_enabled`, `multi_tenant`, `tech_details`, `created_at`, `updated_at`) VALUES (11,'Deployment Packages','Deployment','production','2.2.0','Automated package generation for customer deployments',0,1,0,NULL,'2025-10-23 14:54:33','2025-10-23 14:54:33');
INSERT IGNORE INTO `features` (`id`, `name`, `category`, `status`, `version`, `description`, `requires_agent`, `api_enabled`, `multi_tenant`, `tech_details`, `created_at`, `updated_at`) VALUES (12,'Licensing System','Deployment','production','2.2.0','Multi-tier licensing with firewall count enforcement',0,1,0,NULL,'2025-10-23 14:54:33','2025-10-23 14:54:33');
INSERT IGNORE INTO `features` (`id`, `name`, `category`, `status`, `version`, `description`, `requires_agent`, `api_enabled`, `multi_tenant`, `tech_details`, `created_at`, `updated_at`) VALUES (13,'Update Distribution','Deployment','production','2.2.0','Centralized update distribution and version management',0,1,0,NULL,'2025-10-23 14:54:33','2025-10-23 14:54:33');
INSERT IGNORE INTO `features` (`id`, `name`, `category`, `status`, `version`, `description`, `requires_agent`, `api_enabled`, `multi_tenant`, `tech_details`, `created_at`, `updated_at`) VALUES (14,'Network Diagnostic Tools','Tools','development','2.3.0','Ping, traceroute, DNS lookup from firewall',1,1,1,NULL,'2025-10-23 14:54:33','2025-10-23 14:54:33');
INSERT IGNORE INTO `features` (`id`, `name`, `category`, `status`, `version`, `description`, `requires_agent`, `api_enabled`, `multi_tenant`, `tech_details`, `created_at`, `updated_at`) VALUES (15,'WAN Bandwidth Testing','Monitoring','planned','2.3.0','Automated WAN speed testing and tracking',1,1,1,NULL,'2025-10-23 14:54:33','2025-10-23 14:54:33');
INSERT IGNORE INTO `features` (`id`, `name`, `category`, `status`, `version`, `description`, `requires_agent`, `api_enabled`, `multi_tenant`, `tech_details`, `created_at`, `updated_at`) VALUES (16,'DNS Traffic Analysis','Security','planned','2.4.0','DNS enforcement and internet usage analysis',1,1,1,NULL,'2025-10-23 14:54:33','2025-10-23 14:54:33');
/*!40000 ALTER TABLE `features` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

