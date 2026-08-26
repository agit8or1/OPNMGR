-- ---------------------------------------------------------------------------
-- 0005_widen_secret_columns
--
-- Authenticated-encryption envelopes (enc:v1: + base64(nonce||ciphertext||tag))
-- are roughly 60 bytes longer than the plaintext and base64-expanded, so any
-- column that will hold an encrypted secret needs room for it.
--
-- ai_settings.api_key at VARCHAR(255) truncated a real provider key during the
-- 3.12.0 backfill; the backfill skips rather than truncates, but the column has
-- to grow before those values can be protected.
-- ---------------------------------------------------------------------------

ALTER TABLE ai_settings MODIFY COLUMN api_key TEXT NOT NULL;

ALTER TABLE users MODIFY COLUMN totp_secret TEXT NULL;

ALTER TABLE firewalls MODIFY COLUMN ssh_private_key TEXT NULL;
