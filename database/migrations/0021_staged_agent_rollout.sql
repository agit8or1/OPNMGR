-- Staged agent rollout: publishing an agent version must not deploy it.
--
-- Syncing AGENT_VERSION to production made every firewall fetch and install the
-- new agent on its next check-in, within about two minutes, with no step in
-- between. "Release the agent" and "change every firewall right now" were the
-- same action. On 2026-09-16 that happened twice in one session: 1.6.6 installed
-- itself on fw48 unprompted, and 1.6.7 did the same while a revert was being
-- typed - the download had already completed 45 seconds earlier.
--
-- agent_rollout_stage applies only to agent_rollout_version. When a newer
-- version is published the stored stage no longer matches it and the new version
-- is held, so the safe state is the one you get by forgetting, and promoting a
-- release has to name the version it promotes.
--
-- Idempotent: safe to re-run.

-- Default to 'held'. An installation upgrading into this migration gets the
-- gate closed, which is the point; the current version is promoted below so
-- that nothing changes for firewalls that are already up to date.
INSERT INTO settings (`name`, `value`)
VALUES ('agent_rollout_stage', 'held')
ON DUPLICATE KEY UPDATE `name` = `name`;

-- Deliberately empty: no version has been promoted yet, so every version is
-- held until somebody promotes it by name.
INSERT INTO settings (`name`, `value`)
VALUES ('agent_rollout_version', '')
ON DUPLICATE KEY UPDATE `name` = `name`;

-- Which firewalls take a pilot release. Nothing is a pilot by default, so
-- stage 'pilot' with no pilots chosen offers the update to nobody rather than
-- to everybody - the failure mode has to be conservative.
ALTER TABLE firewalls
    ADD COLUMN IF NOT EXISTS agent_rollout_pilot TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Receives agent releases at rollout stage pilot';
