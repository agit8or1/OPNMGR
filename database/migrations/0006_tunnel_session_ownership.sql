-- ---------------------------------------------------------------------------
-- 0006_tunnel_session_ownership
--
-- tunnel_proxy.php authenticated callers purely with ssh_access_sessions.id,
-- described in its own comments as "unguessable". It is a plain AUTO_INCREMENT
-- integer (currently in the low hundreds), so while any session was active it
-- could be reached by enumeration, giving an anonymous caller a proxied path
-- into a managed firewall's web UI.
--
-- Record who opened a session so the proxy can check ownership, and give each
-- session a real bearer token for callers that cannot carry a login.
-- ---------------------------------------------------------------------------

ALTER TABLE ssh_access_sessions
    ADD COLUMN IF NOT EXISTS created_by_user_id INT(11) NULL
        COMMENT 'MSP user who opened the session; NULL for rows predating 3.12.0',
    ADD COLUMN IF NOT EXISTS created_by_username VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS access_token VARCHAR(64) NULL
        COMMENT 'Unguessable per-session token, required by tunnel_proxy.php';

ALTER TABLE ssh_access_sessions
    ADD INDEX IF NOT EXISTS idx_created_by (created_by_user_id);

-- Existing sessions are all closed; give them a token anyway so no row can be
-- reached with an empty one.
UPDATE ssh_access_sessions
   SET access_token = SHA2(CONCAT(id, '-', UUID(), '-', RAND()), 256)
 WHERE access_token IS NULL OR access_token = '';
