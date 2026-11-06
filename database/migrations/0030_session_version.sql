-- ---------------------------------------------------------------------------
-- 0030_session_version
--
-- Server-side session invalidation. Authorization used to trust the role
-- cached in $_SESSION at login, and the periodic account check looked only at
-- is_active, so a demoted admin kept admin until logout and a password reset
-- left the old sessions working.
--
-- Every session records the version current at login. Bumping it (password
-- change, 2FA change, deactivation, admin reset) ends every session issued
-- before; the role itself is re-read from this table on every request.
-- ---------------------------------------------------------------------------
ALTER TABLE users ADD COLUMN IF NOT EXISTS session_version INT NOT NULL DEFAULT 0
    COMMENT 'Bumped to revoke every existing session for this account';
