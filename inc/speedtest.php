<?php
/**
 * OPNMGR WAN bandwidth tests (iperf3 run by the agent on the firewall).
 *
 * What a result means: throughput between the firewall and one iperf3 server,
 * over that path, at that moment. It is bounded by the server, the path and
 * the per-stream TCP window, not only by the circuit. Before 3.79.2 every test
 * ran 4 streams for 8 s against a distant public server, which plateaued near
 * 1.6 Gbps on a 10 Gbps link, and a failed upload leg was stored as 0 Mbps.
 *
 *   speedtest_config($fw)        settings + per-firewall overrides
 *   speedtest_command_for($fw)   the queued command text, version-gated
 *   speedtest_ingest(...)        store a result honestly (NULL = not measured)
 *
 * @since 3.79.2
 */

const SPEEDTEST_ARGS_MIN_AGENT = '1.8.1';
const SPEEDTEST_OOKLA_MIN_AGENT = '1.8.2';
const SPEEDTEST_OOKLA_TERMS_URLS = [
    'EULA'    => 'https://www.speedtest.net/about/eula',
    'Terms'   => 'https://www.speedtest.net/about/terms',
    'Privacy' => 'https://www.speedtest.net/about/privacy',
];

if (!function_exists('speedtest_ookla_terms')) {
    /** Recorded acceptance of Ookla's terms for this installation, or null. */
    function speedtest_ookla_terms(): ?array {
        $at = speedtest_setting('speedtest_ookla_terms_accepted_at', '');
        return $at === '' ? null : ['at' => $at, 'by' => speedtest_setting('speedtest_ookla_terms_accepted_by', '')];
    }
}

