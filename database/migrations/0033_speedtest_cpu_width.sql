-- ---------------------------------------------------------------------------
-- 0033_speedtest_cpu_width
--
-- iperf3 reports CPU utilisation summed across threads: a 32-stream test on a
-- 40-core firewall reported 625%. TINYINT UNSIGNED (max 255) rejected the row
-- and the measurement was lost. Values are stored as reported.
-- ---------------------------------------------------------------------------
ALTER TABLE bandwidth_tests
    MODIFY COLUMN local_cpu_percent SMALLINT UNSIGNED NULL
        COMMENT 'iperf3 host_total: summed across threads, can exceed 100',
    MODIFY COLUMN remote_cpu_percent SMALLINT UNSIGNED NULL
        COMMENT 'iperf3 remote_total: summed across threads, can exceed 100';
