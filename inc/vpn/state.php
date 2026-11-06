<?php
/**
 * VPN topology: what state is an endpoint in, and how sure are we?
 *
 * States: up, degraded, idle, down, disabled, unknown, stale.
 *
 *   unknown   no telemetry has ever described this endpoint. Never guessed:
 *             a configuration snapshot says what SHOULD exist, not what is
 *             happening.
 *   stale     telemetry exists but is older than vpn_stale_minutes, so the
 *             last reading is reported as history, not as the present.
 *   disabled  switched off in configuration.
 *   idle      configured, no recent traffic, and that is expected for this
 *             kind of peer (WireGuard is connectionless; a roaming user whose
 *             phone is asleep has no handshake and nothing is wrong).
 *   degraded  working in part: a site link whose keepalive handshakes have
 *             stopped but whose counters still move, a tunnel the agent
 *             reports as connecting, or one that is up while a bounded probe
 *             through it fails.
 *   down      a fault: a site-to-site WireGuard peer that has never
 *             handshaken and has moved no traffic, or one silent past the
 *             idle window; an IPsec/OpenVPN tunnel the agent reports down.
 *
 * The decision depends on the peer's role, not on handshake age alone.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/util.php';

if (!function_exists('vpn_thresholds')) {
    function vpn_thresholds(array $override = []): array {
        $wgIdle = 900;
        if (function_exists('health_thresholds')) {
            try { $wgIdle = (int)(health_thresholds()['wg_idle'] ?? 900); } catch (Throwable $e) {}
        }
        return array_merge([
            'stale_s'    => max(60, (int)vpn_setting('vpn_stale_minutes', '30') * 60),
            'wg_up_s'    => 180,
            'wg_idle_s'  => $wgIdle,
            'probe_ttl_s' => 1800,
            'traffic_recent_s' => 300,
        ], $override);
    }
}

if (!function_exists('vpn_endpoint_state')) {
    /**
     * @param array $ep     endpoint from vpn_build_endpoints()
     * @param array $ctx    telemetry_at (string|int|null: when the agent last reported),
     *                      now (int), probe (array|null: {outcome, at}), thresholds
     * @return array{state:string, basis:string, reason:string, evidence:array,
     *               last_activity_at:?int, telemetry_age_s:?int, limitations:array}
     */
    function vpn_endpoint_state(array $ep, array $ctx = []): array {
        $now = $ctx['now'] ?? time();
        $th  = $ctx['thresholds'] ?? vpn_thresholds();
        $row = $ep['runtime'] ?? null;
        $role = $ep['role'] ?? 'unknown';
        $ev = []; $lim = [];

        $res = function (string $state, string $basis, string $reason, ?int $act = null, ?int $age = null) use (&$ev, &$lim) {
            return ['state' => $state, 'basis' => $basis, 'reason' => $reason, 'evidence' => $ev,
                    'last_activity_at' => $act, 'telemetry_age_s' => $age, 'limitations' => $lim];
        };

        if (empty($ep['enabled'])) {
            return $res('disabled', 'configured', 'Disabled in the configuration snapshot.');
        }
        if (!$row) {
            $lim[] = 'No telemetry describes this endpoint; configuration says only that it should exist.';
            return $res('unknown', 'configured', 'No runtime telemetry has been received for this tunnel.');
        }

        $tAt = vpn_ts($ctx['telemetry_at'] ?? ($row['updated_at'] ?? null));
        $age = $tAt !== null ? max(0, $now - $tAt) : null;
        $status = strtolower((string)($row['status'] ?? ''));
        $hs  = vpn_ts($row['latest_handshake'] ?? null);
        $rx  = isset($row['rx_bytes']) ? (int)$row['rx_bytes'] : null;
        $tx  = isset($row['tx_bytes']) ? (int)$row['tx_bytes'] : null;
        $act = $ep['activity'] ?? null;
        $chg = vpn_ts($act['last_change_at'] ?? null);
        $lastAct = max($hs ?? 0, $chg ?? 0) ?: null;

        $ev[] = 'Reported status: ' . ($status !== '' ? $status : 'none');
        if ($ep['protocol'] === 'wireguard') {
            $ev[] = 'Latest handshake: ' . ($hs ? vpn_age_text($now - $hs) . ' ago' : 'never');
            if ($rx !== null || $tx !== null) { $ev[] = sprintf('Counters: rx %d B, tx %d B', (int)$rx, (int)$tx); }
            if ($chg) { $ev[] = 'Counters last moved ' . vpn_age_text($now - $chg) . ' ago'; }
        }

        if ($tAt === null || $age > $th['stale_s']) {
            $lim[] = 'Telemetry is older than ' . round($th['stale_s'] / 60) . ' minutes; the values above are history.';
            return $res('stale', 'observed', sprintf('Last telemetry %s ago (last reported status: %s).',
                $age === null ? 'unknown time' : vpn_age_text($age), $status ?: 'none'), $lastAct, $age);
        }

        $probe = $ctx['probe'] ?? null;
        $probeFresh = $probe && vpn_ts($probe['at'] ?? null) !== null && ($now - vpn_ts($probe['at'])) <= $th['probe_ttl_s'];
        if ($probeFresh) {
            $ev[] = 'Bounded probe from the firewall: ' . ($probe['outcome'] ?? '?') . ' ' . vpn_age_text($now - vpn_ts($probe['at'])) . ' ago';
        }

        // ---- WireGuard: connectionless, so role decides what silence means.
        if ($ep['protocol'] === 'wireguard') {
            $hsAge = $hs ? max(0, $now - $hs) : null;
            $zero = ($rx ?? 0) + ($tx ?? 0) === 0;
            $moving = $chg !== null && ($now - $chg) <= $th['traffic_recent_s'];

            if ($hsAge !== null && $hsAge <= $th['wg_up_s']) {
                $state = 'up'; $reason = 'Recent handshake.'; $basis = 'observed';
            } elseif ($role === 'remote_access') {
                $lim[] = 'Remote-access peers handshake only when the user has traffic; handshake age is not treated as a fault.';
                if ($moving) {
                    $state = 'up'; $reason = 'Traffic counters moved recently.'; $basis = 'observed';
                } elseif ($probeFresh && ($probe['outcome'] ?? '') === 'reachable') {
                    $state = 'up'; $reason = 'A bounded probe reached the peer.'; $basis = 'observed';
                } elseif ($hs === null && $zero) {
                    $state = 'idle'; $reason = 'Remote-access peer has not connected (no handshake, no traffic); not expected to be connected.'; $basis = 'inferred';
                } else {
                    $state = 'idle'; $reason = 'Remote-access peer is quiet' . ($lastAct ? ' (last activity ' . vpn_age_text($now - $lastAct) . ' ago)' : '') . '; this is normal for a roaming user.'; $basis = 'inferred';
                }
            } else {
                $keepalive = (int)($ep['details']['keepalive'] ?? 0);
                if ($hs === null && $zero) {
                    $state = 'down'; $reason = 'Site-to-site peer has never handshaken and no traffic has been counted.'; $basis = 'observed';
                } elseif ($hs === null) {
                    $state = 'degraded'; $reason = 'Counters show traffic but no handshake is recorded.'; $basis = 'observed';
                } elseif ($hsAge <= $th['wg_idle_s']) {
                    if ($keepalive > 0 && $keepalive <= 60) {
                        $state = 'degraded'; $basis = 'inferred';
                        $reason = sprintf('Handshake is %s old but persistent keepalive (%ds) should refresh it every ~2 minutes.', vpn_age_text($hsAge), $keepalive);
                    } else {
                        $state = 'idle'; $basis = 'observed'; $reason = 'Quiet within the idle window; no keepalive is configured, so this is expected.';
                    }
                } elseif ($moving) {
                    $state = 'degraded'; $basis = 'observed'; $reason = 'Handshake is stale but counters are still moving.';
                } else {
                    $state = 'down'; $basis = 'observed';
                    $reason = 'Site-to-site peer silent for ' . vpn_age_text($hsAge) . ' (past the ' . round($th['wg_idle_s'] / 60) . ' minute idle window).';
                }
            }
        } else {
            // ---- IPsec / OpenVPN: connection-oriented; the agent's status is the evidence.
            $up   = in_array($status, ['up', 'connected', 'established', 'installed', 'online'], true);
            $conn = in_array($status, ['connecting', 'rekeying', 'init'], true);
            $dn   = in_array($status, ['down', 'disconnected', 'failed', 'offline', 'closed'], true);
            if ($up) { $state = 'up'; $basis = 'observed'; $reason = 'Agent reports the tunnel established.'; }
            elseif ($conn) { $state = 'degraded'; $basis = 'observed'; $reason = 'Agent reports the tunnel still connecting.'; }
            elseif ($dn && $role === 'remote_access') {
                $state = 'idle'; $basis = 'inferred'; $reason = 'No remote-access session is established; users connect on demand.';
                $lim[] = 'For a remote-access service this means no active session, not a failed service.';
            } elseif ($dn) { $state = 'down'; $basis = 'observed'; $reason = 'Agent reports the tunnel down.'; }
            elseif ($status === 'idle') { $state = 'idle'; $basis = 'observed'; $reason = 'Agent reports no activity.'; }
            else { $state = 'unknown'; $basis = 'observed'; $reason = 'Agent reported an unrecognised status "' . substr($status, 0, 32) . '".'; }
        }

        // A failed probe contradicts an apparently healthy site link.
        if ($probeFresh && ($probe['outcome'] ?? '') === 'unreachable' && in_array($state, ['up', 'idle'], true)
            && $role !== 'remote_access') {
            $state = 'degraded'; $basis = 'observed';
            $reason .= ' However, a bounded probe through the tunnel failed (the probe originates on the firewall, so it does not prove or disprove a client path).';
        }
        return $res($state, $basis, $reason, $lastAct, $age);
    }
}

