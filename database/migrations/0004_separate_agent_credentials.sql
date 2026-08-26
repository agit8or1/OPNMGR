-- ---------------------------------------------------------------------------
-- 0004_separate_agent_credentials
--
-- firewalls.api_key / api_secret are the OPNsense box's own REST API
-- credentials (used as key:secret basic auth by scripts/create_opnmgr_alias.php
-- and api/apply_secure_lockdown.php). Migration 0001 reused those columns for
-- the OPNManager agent's credentials, which conflates two unrelated secrets.
--
-- Move the agent credentials to their own columns and hand api_key/api_secret
-- back to their original meaning.
-- ---------------------------------------------------------------------------

ALTER TABLE firewalls
    ADD COLUMN IF NOT EXISTS agent_api_key TEXT NULL
        COMMENT 'OPNManager agent bearer credential (encrypted at rest)',
    ADD COLUMN IF NOT EXISTS agent_api_secret TEXT NULL
        COMMENT 'OPNManager agent HMAC signing secret (encrypted at rest)';

-- Carry across anything migration 0001 provisioned, without clobbering a value
-- that is already in the new column.
UPDATE firewalls
   SET agent_api_key = api_key
 WHERE api_key IS NOT NULL
   AND api_key LIKE 'enc:v1:%'
   AND (agent_api_key IS NULL OR agent_api_key = '');

UPDATE firewalls
   SET agent_api_secret = api_secret
 WHERE api_secret IS NOT NULL
   AND api_secret LIKE 'enc:v1:%'
   AND (agent_api_secret IS NULL OR agent_api_secret = '');

-- Only clear the OPNsense-API columns where they hold a value we put there
-- (recognisable by the encryption envelope). Any pre-existing plaintext
-- OPNsense API credential is left untouched.
UPDATE firewalls SET api_key    = NULL WHERE api_key    LIKE 'enc:v1:%';
UPDATE firewalls SET api_secret = NULL WHERE api_secret LIKE 'enc:v1:%';
