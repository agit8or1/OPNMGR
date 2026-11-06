<?php
/**
 * OPNMGR platform layer: detection, capabilities and dispatch gating.
 *
 *   platform_detect()           evidence -> family/edition/confidence
 *   platform_record_detection() persists it on the firewall row
 *   platform_adapter()          the adapter for a firewall (or family)
 *   platform_capabilities()     per-capability state for one firewall
 *   platform_action_verdict()   may this catalogue action be queued/delivered
 *   platform_delivery_verdict() the last check before a queued command leaves
 *                               the manager - legacy endpoints that insert
 *                               into firewall_commands directly pass here too
 *
 * Rules this file enforces:
 *
 *   - The product is never inferred from FreeBSD alone.
 *   - Unknown or conflicting evidence means read-only.
 *   - "Implemented" is not "supported". A capability is `supported` only when
 *     it is part of the long-standing OPNsense baseline or a validation for
 *     this platform/edition/version has been recorded from a real device.
 *     Everything else that is implemented is `untested`, and untested write
 *     operations are refused except on firewalls an administrator has named
 *     as lab devices.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/adapters.php';
require_once __DIR__ . '/config_model.php';

const PLATFORM_FAMILIES = ['opnsense', 'pfsense'];

if (!function_exists('platform_capability_catalog')) {
    /**
     * Every capability the UI reports.
     * class: read  = observes the firewall only
     *        write = changes the firewall
     */
    function platform_capability_catalog(): array {
        return [
            'telemetry.checkin'   => ['label' => 'Check-in & inventory',       'class' => 'read'],
            'telemetry.health'    => ['label' => 'Gateways, services, certs',  'class' => 'read'],
            'telemetry.vpn'       => ['label' => 'VPN status telemetry',       'class' => 'read'],
            'ha.detect'           => ['label' => 'CARP / HA detection',        'class' => 'read'],
            'config.backup'       => ['label' => 'Configuration backup',       'class' => 'read'],
            'config.parse'        => ['label' => 'Configuration interpretation','class' => 'read'],
            'diagnostics.network' => ['label' => 'Ping / traceroute / DNS',    'class' => 'read'],
            'vpn.diagnostics'     => ['label' => 'VPN diagnostics',            'class' => 'read'],
            'firmware.check'      => ['label' => 'Firmware update check',      'class' => 'read'],
            'policy.preview'      => ['label' => 'Template preview',           'class' => 'read'],
            'service.control'     => ['label' => 'Service start/stop/restart', 'class' => 'write'],
            'system.reboot'       => ['label' => 'Reboot',                     'class' => 'write'],
            'config.restore'      => ['label' => 'Configuration restore',      'class' => 'write'],
            'firmware.install'    => ['label' => 'Firmware installation',      'class' => 'write'],
            'agent.update'        => ['label' => 'Agent update / restart',     'class' => 'write'],
            'remote.tunnel'       => ['label' => 'Remote GUI / SSH tunnel',    'class' => 'write'],
            'policy.apply'        => ['label' => 'Template application',       'class' => 'write'],
            'command.raw'         => ['label' => 'Raw shell commands',         'class' => 'write'],
        ];
    }
}

if (!function_exists('platform_action_capability')) {
    /** Which capability a catalogue action exercises. */
    function platform_action_capability(?string $action): ?string {
        return match ($action) {
            'service_status', 'agent_status', 'agent_install_verify', 'platform_probe' => 'telemetry.checkin',
            'gateway_status'                    => 'telemetry.health',
            'service_restart', 'service_start',
            'service_stop', 'vpn_restart'       => 'service.control',
            'reboot'                            => 'system.reboot',
            'check_updates'                     => 'firmware.check',
            'install_updates'                   => 'firmware.install',
            'ping', 'traceroute', 'dns_lookup',
            'interface_list'                    => 'diagnostics.network',
            'vpn_status', 'vpn_probe'           => 'vpn.diagnostics',
            'retrieve_config'                   => 'config.backup',
            'restore_config'                    => 'config.restore',
            'agent_restart', 'agent_update'     => 'agent.update',
            'policy_apply', 'policy_confirm',
            'policy_rollback'                   => 'policy.apply',
            null                                => 'command.raw',
            default                             => null,
        };
    }
}