if (!function_exists('vpn_combine_states')) {
    /**
     * One state for a tunnel from its two ends. Unknown is not a vote: if one
     * end is up and the other unmanaged, the tunnel is reported as seen from
     * the managed end and flagged partial by the caller.
     */
    function vpn_combine_states(string $a, ?string $b): string {
        if ($b === null || $b === 'unknown') { return $a; }
        if ($a === 'unknown') { return $b; }
        if ($a === $b) { return $a; }
        $pair = [$a, $b];
        if (in_array('disabled', $pair, true)) { return 'disabled'; }
        $healthy = ['up', 'idle'];
        if (in_array('down', $pair, true)) {
            return (in_array($a, $healthy, true) || in_array($b, $healthy, true)) ? 'degraded' : 'down';
        }
        if (in_array('degraded', $pair, true)) { return 'degraded'; }
        if (in_array('stale', $pair, true)) { return 'stale'; }
        return in_array('up', $pair, true) ? 'up' : 'idle';
    }
}

if (!function_exists('vpn_state_meta')) {
    /** Label and Bootstrap colour for each state, shared by PHP and the page. */
    function vpn_state_meta(): array {
        return [
            'up'       => ['label' => 'Up',       'class' => 'success'],
            'degraded' => ['label' => 'Degraded', 'class' => 'warning'],
            'idle'     => ['label' => 'Idle',     'class' => 'info'],
            'down'     => ['label' => 'Down',     'class' => 'danger'],
            'disabled' => ['label' => 'Disabled', 'class' => 'secondary'],
            'unknown'  => ['label' => 'Unknown',  'class' => 'secondary'],
            'stale'    => ['label' => 'Stale',    'class' => 'warning'],
        ];
    }
}
