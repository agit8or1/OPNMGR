<?php
/**
 * OPNMGR Firewall Security Posture
 *
 * Computes the security facts shown on a firewall's Security tab from that
 * firewall's actual configuration and its recorded agent state.
 *
 * This exists because the panel it replaces was hardcoded: every firewall
 * displayed "SSH Access - Enabled - Port 22" and "API Authentication - Enabled"
 * with green ticks, regardless of configuration. A static green tick on a
 * security panel is worse than no panel, because it is read as evidence.
 *
 * The SSH question that actually matters is not "is sshd running" but "can the
 * internet reach it", and those are different questions with different answers.
 *
 * @since 3.18.0
 */

require_once __DIR__ . '/config_drift.php';
require_once __DIR__ . '/config_search.php';

if (!function_exists('firewall_security_posture')) {
    /**
     * Security posture for one firewall.
     *
     * @return array{
     *   freshness: array,
     *   ssh: array,
     *   api: array
     * }
     */
    function firewall_security_posture(int $firewallId): array {
        $freshness = drift_config_freshness($firewallId);

        $posture = [
            'freshness' => $freshness,
            'ssh' => [
                'known'         => false,
                'service'       => null,   // enabled | disabled
                'port'          => null,
                'exposure'      => 'unknown', // none | lan_only | wan_restricted | wan_open
                'sources'       => [],
                'rules'         => [],
                'root_login'    => null,
                'password_auth' => null,
                'severity'      => 'unknown', // ok | warning | critical | unknown
                'summary'       => 'No configuration backup available',
            ],
            'api' => api_auth_posture($firewallId),
        ];

        if (!$freshness['ok']) {
            return $posture;
        }

        $backup = drift_latest_backup($firewallId);
        $loaded = $backup ? drift_load_backup_xml((int)$backup['id']) : ['ok' => false];
        if (!$loaded['ok']) {
            return $posture;
        }

        $canonical = drift_xml_to_canonical($loaded['xml']);
        if (!$canonical['ok']) {
            $posture['ssh']['summary'] = 'Configuration could not be parsed';
            return $posture;
        }

        $root = $canonical['tree'][array_key_first($canonical['tree'])] ?? [];
        if (!is_array($root)) {
            return $posture;
        }

        $posture['ssh'] = ssh_posture($root) + ['known' => true];
        return $posture;
    }
}

if (!function_exists('ssh_posture')) {
    /**
     * Whether SSH is reachable, and from where.
     *
     * Distinguishes four states, because they carry very different risk:
     *   none            sshd is off
     *   lan_only        sshd is on but no WAN rule permits it (WAN default-denies)
     *   wan_restricted  a WAN rule permits it from specific sources only
     *   wan_open        a WAN rule permits it from any source
     *
     * @param array $cfg Canonical configuration tree
     */
    function ssh_posture(array $cfg): array {
        $ssh     = $cfg['system']['ssh'] ?? [];
        $enabled = isset($ssh['enabled']) && (string)$ssh['enabled'] !== '' && (string)$ssh['enabled'] !== '0';
        $port    = (string)($ssh['port'] ?? '') !== '' ? (string)$ssh['port'] : '22';

        $rootLogin    = isset($ssh['permitrootlogin']) && (string)$ssh['permitrootlogin'] === '1';
        $passwordAuth = isset($ssh['passwordauth']) && (string)$ssh['passwordauth'] === '1';

        $rules   = [];
        $sources = [];
        $open    = false;

        foreach (config_search_rules($cfg) as $rule) {
            // A disabled rule permits nothing.
            if (!empty($rule['disabled'])) {
                continue;
            }
            if (strtolower((string)($rule['type'] ?? '')) !== 'pass') {
                continue;
            }

            $iface = strtolower((string)($rule['interface'] ?? ''));
            if (!str_contains($iface, 'wan')) {
                continue;
            }

            $rulePort = (string)($rule['destination']['port'] ?? '');
            if ($rulePort !== $port && strtolower($rulePort) !== 'ssh') {
                continue;
            }

            $src = (array)($rule['source'] ?? []);
            if (array_key_exists('any', $src)) {
                $open      = true;
                $sourceTxt = 'any';
            } elseif (isset($src['address'])) {
                $sourceTxt = is_array($src['address']) ? implode(', ', $src['address']) : (string)$src['address'];
            } elseif (isset($src['network'])) {
                $sourceTxt = is_array($src['network']) ? implode(', ', $src['network']) : (string)$src['network'];
            } else {
                // A pass rule whose source cannot be identified is treated as
                // open: guessing in the permissive direction on a security
                // panel is the wrong way round.
                $open      = true;
                $sourceTxt = 'unrestricted (source not recognised)';
            }

            $sources[] = $sourceTxt;
            $rules[]   = [
                'interface'   => $iface,
                'port'        => $rulePort,
                'source'      => $sourceTxt,
                'description' => (string)($rule['descr'] ?? ''),
            ];
        }

        $sources = array_values(array_unique($sources));

        if (!$enabled) {
            $exposure = 'none';
            $severity = 'ok';
            $summary  = 'SSH service is disabled';
        } elseif ($open) {
            $exposure = 'wan_open';
            $severity = 'critical';
            $summary  = sprintf('Reachable from ANY source on WAN port %s', $port);
        } elseif ($rules) {
            $exposure = 'wan_restricted';
            $severity = 'warning';
            $summary  = sprintf('Reachable on WAN port %s from %d permitted source(s)', $port, count($sources));
        } else {
            $exposure = 'lan_only';
            $severity = 'ok';
            $summary  = sprintf('Running on port %s, no WAN rule permits it', $port);
        }

        return [
            'service'       => $enabled ? 'enabled' : 'disabled',
            'port'          => $port,
            'exposure'      => $exposure,
            'sources'       => $sources,
            'rules'         => $rules,
            'root_login'    => $rootLogin,
            'password_auth' => $passwordAuth,
            'severity'      => $severity,
            'summary'       => $summary,
        ];
    }
}

