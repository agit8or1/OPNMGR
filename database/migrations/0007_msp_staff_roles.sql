-- ---------------------------------------------------------------------------
-- 0007_msp_staff_roles
--
-- MSP staff roles (P1 #8).
--
-- OPNMGR is self-hosted software for an MSP's own staff. Customers are
-- organisational containers for grouping firewalls and do NOT log in, so there
-- is deliberately no customer role here.
--
-- The existing enum is ('admin','user'). 'user' is widened into three explicit
-- roles and existing 'user' rows become 'technician', which is the closest
-- match to what they could already do - a read-only default would silently
-- take away access people currently have.
-- ---------------------------------------------------------------------------

ALTER TABLE users
    MODIFY COLUMN role ENUM('admin','technician','readonly','user') NOT NULL DEFAULT 'technician'
    COMMENT 'MSP staff role. "user" is the pre-3.12 value, migrated below.';

UPDATE users SET role = 'technician' WHERE role = 'user';

-- Drop the legacy value now that nothing uses it.
ALTER TABLE users
    MODIFY COLUMN role ENUM('admin','technician','readonly') NOT NULL DEFAULT 'technician'
    COMMENT 'MSP staff role: admin (full), technician (operations), readonly (view only)';

-- Track who is exempt from / subject to the MFA requirement, and support
-- per-user deactivation without deleting the account (which would orphan the
-- audit trail).
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS mfa_enforced_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE users
    ADD INDEX IF NOT EXISTS idx_role (role),
    ADD INDEX IF NOT EXISTS idx_is_active (is_active);