if (!function_exists('speedtest_ookla_accept_terms')) {
    /** Record (or withdraw) acceptance. Admin-only at the call site; audited. */
    function speedtest_ookla_accept_terms(bool $accept): void {
        $put = db()->prepare("INSERT INTO settings (`name`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
        $put->execute(['speedtest_ookla_terms_accepted_at', $accept ? date('Y-m-d H:i:s') : '']);
        $put->execute(['speedtest_ookla_terms_accepted_by', $accept ? (string)($_SESSION['username'] ?? 'cli') : '']);
        if (function_exists('audit_log')) {
            audit_log('speedtest.ookla.terms', [
                'message'  => $accept
                    ? "Accepted Ookla's EULA/Terms/Privacy Policy for this installation and confirmed the use is permitted"
                    : 'Withdrew acceptance of Ookla terms; Ookla tests disabled',
                'metadata' => ['urls' => SPEEDTEST_OOKLA_TERMS_URLS],
            ]);
        }
    }
}

if (!function_exists('speedtest_setting')) {
    function speedtest_setting(string $name, string $default): string {
        try {
            $s = db()->prepare('SELECT `value` FROM settings WHERE `name` = ?');
            $s->execute([$name]);
            $v = $s->fetchColumn();
            return ($v === false || $v === null || $v === '') ? $default : (string)$v;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('speedtest_valid_server')) {
    /** host or host:port; hostnames and IPv4 literals only. */
    function speedtest_valid_server(string $entry): bool {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}(:\d{1,5})?$/D', $entry) === 1;
    }
}

if (!function_exists('speedtest_config')) {
    /**
     * @return array{servers:string[], streams:int, duration:int, link_down:?int, link_up:?int}
     */
    function speedtest_config(array $fw): array {
        $servers = trim((string)($fw['speedtest_server'] ?? ''));
        if ($servers === '') {
            $servers = speedtest_setting('speedtest_iperf_servers',
                'iperf.he.net,bouygues.iperf.fr,speedtest.wtnet.de,iperf.par2.as49434.net');
        }
        $list = array_values(array_filter(array_map('trim', explode(',', $servers)), 'speedtest_valid_server'));
        $streams = (int)($fw['speedtest_streams'] ?? 0) ?: (int)speedtest_setting('speedtest_streams', '16');
        $duration = (int)speedtest_setting('speedtest_duration', '10');
        $engine = ($fw['speedtest_engine'] ?? '') === 'ookla' ? 'ookla' : 'iperf3';
        return [
            'engine'    => $engine,
            // Effective engine: Ookla only while terms are recorded.
            'engine_effective' => ($engine === 'ookla' && speedtest_ookla_terms() !== null) ? 'ookla' : 'iperf3',
            'ookla_server_id' => isset($fw['speedtest_ookla_server_id']) && (int)$fw['speedtest_ookla_server_id'] > 0
                ? (int)$fw['speedtest_ookla_server_id'] : null,
            'servers'   => array_slice($list, 0, 8),
            'streams'   => max(1, min(128, $streams)),
            'duration'  => max(5, min(60, $duration)),
            'link_down' => isset($fw['wan_link_down_mbps']) && (int)$fw['wan_link_down_mbps'] > 0 ? (int)$fw['wan_link_down_mbps'] : null,
            'link_up'   => isset($fw['wan_link_up_mbps']) && (int)$fw['wan_link_up_mbps'] > 0 ? (int)$fw['wan_link_up_mbps'] : null,
        ];
    }
}

if (!function_exists('speedtest_command_for')) {
    /**
     * Command text for a firewall. Agents before 1.8.1 only understand the bare
     * word (anything else would be run as a shell command and fail), so they
     * get it unchanged until they update.
     */
    function speedtest_command_for(array $fw): string {
        $v = preg_replace('/[^0-9.]/', '', (string)($fw['agent_version'] ?? ''));
        if ($v === '' || version_compare($v, SPEEDTEST_ARGS_MIN_AGENT, '<')
            || ($fw['agent_flavor'] ?? 'opnsense-plugin') === 'pfsense-agent') {
            return 'run_speedtest';
        }
        $c = speedtest_config($fw);
        // Ookla only for agents that have the engine; older ones keep iperf3.
        if ($c['engine_effective'] === 'ookla' && version_compare($v, SPEEDTEST_OOKLA_MIN_AGENT, '>=')) {
            return 'run_speedtest engine=ookla' . ($c['ookla_server_id'] ? ' ookla_server=' . $c['ookla_server_id'] : '');
        }
        $parts = ['run_speedtest'];
        if ($c['servers']) {
            $parts[] = 'servers=' . implode(',', $c['servers']);
        }
        $parts[] = 'streams=' . $c['streams'];
        $parts[] = 'duration=' . $c['duration'];
        return implode(' ', $parts);
    }
}

if (!function_exists('speedtest_is_command')) {
    function speedtest_is_command(?string $command): bool {
        $c = (string)$command;
        return $c === 'run_speedtest' || str_starts_with($c, 'run_speedtest ');
    }
}

if (!function_exists('speedtest_num')) {
    /** A positive number, or null. 0 is "did not measure", never a speed. */
    function speedtest_num($v, float $max): ?float {
        if (!is_numeric($v)) {
            return null;
        }
        $f = (float)$v;
        return ($f > 0 && $f <= $max) ? $f : null;
    }
}

if (!function_exists('speedtest_ingest')) {
    /**
     * Store a speedtest result from a completed command.
     *
     * Completes the 'running' row api/run_bandwidth_test.php created for this
     * command when there is one (it used to stay 'running' forever while a
     * second row was inserted), otherwise inserts. Scheduled runs are labelled
     * scheduled: the queueing row has no user.
     *
     * @return int|null bandwidth_tests id
     */
    function speedtest_ingest(int $firewallId, array $data, array $commandRow): ?int {
        $type = empty($commandRow['queued_by_user_id']) && stripos((string)($commandRow['description'] ?? ''), 'scheduled') !== false
            ? 'scheduled' : 'manual';
        $failed = isset($data['error']) || speedtest_num($data['download_mbps'] ?? null, 400000) === null;
        $err = $failed ? substr((string)($data['error'] ?? 'no download measurement'), 0, 500)
                       : (isset($data['upload_error']) && $data['upload_error'] !== null ? substr((string)$data['upload_error'], 0, 500) : null);

        $cols = [
            'test_type'          => $type,
            'test_status'        => $failed ? 'failed' : 'completed',
            'download_speed'     => $failed ? null : speedtest_num($data['download_mbps'] ?? null, 400000),
            'upload_speed'       => $failed ? null : speedtest_num($data['upload_mbps'] ?? null, 400000),
            'latency'            => speedtest_num($data['ping_ms'] ?? null, 10000),
            'test_server'        => isset($data['server']) ? substr((string)$data['server'], 0, 255) : null,
            'test_duration'      => isset($data['duration_s']) && is_numeric($data['duration_s']) ? max(0, min(3600, (int)$data['duration_s'])) : null,
            'streams'            => isset($data['streams']) && is_numeric($data['streams']) ? max(1, min(128, (int)$data['streams'])) : null,
            'iperf_version'      => isset($data['iperf_version']) ? substr(preg_replace('/[^0-9.]/', '', (string)$data['iperf_version']), 0, 16) : null,
            // Summed across threads by iperf3 (625 on a 32-stream run), so
            // only clamped to the column, never to 100.
            'local_cpu_percent'  => isset($data['local_cpu_percent']) && is_numeric($data['local_cpu_percent']) ? max(0, min(65535, (int)$data['local_cpu_percent'])) : null,
            'remote_cpu_percent' => isset($data['remote_cpu_percent']) && is_numeric($data['remote_cpu_percent']) ? max(0, min(65535, (int)$data['remote_cpu_percent'])) : null,
            'error_message'      => $err,
            'command_id'         => isset($commandRow['id']) ? (int)$commandRow['id'] : null,
            'engine'             => ($data['engine'] ?? 'iperf3') === 'ookla' ? 'ookla' : 'iperf3',
            'jitter_ms'          => speedtest_num($data['jitter_ms'] ?? null, 100000),
            'packet_loss'        => isset($data['packet_loss']) && is_numeric($data['packet_loss'])
                                    ? max(0.0, min(100.0, (float)$data['packet_loss'])) : null,
            // Only Ookla's own result pages.
            'result_url'         => (isset($data['result_url']) && preg_match('#^https://www\.speedtest\.net/result/[A-Za-z0-9/._-]{1,200}$#D', (string)$data['result_url']))
                                    ? (string)$data['result_url'] : null,
        ];

        // A 'running' row queued for this exact command (run_bandwidth_test.php
        // puts "(ID: n)" in the description).
        $runningId = null;
        if (preg_match('/\(ID: (\d+)\)/', (string)($commandRow['description'] ?? ''), $m)) {
            $s = db()->prepare("SELECT id FROM bandwidth_tests WHERE id = ? AND firewall_id = ? AND test_status = 'running'");
            $s->execute([(int)$m[1], $firewallId]);
            $runningId = $s->fetchColumn() ?: null;
        }

        if ($runningId) {
            $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($cols)));
            db()->prepare("UPDATE bandwidth_tests SET $set, tested_at = NOW() WHERE id = ?")
                ->execute([...array_values($cols), $runningId]);
            return (int)$runningId;
        }
        $names = implode(', ', array_map(fn($k) => "`$k`", array_keys($cols)));
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        db()->prepare("INSERT INTO bandwidth_tests (firewall_id, $names, tested_at) VALUES (?, $ph, NOW())")
            ->execute([$firewallId, ...array_values($cols)]);
        return (int)db()->lastInsertId();
    }
}