if (!function_exists('platform_opnsense_baseline')) {
    /**
     * Capabilities OPNMGR has run against production OPNsense since before
     * mixed-fleet support, and so are not "untested" on OPNsense. Restore and
     * template application are deliberately absent: restore had never
     * executed successfully (see installConfigShell) and templates are new.
     */
    function platform_opnsense_baseline(): array {
        return ['telemetry.checkin', 'telemetry.health', 'telemetry.vpn', 'ha.detect',
                'config.backup', 'config.parse', 'diagnostics.network', 'vpn.diagnostics',
                'firmware.check', 'policy.preview', 'service.control', 'system.reboot',
                'firmware.install', 'agent.update', 'remote.tunnel', 'command.raw'];
    }
}

if (!function_exists('platform_setting')) {
    function platform_setting(string $name, string $default = ''): string {
        try {
            $s = db()->prepare('SELECT `value` FROM settings WHERE `name` = ?');
            $s->execute([$name]);
            $v = $s->fetchColumn();
            return ($v === false || $v === null) ? $default : (string)$v;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('platform_lab_firewall_ids')) {
    /** Firewalls an administrator has designated as lab devices. */
    function platform_lab_firewall_ids(): array {
        $raw = platform_setting('policy_apply_lab_firewall_ids', '');
        return array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,]+/', $raw)))));
    }
}

// ---------------------------------------------------------------------------
// Detection
// ---------------------------------------------------------------------------

if (!function_exists('platform_detect')) {
    /**
     * Decide the platform from evidence.
     *
     * @param array $e  agent_platform  array  `platform` block from a 1.8+/pfSense agent
     *                  agent_version   string
     *                  version_string  string reported product version (legacy agents)
     *                  config_root     string root element of the latest backup
     * @return array{family:?string, edition:?string, version:?string, base_version:?string,
     *               config_version:?string, confidence:string, source:string, conflict:?string}
     */
    function platform_detect(array $e): array {
        $votes = [];   // [family, confidence, source]
        $ap = is_array($e['agent_platform'] ?? null) ? $e['agent_platform'] : [];
        $markers = is_array($ap['markers'] ?? null) ? $ap['markers'] : [];

        // 1. Product-specific markers observed by the agent on the firewall.
        if (!empty($markers['opnsense_version_bin'])) {
            $votes[] = ['opnsense', 'high', 'agent:opnsense-version'];
        }
        if (!empty($markers['pfsense_globals'])) {
            $votes[] = ['pfsense', 'high', 'agent:/etc/inc/globals.inc'];
        }
        // 2. Self-declared family without a marker: an agent can be installed
        //    on the wrong product, so this is weaker evidence.
        $declared = strtolower((string)($ap['family'] ?? ''));
        if (in_array($declared, PLATFORM_FAMILIES, true) && !$markers) {
            $votes[] = [$declared, 'medium', 'agent:declared'];
        }
        // 3. Legacy OPNsense plugin agents report the output of
        //    `opnsense-version`, which only exists on OPNsense.
        if (!$ap && str_starts_with((string)($e['agent_version'] ?? ''), '1.')
            && preg_match('/^\d{2}\.\d+/', (string)($e['version_string'] ?? ''))) {
            $votes[] = ['opnsense', 'medium', 'agent:opnsense-plugin-version'];
        }
        // 4. The configuration itself.
        $root = strtolower((string)($e['config_root'] ?? ''));
        if (in_array($root, PLATFORM_FAMILIES, true)) {
            $votes[] = [$root, 'high', 'config:<' . $root . '>'];
        }
        // FreeBSD version is deliberately not a vote: both products run it.

        $families = array_unique(array_column($votes, 0));
        $result = [
            'family'         => null,
            'edition'        => null,
            'version'        => isset($ap['product_version']) ? substr((string)$ap['product_version'], 0, 64)
                                : (($e['version_string'] ?? '') !== '' ? substr((string)$e['version_string'], 0, 64) : null),
            'base_version'   => isset($ap['base_version']) ? substr((string)$ap['base_version'], 0, 64) : null,
            'config_version' => isset($ap['config_version']) ? substr((string)$ap['config_version'], 0, 16) : null,
            'confidence'     => 'low',
            'source'         => $votes ? implode('+', array_column($votes, 2)) : 'none',
            'conflict'       => null,
        ];

        if (!$votes) {
            return $result;
        }
        if (count($families) > 1) {
            $result['conflict'] = 'Evidence disagrees: ' . implode('; ', array_map(
                fn($v) => "{$v[2]} says {$v[0]}", $votes));
            return $result;
        }

        $result['family'] = $families[0];
        $result['confidence'] = in_array('high', array_column($votes, 1), true) ? 'high' : 'medium';

        if ($result['family'] === 'pfsense') {
            $result['edition'] = platform_pfsense_edition($ap, (string)$result['version']);
        }
        return $result;
    }
}

if (!function_exists('platform_pfsense_edition')) {
    /**
     * CE versions are 2.x (2.7.2-RELEASE); Plus versions are YY.MM (25.07).
     * globals.plus.inc ships only on Plus and settles it outright.
     */
    function platform_pfsense_edition(array $ap, string $version): string {
        $markers = $ap['markers'] ?? [];
        if (!empty($markers['plus_globals'])) {
            return 'plus';
        }
        if (preg_match('/^2\.\d+/', $version)) {
            return 'community';
        }
        if (preg_match('/^(2[1-9]|[3-9]\d)\.\d{2}/', $version)) {
            return 'plus';
        }
        return 'unknown';
    }
}

if (!function_exists('platform_record_detection')) {
    /**
     * Persist a detection result and the agent's probe on the firewall.
     * A downgrade from a verified family to unknown is recorded as a conflict
     * rather than silently clearing what was known.
     */
    function platform_record_detection(int $firewallId, array $d, array $agentPlatform = []): void {
        $stmt = db()->prepare('SELECT platform_family FROM firewalls WHERE id = ?');
        $stmt->execute([$firewallId]);
        $previous = $stmt->fetchColumn() ?: null;

        $conflict = $d['conflict'];
        if ($previous && $d['family'] && $previous !== $d['family'] && $d['confidence'] !== 'high') {
            $conflict = "Previously {$previous}, now reported as {$d['family']} with only {$d['confidence']} confidence";
        }

        $probe = $agentPlatform['probes'] ?? null;
        $packages = $agentPlatform['packages'] ?? null;

        db()->prepare(
            'UPDATE firewalls SET
                platform_family = ?, platform_edition = ?, platform_os_version = COALESCE(?, platform_os_version),
                platform_base_version = COALESCE(?, platform_base_version),
                platform_config_version = COALESCE(?, platform_config_version),
                platform_packages = COALESCE(?, platform_packages),
                platform_probe = COALESCE(?, platform_probe),
                platform_detection_source = ?, platform_detection_confidence = ?,
                platform_detection_conflict = ?, platform_verified_at = NOW(),
                agent_flavor = COALESCE(?, agent_flavor)
              WHERE id = ?'
        )->execute([
            $conflict ? null : $d['family'],
            $conflict ? null : $d['edition'],
            $d['version'], $d['base_version'], $d['config_version'],
            is_array($packages) ? json_encode(array_slice(array_map(fn($p) => substr((string)$p, 0, 96), $packages), 0, 200)) : null,
            is_array($probe) ? json_encode(array_map(fn($v) => is_bool($v) ? $v : substr((string)$v, 0, 128), array_slice($probe, 0, 64, true))) : null,
            substr($d['source'], 0, 64), $d['confidence'],
            $conflict ? substr($conflict, 0, 255) : null,
            isset($agentPlatform['agent_flavor']) ? substr((string)$agentPlatform['agent_flavor'], 0, 32) : null,
            $firewallId,
        ]);

        if ($previous !== ($conflict ? null : $d['family'])) {
            audit_log_agent('platform.detected', $firewallId, [
                'message'  => sprintf('Platform %s -> %s (%s, %s)', $previous ?? 'unknown',
                                      $conflict ? 'unknown (conflict)' : ($d['family'] ?? 'unknown'),
                                      $d['source'], $d['confidence']),
                'metadata' => ['detection' => $d],
            ]);
        }
    }
}

// ---------------------------------------------------------------------------
// Adapters
// ---------------------------------------------------------------------------

if (!function_exists('platform_adapter')) {
    /** @param array|string|null $firewallOrFamily */
    function platform_adapter($firewallOrFamily): OpnmgrPlatformAdapter {
        $family = is_array($firewallOrFamily)
            ? (!empty($firewallOrFamily['platform_detection_conflict']) ? null : ($firewallOrFamily['platform_family'] ?? null))
            : $firewallOrFamily;
        return match ($family) {
            'opnsense' => new OpnmgrOpnsenseAdapter(),
            'pfsense'  => new OpnmgrPfsenseAdapter(),
            default    => new OpnmgrUnknownAdapter(),
        };
    }
}

if (!function_exists('platform_firewall')) {
    /** The firewall row with its platform columns, or null. */
    function platform_firewall(int $firewallId): ?array {
        $s = db()->prepare('SELECT * FROM firewalls WHERE id = ?');
        $s->execute([$firewallId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

// ---------------------------------------------------------------------------
// Capabilities
// ---------------------------------------------------------------------------

if (!function_exists('platform_validations')) {
    function platform_validations(bool $reset = false): array {
        static $rows = null;
        if ($reset) { $rows = null; }
        if ($rows === null) {
            try {
                $rows = db()->query('SELECT * FROM platform_validations')->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $rows = [];
            }
        }
        return $rows;
    }
}

if (!function_exists('platform_find_validation')) {
    function platform_find_validation(array $fw, string $capability): ?array {
        $version = (string)($fw['platform_os_version'] ?? '');
        $best = null;
        foreach (platform_validations() as $v) {
            if ($v['platform_family'] !== ($fw['platform_family'] ?? '')
                || $v['capability'] !== $capability
                || ($v['platform_edition'] !== '' && $v['platform_edition'] !== ($fw['platform_edition'] ?? ''))
                || $version === '' || !str_starts_with($version, $v['version_prefix'])) {
                continue;
            }
            if ($best === null || strlen($v['version_prefix']) > strlen($best['version_prefix'])) {
                $best = $v;
            }
        }
        return $best;
    }
}

if (!function_exists('platform_capabilities')) {
    /**
     * State of every capability for one firewall.
     *
     * state: supported   - implemented and validated (or OPNsense baseline)
     *        untested    - implemented, never exercised on this platform/version
     *        unsupported - the platform cannot, or OPNMGR does not implement it
     *        unavailable - implemented, but a dependency or verification is missing
     *        stale       - would be supported, but the platform was last
     *                      verified too long ago to rely on
     *
     * @return array<string, array{state:string, reason:string, class:string, label:string,
     *                             validated_at:?string, evidence:?string}>
     */
    function platform_capabilities(array $fw): array {
        $catalog  = platform_capability_catalog();
        $adapter  = platform_adapter($fw);
        $family   = $adapter->family();
        $probe    = json_decode((string)($fw['platform_probe'] ?? ''), true) ?: [];
        $staleH   = max(1, (int)platform_setting('platform_verification_stale_hours', '24'));
        $verified = !empty($fw['platform_verified_at']) ? strtotime((string)$fw['platform_verified_at']) : null;
        $isStale  = $verified === null || (time() - $verified) > $staleH * 3600;

        $out = [];
        foreach ($catalog as $cap => $meta) {
            $row = ['state' => 'unsupported', 'reason' => '', 'class' => $meta['class'],
                    'label' => $meta['label'], 'validated_at' => null, 'evidence' => null];

            if ($family === 'unknown') {
                $readOk = in_array($cap, ['telemetry.checkin', 'config.backup', 'diagnostics.network'], true);
                $row['state']  = $readOk ? 'untested' : 'unavailable';
                $row['reason'] = !empty($fw['platform_detection_conflict'])
                    ? 'Platform evidence conflicts (' . $fw['platform_detection_conflict'] . '); read-only until resolved.'
                    : 'Platform not verified; read-only until the agent reports it.';
                $out[$cap] = $row;
                continue;
            }

            $impl = $adapter->capabilityImplementation($cap, $fw);
            if (!$impl['implemented']) {
                $row['reason'] = $impl['reason'];
                $out[$cap] = $row;
                continue;
            }

            // Dependency probes reported by the agent.
            $needs = $impl['requires_probe'] ?? null;
            if ($needs !== null && empty($probe[$needs])) {
                $row['state']  = 'unavailable';
                $row['reason'] = $probe
                    ? "The agent reported that '{$needs}' is not available on this firewall."
                    : "Needs the platform probe from agent 1.8+ / the pfSense agent ('{$needs}'), which this firewall has not sent.";
                $out[$cap] = $row;
                continue;
            }

            $validation = platform_find_validation($fw, $cap);
            $baseline = $family === 'opnsense' && in_array($cap, platform_opnsense_baseline(), true);

            if ($validation) {
                $row['state'] = 'supported';
                $row['validated_at'] = $validation['validated_at'];
                $row['evidence'] = $validation['evidence'];
                $row['reason'] = $impl['reason'];
            } elseif ($baseline) {
                $row['state'] = 'supported';
                $row['evidence'] = 'Existing OPNsense functionality, in production use before mixed-fleet support';
                $row['reason'] = $impl['reason'];
            } else {
                $row['state'] = 'untested';
                $row['reason'] = trim($impl['reason'] . ' — not yet exercised on a live '
                    . $adapter->label($fw['platform_edition'] ?? null) . ' '
                    . ($fw['platform_os_version'] ?? '') . ' device.');
            }

            if ($row['state'] === 'supported' && $isStale) {
                $row['state'] = 'stale';
                $row['reason'] = sprintf('Platform last verified %s; capabilities are not relied on after %d hours.',
                    $verified ? date('Y-m-d H:i', $verified) : 'never', $staleH);
            }
            $out[$cap] = $row;
        }
        return $out;
    }
}

if (!function_exists('platform_capability_allows')) {
    /**
     * Whether a capability may be exercised now.
     * Reads: anything but unsupported/unavailable.
     * Writes: supported; or untested on a designated lab firewall.
     *
     * @return array{allowed:bool, reason:string, state:string}
     */
    function platform_capability_allows(array $fw, string $capability): array {
        $caps = platform_capabilities($fw);
        $c = $caps[$capability] ?? null;
        if ($c === null) {
            return ['allowed' => false, 'reason' => "Unknown capability {$capability}", 'state' => 'unsupported'];
        }
        if (in_array($c['state'], ['unsupported', 'unavailable'], true)) {
            return ['allowed' => false, 'reason' => $c['reason'], 'state' => $c['state']];
        }
        if ($c['class'] === 'read') {
            return ['allowed' => true, 'reason' => $c['reason'], 'state' => $c['state']];
        }
        if ($c['state'] === 'supported') {
            return ['allowed' => true, 'reason' => '', 'state' => 'supported'];
        }
        if ($c['state'] === 'untested' && in_array((int)$fw['id'], platform_lab_firewall_ids(), true)) {
            return ['allowed' => true, 'reason' => 'Untested capability allowed: designated lab firewall', 'state' => 'untested'];
        }
        return ['allowed' => false, 'state' => $c['state'],
                'reason' => $c['state'] === 'stale'
                    ? $c['reason']
                    : $c['label'] . ' is untested on this platform/version and this firewall is not a designated lab device. ' . $c['reason']];
    }
}

// ---------------------------------------------------------------------------
// Dispatch gating
// ---------------------------------------------------------------------------

if (!function_exists('platform_action_verdict')) {
    /**
     * Whether a structured action can be queued for a firewall, and the shell
     * for it on that platform.
     *
     * @return array{ok:bool, error:string, command:string}
     */
    function platform_action_verdict(array $fw, string $action, array $validatedParams): array {
        $cap = platform_action_capability($action);
        if ($cap === null) {
            return ['ok' => false, 'error' => "Action {$action} is not mapped to a capability", 'command' => ''];
        }
        $allow = platform_capability_allows($fw, $cap);
        if (!$allow['allowed']) {
            return ['ok' => false, 'error' => $allow['reason'], 'command' => ''];
        }
        $adapter = platform_adapter($fw);
        if (isset($validatedParams['service'])
            && !in_array($validatedParams['service'], $adapter->serviceAllowlist(), true)) {
            return ['ok' => false, 'command' => '',
                    'error' => sprintf("Service '%s' is not a known %s service", $validatedParams['service'], $adapter->label())];
        }
        $cmd = $adapter->buildAction($action, $validatedParams);
        if ($cmd === null) {
            return ['ok' => false, 'error' => $adapter->unsupportedReason($action), 'command' => ''];
        }
        return ['ok' => true, 'error' => '', 'command' => $cmd];
    }
}

if (!function_exists('platform_delivery_verdict')) {
    /**
     * Last gate before a queued command is handed to an agent.
     *
     * Several legacy endpoints insert shell text into firewall_commands
     * directly. They cannot be trusted to have asked the adapter, so this
     * runs on every delivery regardless of how the row got there.
     *
     * @param array $fw  firewall row
     * @param array $cmd firewall_commands row (command, action, is_raw)
     * @return array{deliver:bool, reason:string}
     */
    function platform_delivery_verdict(array $fw, array $cmd): array {
        $adapter = platform_adapter($fw);
        $text = (string)($cmd['command'] ?? '');
        $action = ($cmd['action'] ?? '') !== '' ? (string)$cmd['action'] : null;

        foreach ($adapter->foreignTools() as $tool) {
            if (stripos($text, $tool) !== false) {
                return ['deliver' => false, 'reason' => sprintf(
                    'Not delivered: the command uses %s, which does not exist on %s.', $tool,
                    $adapter->label($fw['platform_edition'] ?? null))];
            }
        }

        // Structured commands that were queued through the adapter are
        // re-checked against current capabilities (the platform may have
        // changed, or gone stale, since queueing). Raw and legacy rows map to
        // command.raw.
        $capability = ($cmd['is_raw'] ?? 1) ? 'command.raw' : platform_action_capability($action);

        // Policy confirm/rollback must never be withheld once an apply was
        // delivered: refusing to deliver the confirmation would trigger a
        // rollback, refusing the rollback would leave an unverified change.
        if (in_array($action, ['policy_confirm', 'policy_rollback'], true)) {
            return ['deliver' => true, 'reason' => ''];
        }
        if ($capability === null) {
            return ['deliver' => false, 'reason' => "Not delivered: action {$action} is not mapped to a platform capability."];
        }
        $allow = platform_capability_allows($fw, $capability);
        if (!$allow['allowed']) {
            return ['deliver' => false, 'reason' => 'Not delivered: ' . $allow['reason']];
        }
        return ['deliver' => true, 'reason' => ''];
    }
}

if (!function_exists('platform_summary')) {
    /** One-line label for lists: "pfSense Plus 25.07 (high)". */
    function platform_summary(array $fw): string {
        $a = platform_adapter($fw);
        if ($a->family() === 'unknown') {
            return !empty($fw['platform_detection_conflict']) ? 'Unknown (conflicting evidence)' : 'Unknown';
        }
        return trim($a->label($fw['platform_edition'] ?? null) . ' ' . ($fw['platform_os_version'] ?? ''));
    }
}

if (!function_exists('platform_config_root_of_latest_backup')) {
    /** Root element of the newest validated backup, for detection. */
    function platform_config_root_of_latest_backup(int $firewallId): ?string {
        $s = db()->prepare('SELECT * FROM backups WHERE firewall_id = ? AND validated = 1 ORDER BY id DESC LIMIT 1');
        $s->execute([$firewallId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row || !function_exists('resolve_backup_path')) {
            return null;
        }
        $path = resolve_backup_path($row);
        if ($path === null) {
            return null;
        }
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return null;
        }
        $head = (string)fread($fh, 4096);
        fclose($fh);
        return preg_match('/<(opnsense|pfsense)[\s>]/i', $head, $m) ? strtolower($m[1]) : null;
    }
}
