-- Per-firewall and per-object alert policy.
--
-- Alerting was global and all-or-nothing below the firewall level:
-- `firewalls.alerts_enabled` silenced a firewall entirely, and there was nothing
-- between that and receiving every condition for every object. A lab tunnel that
-- is down by design, a circuit whose latency is what it is, a certificate nobody
-- renews - each produced an incident that could only be stopped by muting the
-- whole firewall, which then hid the conditions that did matter.
--
-- A policy row is an override. Resolution runs most-specific-first:
--
--   1. this alert type, this firewall, this object   (that one tunnel)
--   2. this alert type, this firewall, any object    (no cert alerts on fw3)
--   3. this alert type, any firewall, any object     (nobody wants job.stale)
--   4. no row: enabled, with the built-in threshold
--
-- Absence therefore means "alert, as before", so installing this changes no
-- behaviour until someone turns something off.
--
-- firewall_id 0 and object_key '' are the wildcards rather than NULL, because
-- MySQL does not treat NULLs as equal in a UNIQUE index and the upsert needs
-- one row per scope.

CREATE TABLE IF NOT EXISTS alert_policies (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    alert_type    VARCHAR(50)  NOT NULL,
    firewall_id   INT          NOT NULL DEFAULT 0,
    object_key    VARCHAR(191) NOT NULL DEFAULT '',
    enabled       TINYINT(1)   NOT NULL DEFAULT 1,
    threshold     VARCHAR(50)  NULL,
    note          VARCHAR(255) NULL,
    created_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_scope (alert_type, firewall_id, object_key),
    KEY idx_lookup (alert_type, firewall_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
