-- ---------------------------------------------------------------------------
-- 0024_platform_detection
--
-- Mixed fleets: OPNsense, pfSense CE and pfSense Plus.
--
-- Until now every firewall was assumed to be OPNsense. The product was never
-- recorded, so nothing could refuse to send `configctl` to a box that does not
-- have it, or to restore an <opnsense> configuration onto a <pfsense> one.
--
-- Detection is evidence-based and recorded with its source and confidence. A
-- firewall whose platform is unknown is treated as read-only until the agent
-- has verified it (see inc/platform/platform.php).
--
-- platform_validations records which platform/version/capability combinations
-- have been exercised on a real device, so "implemented" and "live tested" are
-- never the same column.
-- ---------------------------------------------------------------------------

ALTER TABLE firewalls
    ADD COLUMN IF NOT EXISTS platform_family VARCHAR(16) NULL
        COMMENT 'opnsense | pfsense | NULL (unknown)',
    ADD COLUMN IF NOT EXISTS platform_edition VARCHAR(16) NULL
        COMMENT 'community | plus | business | unknown (pfSense); NULL for OPNsense',
    ADD COLUMN IF NOT EXISTS platform_os_version VARCHAR(64) NULL
        COMMENT 'Product version as the platform reports it, e.g. 26.7.8 or 2.8.1-RELEASE',
    ADD COLUMN IF NOT EXISTS platform_base_version VARCHAR(64) NULL
        COMMENT 'FreeBSD release. Recorded, never used on its own to identify the product',
    ADD COLUMN IF NOT EXISTS platform_config_version VARCHAR(16) NULL
        COMMENT 'config.xml <version> schema revision',
    ADD COLUMN IF NOT EXISTS platform_packages TEXT NULL
        COMMENT 'JSON list of relevant installed packages',
    ADD COLUMN IF NOT EXISTS platform_probe TEXT NULL
        COMMENT 'JSON tool/mechanism probe results from the agent',
    ADD COLUMN IF NOT EXISTS platform_detection_source VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS platform_detection_confidence VARCHAR(8) NULL
        COMMENT 'high | medium | low',
    ADD COLUMN IF NOT EXISTS platform_detection_conflict VARCHAR(255) NULL
        COMMENT 'Set when evidence sources disagree; forces read-only',
    ADD COLUMN IF NOT EXISTS platform_verified_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS agent_flavor VARCHAR(32) NULL
        COMMENT 'opnsense-plugin | pfsense-agent';

ALTER TABLE firewalls ADD INDEX IF NOT EXISTS idx_platform_family (platform_family);

CREATE TABLE IF NOT EXISTS platform_validations (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    platform_family VARCHAR(16)  NOT NULL,
    platform_edition VARCHAR(16) NOT NULL DEFAULT '',
    version_prefix  VARCHAR(32)  NOT NULL COMMENT 'Matched as a prefix of platform_os_version, e.g. 26.7',
    capability      VARCHAR(64)  NOT NULL,
    agent_version   VARCHAR(32)  NULL,
    validated_at    DATETIME     NOT NULL,
    evidence        VARCHAR(512) NOT NULL COMMENT 'What was run, on which device, with what result',
    recorded_by     VARCHAR(64)  NULL,
    UNIQUE KEY uniq_validation (platform_family, platform_edition, version_prefix, capability)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill: every firewall enrolled before 3.79 ran the OPNsense plugin agent,
-- whose version string comes from `opnsense-version` - a binary only OPNsense
-- ships. That is product-specific evidence, not a FreeBSD guess. Confidence is
-- medium until the 1.8 agent's explicit probe confirms it.
UPDATE firewalls
   SET platform_family = 'opnsense',
       platform_os_version = COALESCE(NULLIF(current_version, ''), NULLIF(version, '')),
       platform_detection_source = 'backfill:opnsense-plugin-agent',
       platform_detection_confidence = 'medium',
       agent_flavor = 'opnsense-plugin',
       platform_verified_at = last_checkin
 WHERE platform_family IS NULL
   AND agent_version LIKE '1.%'
   AND (COALESCE(current_version, version, '') REGEXP '^[0-9]{2}\\.[0-9]+');

-- Settings. Policy writes stay off until an administrator names lab devices.
INSERT IGNORE INTO settings (`name`, `value`) VALUES
    ('platform_verification_stale_hours', '24'),
    ('policy_apply_lab_firewall_ids', '');
