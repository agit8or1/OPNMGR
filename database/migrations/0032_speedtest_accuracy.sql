-- ---------------------------------------------------------------------------
-- 0032_speedtest_accuracy
--
-- Bandwidth tests ran 4 streams for 8 s against a distant public iperf3
-- server, which plateaued near 1.6 Gbps on a 10 Gbps circuit; a failed upload
-- leg was stored as 0 Mbps and latency was hardcoded to 0. Agent 1.8.1 takes
-- servers/streams/duration from the manager and reports what it measured.
-- ---------------------------------------------------------------------------

ALTER TABLE firewalls
    ADD COLUMN IF NOT EXISTS speedtest_server VARCHAR(255) NULL
        COMMENT 'Comma-separated iperf3 host[:port] list for this firewall; NULL = global setting',
    ADD COLUMN IF NOT EXISTS speedtest_streams SMALLINT NULL
        COMMENT 'Parallel streams for this firewall; NULL = global setting',
    ADD COLUMN IF NOT EXISTS wan_link_down_mbps INT NULL
        COMMENT 'Provisioned downstream, for showing results as a share of the circuit',
    ADD COLUMN IF NOT EXISTS wan_link_up_mbps INT NULL
        COMMENT 'Provisioned upstream';

ALTER TABLE bandwidth_tests
    ADD COLUMN IF NOT EXISTS streams SMALLINT NULL,
    ADD COLUMN IF NOT EXISTS iperf_version VARCHAR(16) NULL,
    ADD COLUMN IF NOT EXISTS local_cpu_percent TINYINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS remote_cpu_percent TINYINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS command_id INT NULL;

INSERT IGNORE INTO settings (`name`, `value`) VALUES
    ('speedtest_iperf_servers', 'iperf.he.net,bouygues.iperf.fr,speedtest.wtnet.de,iperf.par2.as49434.net'),
    ('speedtest_streams', '16'),
    ('speedtest_duration', '10');

-- Past results: an upload of exactly 0 next to a real download was a failed
-- upload leg, and latency was never measured. Both become "not measured".
UPDATE bandwidth_tests SET upload_speed = NULL
 WHERE upload_speed = 0 AND download_speed > 0 AND test_status = 'completed';
UPDATE bandwidth_tests SET latency = NULL WHERE latency = 0;

-- 'running' rows queued by api/run_bandwidth_test.php were never completed.
UPDATE bandwidth_tests SET test_status = 'failed',
       error_message = COALESCE(error_message, 'Never completed (pre-3.79.2 rows were not linked to their result)')
 WHERE test_status = 'running' AND tested_at < NOW() - INTERVAL 1 DAY;
