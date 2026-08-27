<?php
/**
 * OPNMGR Firewall Health
 *
 * Ingests and queries the OPNsense-specific health an agent reports:
 * gateways, VPN tunnels, CARP/HA state, services and certificates.
 *
 * Ingestion rules that matter:
 *
 *  - Every value is validated and clamped before it is stored. An agent is a
 *    remote machine reporting into the management server, so its payload is
 *    treated as untrusted input even though the agent authenticated.
 *  - Only objects the agent actually reported are kept. A service that is not
 *    installed on a firewall does not appear for it, rather than showing as
 *    "stopped" and generating a permanent false alarm.
 *  - Status transitions are recorded before the current-state row is replaced,
 *    which is what makes flapping visible.
 *  - Certificates are metadata only. Nothing here accepts or stores key
 *    material, and the agent does not send it.
 *
 * @since 3.13.0
 */

require_once __DIR__ . '/audit.php';

if (!function_exists('health_clamp_float')) {
    /**
     * Coerce a reported number into a sane range, or null.
     *
     * Accepts unit-suffixed strings such as "12.4 ms" and "0.0 %". OPNsense
     * reports gateway delay and loss that way, and while the bundled collector
     * strips the units, an older or third-party agent may not - and silently
     * storing NULL for every latency reading is a worse failure than parsing
     * leniently here.
     */
    function health_clamp_float($value, float $min, float $max): ?float {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        if (!is_numeric($value)) {
            if (!is_string($value) || !preg_match('/-?\d+(\.\d+)?/', $value, $m)) {
                return null;
            }
            $value = $m[0];
        }

        $f = (float)$value;
        if (is_nan($f) || is_infinite($f)) {
            return null;
        }
        return max($min, min($max, $f));
    }
}

if (!function_exists('health_clean_string')) {
    /**
     * Trim and bound a reported string.
     */
    function health_clean_string($value, int $max = 255): ?string {
        if ($value === null || !is_scalar($value)) {
            return null;
        }
        $s = trim((string)$value);
        if ($s === '') {
            return null;
        }
        // Strip control characters so a hostile value cannot corrupt log output.
        $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s);
        return mb_substr($s, 0, $max);
    }
}

if (!function_exists('health_parse_timestamp')) {
    /**
     * Accept a unix timestamp or a parseable date, reject anything absurd.
     */
    function health_parse_timestamp($value): ?string {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        if (is_numeric($value)) {
            $ts = (int)$value;
        } else {
            $ts = strtotime((string)$value);
            if ($ts === false) {
                return null;
            }
        }
        // Ignore values outside a plausible window; a firewall with a broken
        // clock should not write a year-2106 handshake into the database.
        if ($ts < 946684800 || $ts > time() + 86400 * 365) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }
}

// ---------------------------------------------------------------------------
// Ingestion
// ---------------------------------------------------------------------------

if (!function_exists('health_ingest')) {
    /**
     * Store a health payload reported by an authenticated agent.
     *
     * Each section is optional; an agent that only knows how to report some of
     * them updates only those. A section present but empty means "none of these
     * exist here", which is different from absent.
     *
     * @param int   $firewallId Authenticated firewall
     * @param array $health     'gateways', 'vpn', 'carp', 'services', 'certificates'
     * @return array<string,int> Rows stored per section
     */
    function health_ingest(int $firewallId, array $health): array {
        $stored = [];

        if (array_key_exists('gateways', $health) && is_array($health['gateways'])) {
            $stored['gateways'] = health_ingest_gateways($firewallId, $health['gateways']);
        }
        if (array_key_exists('vpn', $health) && is_array($health['vpn'])) {
            $stored['vpn'] = health_ingest_vpn($firewallId, $health['vpn']);
        }
        if (array_key_exists('carp', $health) && is_array($health['carp'])) {
            $stored['carp'] = health_ingest_carp($firewallId, $health['carp']);
        }
        if (array_key_exists('services', $health) && is_array($health['services'])) {
            $stored['services'] = health_ingest_services($firewallId, $health['services']);
        }
        if (array_key_exists('certificates', $health) && is_array($health['certificates'])) {
            $stored['certificates'] = health_ingest_certificates($firewallId, $health['certificates']);
        }

        return $stored;
    }
}