if (!function_exists('api_auth_posture')) {
    /**
     * Real agent authentication state, from what the agent has actually proven.
     *
     * The panel this replaces asserted "Token-based auth: Enabled" for every
     * firewall, including ones still authenticating on hardware_id alone.
     */
    function api_auth_posture(int $firewallId): array {
        try {
            $stmt = db()->prepare(
                'SELECT api_key_confirmed, agent_signing_supported, agent_auth_failures,
                        agent_last_auth_failure_at, agent_clock_skew_seconds, agent_version
                   FROM firewalls WHERE id = ?'
            );
            $stmt->execute([$firewallId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('OPNMGR: api_auth_posture failed: ' . $e->getMessage());
            $row = null;
        }

        if (!$row) {
            return ['severity' => 'unknown', 'summary' => 'Unknown', 'detail' => '',
                    'key_pinned' => false, 'signing' => false, 'failures' => 0];
        }

        $pinned  = (int)$row['api_key_confirmed'] === 1;
        $signing = (int)$row['agent_signing_supported'] === 1;
        $fails   = (int)$row['agent_auth_failures'];

        if ($fails >= 5) {
            $severity = 'critical';
            $summary  = sprintf('%d consecutive authentication failures', $fails);
        } elseif ($signing) {
            $severity = 'ok';
            $summary  = 'API key pinned, requests signed';
        } elseif ($pinned) {
            $severity = 'ok';
            $summary  = 'API key pinned';
        } else {
            // The bootstrap window: still accepting hardware_id alone, which is
            // a device fingerprint rather than a secret.
            $severity = 'warning';
            $summary  = 'Bootstrapping — hardware_id only';
        }

        $detail = $pinned
            ? ($signing ? 'HMAC-signed requests' : 'Key adopted; not yet signing')
            : 'Agent has not yet presented its issued API key';

        return [
            'severity'   => $severity,
            'summary'    => $summary,
            'detail'     => $detail,
            'key_pinned' => $pinned,
            'signing'    => $signing,
            'failures'   => $fails,
            'skew'       => $row['agent_clock_skew_seconds'],
        ];
    }
}

if (!function_exists('posture_badge_class')) {
    /** Bootstrap contextual class for a posture severity. */
    function posture_badge_class(string $severity): string {
        return match ($severity) {
            'critical' => 'danger',
            'warning'  => 'warning',
            'ok'       => 'success',
            default    => 'secondary',
        };
    }
}
