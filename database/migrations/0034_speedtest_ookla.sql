-- ---------------------------------------------------------------------------
-- 0034_speedtest_ookla
--
-- Optional Ookla Speedtest CLI engine. Off by default and per firewall.
-- Ookla licenses the CLI for personal, non-commercial use; an administrator
-- must record acceptance of Ookla's terms (confirming the use is permitted or
-- covered by an agreement with Ookla) before any firewall can use it.
-- ---------------------------------------------------------------------------
ALTER TABLE firewalls
    ADD COLUMN IF NOT EXISTS speedtest_engine VARCHAR(16) NULL
        COMMENT 'iperf3 (default, NULL) or ookla',
    ADD COLUMN IF NOT EXISTS speedtest_ookla_server_id INT NULL
        COMMENT 'Ookla server id; NULL lets the CLI pick the nearest';

ALTER TABLE bandwidth_tests
    ADD COLUMN IF NOT EXISTS engine VARCHAR(16) NULL,
    ADD COLUMN IF NOT EXISTS jitter_ms DECIMAL(8,2) NULL,
    ADD COLUMN IF NOT EXISTS packet_loss DECIMAL(6,2) NULL,
    ADD COLUMN IF NOT EXISTS result_url VARCHAR(255) NULL;

INSERT IGNORE INTO settings (`name`, `value`) VALUES
    ('speedtest_ookla_terms_accepted_at', ''),
    ('speedtest_ookla_terms_accepted_by', '');