if (!function_exists('health_ingest_gateways')) {
    /**
     * Replace a firewall's gateway state, recording transitions.
     */
    function health_ingest_gateways(int $firewallId, array $gateways): int {
        $existing = [];
        $stmt = db()->prepare('SELECT name, status FROM firewall_gateways WHERE firewall_id = ?');
        $stmt->execute([$firewallId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing[$row['name']] = $row['status'];
        }

        $seen  = [];
        $count = 0;

        foreach ($gateways as $gw) {
            if (!is_array($gw)) {
                continue;
            }
            $name = health_clean_string($gw['name'] ?? null, 64);
            if ($name === null) {
                continue;
            }

            $status  = strtolower((string)(health_clean_string($gw['status'] ?? null, 32) ?? 'unknown'));
            $latency = health_clamp_float($gw['latency_ms'] ?? $gw['delay'] ?? null, 0, 100000);
            $loss    = health_clamp_float($gw['loss_percent'] ?? $gw['loss'] ?? null, 0, 100);
            $stddev  = health_clamp_float($gw['stddev_ms'] ?? $gw['stddev'] ?? null, 0, 100000);

            // Record the transition before overwriting the current row.
            if (array_key_exists($name, $existing) && $existing[$name] !== $status) {
                db()->prepare(
                    'INSERT INTO firewall_gateway_events
                        (firewall_id, gateway_name, from_status, to_status, latency_ms, loss_percent)
                     VALUES (?,?,?,?,?,?)'
                )->execute([$firewallId, $name, $existing[$name], $status, $latency, $loss]);
            }

            db()->prepare(
                'INSERT INTO firewall_gateways
                    (firewall_id, name, interface, address, monitor, status,
                     latency_ms, stddev_ms, loss_percent, is_default, gateway_group, priority)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    interface = VALUES(interface), address = VALUES(address),
                    monitor = VALUES(monitor), status = VALUES(status),
                    latency_ms = VALUES(latency_ms), stddev_ms = VALUES(stddev_ms),
                    loss_percent = VALUES(loss_percent), is_default = VALUES(is_default),
                    gateway_group = VALUES(gateway_group), priority = VALUES(priority)'
            )->execute([
                $firewallId, $name,
                health_clean_string($gw['interface'] ?? null, 64),
                health_clean_string($gw['address'] ?? null, 45),
                health_clean_string($gw['monitor'] ?? null, 45),
                $status, $latency, $stddev, $loss,
                !empty($gw['is_default']) ? 1 : 0,
                health_clean_string($gw['gateway_group'] ?? null, 64),
                isset($gw['priority']) && is_numeric($gw['priority']) ? (int)$gw['priority'] : null,
            ]);

            $seen[] = $name;
            $count++;
        }

        health_prune($firewallId, 'firewall_gateways', 'name', $seen);
        return $count;
    }
}

if (!function_exists('health_ingest_vpn')) {
    /**
     * Replace a firewall's VPN tunnel state, recording transitions.
     */
    function health_ingest_vpn(int $firewallId, array $tunnels): int {
        $existing = [];
        $stmt = db()->prepare('SELECT vpn_type, name, status FROM firewall_vpn_tunnels WHERE firewall_id = ?');
        $stmt->execute([$firewallId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing[$row['vpn_type'] . '|' . $row['name']] = $row['status'];
        }

        $seen  = [];
        $count = 0;

        foreach ($tunnels as $t) {
            if (!is_array($t)) {
                continue;
            }
            $type = strtolower((string)($t['type'] ?? $t['vpn_type'] ?? ''));
            if (!in_array($type, ['wireguard', 'openvpn', 'ipsec'], true)) {
                continue;
            }
            $name = health_clean_string($t['name'] ?? null, 128);
            if ($name === null) {
                continue;
            }

            $status = strtolower((string)(health_clean_string($t['status'] ?? null, 32) ?? 'unknown'));
            $key    = $type . '|' . $name;

            if (array_key_exists($key, $existing) && $existing[$key] !== $status) {
                db()->prepare(
                    'INSERT INTO firewall_vpn_events (firewall_id, vpn_type, name, from_status, to_status)
                     VALUES (?,?,?,?,?)'
                )->execute([$firewallId, $type, $name, $existing[$key], $status]);
            }

            db()->prepare(
                'INSERT INTO firewall_vpn_tunnels
                    (firewall_id, vpn_type, name, peer, endpoint, status, enabled,
                     latest_handshake, connected_since, rx_bytes, tx_bytes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    peer = VALUES(peer), endpoint = VALUES(endpoint), status = VALUES(status),
                    enabled = VALUES(enabled), latest_handshake = VALUES(latest_handshake),
                    connected_since = VALUES(connected_since),
                    rx_bytes = VALUES(rx_bytes), tx_bytes = VALUES(tx_bytes)'
            )->execute([
                $firewallId, $type, $name,
                health_clean_string($t['peer'] ?? null, 255),
                health_clean_string($t['endpoint'] ?? null, 255),
                $status,
                array_key_exists('enabled', $t) ? (!empty($t['enabled']) ? 1 : 0) : 1,
                health_parse_timestamp($t['latest_handshake'] ?? null),
                health_parse_timestamp($t['connected_since'] ?? null),
                isset($t['rx_bytes']) && is_numeric($t['rx_bytes']) ? max(0, (int)$t['rx_bytes']) : null,
                isset($t['tx_bytes']) && is_numeric($t['tx_bytes']) ? max(0, (int)$t['tx_bytes']) : null,
            ]);

            $seen[] = $key;
            $count++;
        }

        // Prune on the composite key.
        try {
            $rows = db()->prepare('SELECT id, vpn_type, name FROM firewall_vpn_tunnels WHERE firewall_id = ?');
            $rows->execute([$firewallId]);
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!in_array($row['vpn_type'] . '|' . $row['name'], $seen, true)) {
                    db()->prepare('DELETE FROM firewall_vpn_tunnels WHERE id = ?')->execute([$row['id']]);
                }
            }
        } catch (Throwable $e) {
            error_log('OPNMGR: VPN prune failed: ' . $e->getMessage());
        }

        return $count;
    }
}

if (!function_exists('health_ingest_carp')) {
    /**
     * Store CARP virtual-IP state and roll it up to a node-level state.
     */
    function health_ingest_carp(int $firewallId, array $carp): int {
        // Allow either a list of VHIDs or {enabled, vhids:[...]}
        $vhids  = $carp['vhids'] ?? $carp;
        $seen   = [];
        $count  = 0;
        $states = [];

        if (is_array($vhids)) {
            foreach ($vhids as $v) {
                if (!is_array($v)) {
                    continue;
                }
                $vhid = health_clean_string($v['vhid'] ?? null, 16);
                if ($vhid === null) {
                    continue;
                }
                $state = strtoupper((string)(health_clean_string($v['state'] ?? null, 16) ?? 'INIT'));
                if (!in_array($state, ['MASTER', 'BACKUP', 'INIT'], true)) {
                    $state = 'INIT';
                }

                db()->prepare(
                    'INSERT INTO firewall_carp (firewall_id, vhid, interface, address, state, advskew, advbase)
                     VALUES (?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                        interface = VALUES(interface), address = VALUES(address), state = VALUES(state),
                        advskew = VALUES(advskew), advbase = VALUES(advbase)'
                )->execute([
                    $firewallId, $vhid,
                    health_clean_string($v['interface'] ?? null, 64),
                    health_clean_string($v['address'] ?? null, 45),
                    $state,
                    isset($v['advskew']) && is_numeric($v['advskew']) ? (int)$v['advskew'] : null,
                    isset($v['advbase']) && is_numeric($v['advbase']) ? (int)$v['advbase'] : null,
                ]);

                $seen[]   = $vhid;
                $states[] = $state;
                $count++;
            }
        }

        health_prune($firewallId, 'firewall_carp', 'vhid', $seen);

        // A node is MASTER if it holds any MASTER VHID.
        $nodeState = null;
        if ($states) {
            $nodeState = in_array('MASTER', $states, true) ? 'MASTER'
                       : (in_array('BACKUP', $states, true) ? 'BACKUP' : 'INIT');
        }

        db()->prepare(
            'UPDATE firewalls
                SET carp_enabled = ?, carp_state = ?, carp_peer_host = ?, carp_sync_status = ?
              WHERE id = ?'
        )->execute([
            $count > 0 ? 1 : 0,
            $nodeState,
            health_clean_string($carp['peer_host'] ?? null, 255),
            health_clean_string($carp['sync_status'] ?? null, 32),
            $firewallId,
        ]);

        return $count;
    }
}

if (!function_exists('health_ingest_services')) {
    /**
     * Replace the service list for a firewall.
     *
     * Only services the agent reported are kept, so a firewall that does not
     * run OpenVPN simply has no OpenVPN row rather than a permanently "stopped"
     * one.
     */
    function health_ingest_services(int $firewallId, array $services): int {
        $seen  = [];
        $count = 0;

        foreach ($services as $svc) {
            if (!is_array($svc)) {
                continue;
            }
            $name = health_clean_string($svc['name'] ?? null, 64);
            if ($name === null) {
                continue;
            }

            db()->prepare(
                'INSERT INTO firewall_services (firewall_id, name, description, running, enabled)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    description = VALUES(description), running = VALUES(running), enabled = VALUES(enabled)'
            )->execute([
                $firewallId, $name,
                health_clean_string($svc['description'] ?? null, 128),
                !empty($svc['running']) ? 1 : 0,
                array_key_exists('enabled', $svc) ? (!empty($svc['enabled']) ? 1 : 0) : 1,
            ]);

            $seen[] = $name;
            $count++;
        }

        health_prune($firewallId, 'firewall_services', 'name', $seen);
        return $count;
    }
}

if (!function_exists('health_ingest_certificates')) {
    /**
     * Store certificate metadata.
     *
     * days_remaining is computed here from not_after rather than trusted from
     * the agent, so a firewall with a skewed clock cannot hide an expiry.
     */
    function health_ingest_certificates(int $firewallId, array $certs): int {
        $seen  = [];
        $count = 0;

        foreach ($certs as $c) {
            if (!is_array($c)) {
                continue;
            }
            $refid = health_clean_string($c['refid'] ?? $c['id'] ?? null, 64);
            if ($refid === null) {
                continue;
            }

            $notAfter  = health_parse_timestamp($c['not_after'] ?? $c['valid_to'] ?? null);
            $notBefore = health_parse_timestamp($c['not_before'] ?? $c['valid_from'] ?? null);

            $daysRemaining = null;
            if ($notAfter !== null) {
                $daysRemaining = (int)floor((strtotime($notAfter) - time()) / 86400);
            }

            db()->prepare(
                'INSERT INTO firewall_certificates
                    (firewall_id, refid, name, issuer, subject, cert_type,
                     not_before, not_after, days_remaining, in_use)
                 VALUES (?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name), issuer = VALUES(issuer), subject = VALUES(subject),
                    cert_type = VALUES(cert_type), not_before = VALUES(not_before),
                    not_after = VALUES(not_after), days_remaining = VALUES(days_remaining),
                    in_use = VALUES(in_use)'
            )->execute([
                $firewallId, $refid,
                health_clean_string($c['name'] ?? $c['descr'] ?? null, 255),
                health_clean_string($c['issuer'] ?? null, 255),
                health_clean_string($c['subject'] ?? null, 255),
                health_clean_string($c['type'] ?? null, 32),
                $notBefore, $notAfter, $daysRemaining,
                health_clean_string($c['in_use'] ?? null, 255),
            ]);

            $seen[] = $refid;
            $count++;
        }

        health_prune($firewallId, 'firewall_certificates', 'refid', $seen);
        return $count;
    }
}

if (!function_exists('health_prune')) {
    /**
     * Remove rows for objects the agent no longer reports.
     *
     * @param string[] $seen Keys present in this report
     */
    function health_prune(int $firewallId, string $table, string $keyColumn, array $seen): void {
        try {
            if (!$seen) {
                db()->prepare("DELETE FROM `{$table}` WHERE firewall_id = ?")->execute([$firewallId]);
                return;
            }
            $ph = implode(',', array_fill(0, count($seen), '?'));
            $sql = "DELETE FROM `{$table}` WHERE firewall_id = ? AND `{$keyColumn}` NOT IN ({$ph})";
            db()->prepare($sql)->execute(array_merge([$firewallId], $seen));
        } catch (Throwable $e) {
            error_log("OPNMGR: health_prune({$table}) failed: " . $e->getMessage());
        }
    }
}

// ---------------------------------------------------------------------------
// Queries
// ---------------------------------------------------------------------------

if (!function_exists('health_thresholds')) {
    /**
     * Configurable warning thresholds.
     *
     * @return array{cert_critical:int, cert_high:int, cert_medium:int,
     *               gw_loss:float, gw_latency:float}
     */
    function health_thresholds(): array {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $defaults = [
            'cert_warn_days_critical'     => 7,
            'cert_warn_days_high'         => 14,
            'cert_warn_days_medium'       => 30,
            'health_gateway_loss_warn'    => 5,
            'health_gateway_latency_warn' => 150,
        ];

        try {
            $rows = db()->query('SELECT `name`,`value` FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Throwable $e) {
            $rows = [];
        }

        foreach ($defaults as $k => $v) {
            if (isset($rows[$k]) && is_numeric($rows[$k])) {
                $defaults[$k] = $rows[$k] + 0;
            }
        }

        $cache = [
            'cert_critical' => (int)$defaults['cert_warn_days_critical'],
            'cert_high'     => (int)$defaults['cert_warn_days_high'],
            'cert_medium'   => (int)$defaults['cert_warn_days_medium'],
            'gw_loss'       => (float)$defaults['health_gateway_loss_warn'],
            'gw_latency'    => (float)$defaults['health_gateway_latency_warn'],
        ];
        return $cache;
    }
}

if (!function_exists('health_for_firewall')) {
    /**
     * Everything known about one firewall's health.
     */
    function health_for_firewall(int $firewallId): array {
        $out = ['gateways' => [], 'vpn' => [], 'carp' => [], 'services' => [], 'certificates' => []];

        try {
            $q = function (string $sql) use ($firewallId) {
                $stmt = db()->prepare($sql);
                $stmt->execute([$firewallId]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            };

            $out['gateways']     = $q('SELECT * FROM firewall_gateways WHERE firewall_id = ? ORDER BY is_default DESC, name');
            $out['vpn']          = $q('SELECT * FROM firewall_vpn_tunnels WHERE firewall_id = ? ORDER BY vpn_type, name');
            $out['carp']         = $q('SELECT * FROM firewall_carp WHERE firewall_id = ? ORDER BY vhid');
            $out['services']     = $q('SELECT * FROM firewall_services WHERE firewall_id = ? ORDER BY name');
            $out['certificates'] = $q('SELECT * FROM firewall_certificates WHERE firewall_id = ? ORDER BY days_remaining IS NULL, days_remaining');
        } catch (Throwable $e) {
            error_log('OPNMGR: health_for_firewall failed: ' . $e->getMessage());
        }

        return $out;
    }
}

if (!function_exists('health_gateway_severity')) {
    /**
     * Severity for a gateway row: ok, warning or critical.
     */
    function health_gateway_severity(array $gw): string {
        $t = health_thresholds();
        $status = strtolower((string)($gw['status'] ?? ''));

        if (in_array($status, ['down', 'force_down'], true)) {
            return 'critical';
        }
        if ($gw['loss_percent'] !== null && (float)$gw['loss_percent'] >= $t['gw_loss']) {
            return 'warning';
        }
        if ($gw['latency_ms'] !== null && (float)$gw['latency_ms'] >= $t['gw_latency']) {
            return 'warning';
        }
        if (in_array($status, ['loss', 'delay', 'degraded'], true)) {
            return 'warning';
        }
        return 'ok';
    }
}

if (!function_exists('health_cert_severity')) {
    /**
     * Severity for a certificate based on days remaining.
     */
    function health_cert_severity(?int $daysRemaining): string {
        if ($daysRemaining === null) {
            return 'unknown';
        }
        $t = health_thresholds();
        if ($daysRemaining < 0)                  return 'expired';
        if ($daysRemaining <= $t['cert_critical']) return 'critical';
        if ($daysRemaining <= $t['cert_high'])     return 'high';
        if ($daysRemaining <= $t['cert_medium'])   return 'medium';
        return 'ok';
    }
}

if (!function_exists('health_fleet_summary')) {
    /**
     * Fleet-wide health roll-up for the dashboard and health page.
     */
    function health_fleet_summary(): array {
        $t = health_thresholds();
        $summary = [
            'gateways_down'   => 0,
            'gateways_warn'   => 0,
            'vpn_down'        => 0,
            'services_stopped'=> 0,
            'certs_expired'   => 0,
            'certs_expiring'  => 0,
            'carp_init'       => 0,
        ];

        try {
            $summary['gateways_down'] = (int)db()->query(
                "SELECT COUNT(*) FROM firewall_gateways WHERE LOWER(status) IN ('down','force_down')"
            )->fetchColumn();

            $stmt = db()->prepare(
                "SELECT COUNT(*) FROM firewall_gateways
                  WHERE LOWER(status) IN ('loss','delay','degraded')
                     OR loss_percent >= ? OR latency_ms >= ?"
            );
            $stmt->execute([$t['gw_loss'], $t['gw_latency']]);
            $summary['gateways_warn'] = (int)$stmt->fetchColumn();

            $summary['vpn_down'] = (int)db()->query(
                "SELECT COUNT(*) FROM firewall_vpn_tunnels WHERE enabled = 1 AND LOWER(status) NOT IN ('up','connected')"
            )->fetchColumn();

            $summary['services_stopped'] = (int)db()->query(
                'SELECT COUNT(*) FROM firewall_services WHERE enabled = 1 AND running = 0'
            )->fetchColumn();

            $summary['certs_expired'] = (int)db()->query(
                'SELECT COUNT(*) FROM firewall_certificates WHERE days_remaining IS NOT NULL AND days_remaining < 0'
            )->fetchColumn();

            $stmt = db()->prepare(
                'SELECT COUNT(*) FROM firewall_certificates
                  WHERE days_remaining IS NOT NULL AND days_remaining >= 0 AND days_remaining <= ?'
            );
            $stmt->execute([$t['cert_medium']]);
            $summary['certs_expiring'] = (int)$stmt->fetchColumn();

            $summary['carp_init'] = (int)db()->query(
                "SELECT COUNT(*) FROM firewall_carp WHERE state = 'INIT'"
            )->fetchColumn();
        } catch (Throwable $e) {
            error_log('OPNMGR: health_fleet_summary failed: ' . $e->getMessage());
        }

        return $summary;
    }
}

if (!function_exists('health_gateway_flapping')) {
    /**
     * Gateways that changed state repeatedly in a recent window.
     *
     * A gateway that is cleanly down is one problem; one that is oscillating is
     * a different and often more urgent one, and a point-in-time status cannot
     * show it.
     */
    function health_gateway_flapping(int $hours = 24, int $minTransitions = 4): array {
        try {
            $stmt = db()->prepare(
                'SELECT e.firewall_id, f.hostname, e.gateway_name, COUNT(*) AS transitions,
                        MAX(e.occurred_at) AS last_change
                   FROM firewall_gateway_events e
                   JOIN firewalls f ON f.id = e.firewall_id
                  WHERE e.occurred_at >= (NOW() - INTERVAL ? HOUR)
                  GROUP BY e.firewall_id, f.hostname, e.gateway_name
                 HAVING transitions >= ?
                  ORDER BY transitions DESC'
            );
            $stmt->execute([$hours, $minTransitions]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('OPNMGR: health_gateway_flapping failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('health_expiring_certificates')) {
    /**
     * Certificates at or past a warning threshold, fleet-wide.
     */
    function health_expiring_certificates(?int $withinDays = null): array {
        $t = health_thresholds();
        $within = $withinDays ?? $t['cert_medium'];

        try {
            $stmt = db()->prepare(
                'SELECT c.*, f.hostname, cu.name AS customer_name
                   FROM firewall_certificates c
                   JOIN firewalls f ON f.id = c.firewall_id
                   LEFT JOIN customers cu ON cu.id = f.customer_id
                  WHERE c.days_remaining IS NOT NULL AND c.days_remaining <= ?
                  ORDER BY c.days_remaining'
            );
            $stmt->execute([$within]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('OPNMGR: health_expiring_certificates failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('health_resolve_ha_pairs')) {
    /**
     * Link CARP peers to each other where both are managed here.
     *
     * Used later to avoid updating or rebooting both members of an HA pair at
     * the same time. Matches on the reported peer host against hostname, WAN or
     * LAN address.
     *
     * @return int Number of links made
     */
    function health_resolve_ha_pairs(): int {
        $linked = 0;
        try {
            $rows = db()->query(
                "SELECT id, hostname, carp_peer_host FROM firewalls
                  WHERE carp_enabled = 1 AND carp_peer_host IS NOT NULL AND carp_peer_host <> ''"
            )->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $peer = db()->prepare(
                    'SELECT id FROM firewalls
                      WHERE id <> ? AND (hostname = ? OR wan_ip = ? OR lan_ip = ? OR ip_address = ?)
                      LIMIT 1'
                );
                $peer->execute([
                    $row['id'], $row['carp_peer_host'], $row['carp_peer_host'],
                    $row['carp_peer_host'], $row['carp_peer_host'],
                ]);
                $peerId = $peer->fetchColumn();

                if ($peerId) {
                    db()->prepare('UPDATE firewalls SET ha_peer_firewall_id = ? WHERE id = ?')
                        ->execute([(int)$peerId, (int)$row['id']]);
                    $linked++;
                }
            }
        } catch (Throwable $e) {
            error_log('OPNMGR: health_resolve_ha_pairs failed: ' . $e->getMessage());
        }
        return $linked;
    }
}

if (!function_exists('health_gateway_label')) {
    /**
     * Human label for an OPNsense gateway status.
     *
     * OPNsense reports "none" to mean "no problems detected", which reads as
     * missing rather than healthy. The raw value is kept in the database; this
     * is presentation only.
     */
    function health_gateway_label(?string $status): string {
        return match (strtolower((string)$status)) {
            'none', 'online', 'ok' => 'online',
            'down'                 => 'down',
            'force_down'           => 'forced down',
            'loss'                 => 'packet loss',
            'delay'                => 'high latency',
            'loss,delay', 'delay,loss' => 'loss + latency',
            'degraded'             => 'degraded',
            ''                     => 'unknown',
            default                => strtolower((string)$status),
        };
    }
}

if (!function_exists('agent_health_fleet')) {
    /**
     * Agent-side health across the fleet (P2 #22).
     *
     * Everything here is already recorded by the check-in path; this assembles
     * it into the questions an operator actually asks: is the agent current, is
     * it talking, is its clock sane, is it authenticating, and can it sign.
     */
    function agent_health_fleet(): array {
        try {
            $latest = '';
            $manifest = dirname(__DIR__) . '/downloads/manifest.json';
            if (is_file($manifest)) {
                require_once __DIR__ . '/agent_update.php';
                $target = latest_agent_artifact();
                $latest = $target['ok'] ? $target['version'] : '';
            }

            $rows = db()->query("
                SELECT f.id, f.hostname, f.status, f.agent_version, f.last_checkin,
                       f.checkin_interval, f.agent_auth_failures, f.agent_last_auth_failure_at,
                       f.agent_signing_supported, f.agent_clock_skew_seconds,
                       f.api_key_confirmed, f.last_update_result, f.last_update_error,
                       c.name AS customer_name,
                       TIMESTAMPDIFF(SECOND, f.last_checkin, NOW()) AS seconds_since_checkin
                  FROM firewalls f
                  LEFT JOIN customers c ON c.id = f.customer_id
                 ORDER BY f.hostname
            ")->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as &$r) {
                $r['latest_agent_version'] = $latest;
                $r['outdated'] = $latest !== '' && !empty($r['agent_version'])
                    && version_compare($r['agent_version'], $latest, '<');

                // A check-in more than three intervals late is overdue, not just
                // slightly behind.
                $interval = max(60, (int)($r['checkin_interval'] ?: 180));
                $silent   = $r['seconds_since_checkin'];
                $r['overdue'] = $silent !== null && (int)$silent > ($interval * 3);

                // Anything beyond a minute of skew will break signed requests
                // long before it breaks anything else.
                $skew = $r['agent_clock_skew_seconds'];
                $r['clock_skewed'] = $skew !== null && abs((int)$skew) > 60;
            }
            unset($r);

            return $rows;
        } catch (Throwable $e) {
            error_log('OPNMGR: agent_health_fleet failed: ' . $e->getMessage());
            return [];
        }
    }
}
