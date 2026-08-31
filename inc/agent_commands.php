<?php
/**
 * OPNMGR Remote Operations
 *
 * OPNMGR legitimately needs to run things on managed firewalls, so this module
 * does not remove that capability - it splits it in two:
 *
 *   A. Structured actions. A fixed catalogue of operations addressed by id
 *      ('service_restart') with typed, validated parameters. The shell text is
 *      built here from a template; nothing an operator types is ever
 *      concatenated into it unescaped.
 *
 *   B. Raw shell. Still available, because this is an MSP administrator tool,
 *      but it is now an explicitly privileged path: admin-only by default,
 *      gated by a setting, and always written to the audit log with the user,
 *      source IP, target firewall and (later) the result.
 *
 * Every queue goes through queue_firewall_command(), which is the only place
 * that inserts into firewall_commands, so a command can never be attributed to
 * the wrong firewall or escape the audit trail.
 *
 * @since 3.12.0
 */

require_once __DIR__ . '/audit.php';

if (!function_exists('agent_service_allowlist')) {
    /**
     * Services a structured action may act on.
     *
     * An allowlist rather than a pattern: these are the service names OPNsense
     * ships, and anything outside the list is rejected instead of escaped.
     *
     * @return string[]
     */
    function agent_service_allowlist(): array {
        return [
            'unbound', 'dnsmasq', 'dhcpd', 'kea-dhcp4', 'kea-dhcp6',
            'openvpn', 'ipsec', 'strongswan', 'wireguard', 'frr',
            'nginx', 'lighttpd', 'openssh', 'sshd', 'ntpd', 'chronyd',
            'syslog-ng', 'cron', 'configd', 'suricata', 'zabbix-agent',
            'haproxy', 'squid', 'radvd', 'miniupnpd', 'telegraf',
            'monit', 'netdata', 'collectd', 'apcupsd', 'pf', 'filter',
        ];
    }
}

if (!function_exists('agent_command_catalog')) {
    /**
     * The structured operation catalogue.
     *
     * Each entry:
     *   label     - what the UI shows
     *   category  - grouping
     *   risk      - LOW | MEDIUM | HIGH | CRITICAL
     *   params    - name => ['type' => ..., 'required' => bool]
     *   build     - fn(array $params): string  returns the shell command
     *
     * Parameter types are validated by validate_action_params() before build()
     * is called, and every interpolation inside build() uses escapeshellarg().
     *
     * @return array<string, array>
     */
    function agent_command_catalog(): array {
        static $catalog = null;
        if ($catalog !== null) {
            return $catalog;
        }

        $catalog = [
            // --- system ------------------------------------------------------
            'reboot' => [
                'label'    => 'Reboot firewall',
                'category' => 'system',
                'risk'     => 'HIGH',
                'params'   => ['delay_minutes' => ['type' => 'int', 'min' => 0, 'max' => 60, 'required' => false]],
                'build'    => fn(array $p) => sprintf(
                    '/sbin/shutdown -r +%d "Reboot requested via OPNManager"',
                    $p['delay_minutes'] ?? 1
                ),
            ],
            'service_status' => [
                'label'    => 'Service status',
                'category' => 'services',
                'risk'     => 'LOW',
                'params'   => ['service' => ['type' => 'service', 'required' => true]],
                'build'    => fn(array $p) => '/usr/local/sbin/configctl ' . escapeshellarg($p['service']) . ' status',
            ],
            'service_restart' => [
                'label'    => 'Restart service',
                'category' => 'services',
                'risk'     => 'MEDIUM',
                'params'   => ['service' => ['type' => 'service', 'required' => true]],
                'build'    => fn(array $p) => '/usr/local/sbin/configctl ' . escapeshellarg($p['service']) . ' restart',
            ],
            'service_start' => [
                'label'    => 'Start service',
                'category' => 'services',
                'risk'     => 'MEDIUM',
                'params'   => ['service' => ['type' => 'service', 'required' => true]],
                'build'    => fn(array $p) => '/usr/local/sbin/configctl ' . escapeshellarg($p['service']) . ' start',
            ],
            'service_stop' => [
                'label'    => 'Stop service',
                'category' => 'services',
                'risk'     => 'HIGH',
                'params'   => ['service' => ['type' => 'service', 'required' => true]],
                'build'    => fn(array $p) => '/usr/local/sbin/configctl ' . escapeshellarg($p['service']) . ' stop',
            ],

            // --- updates -----------------------------------------------------
            'check_updates' => [
                'label'    => 'Check for updates',
                'category' => 'packages',
                'risk'     => 'LOW',
                'params'   => [],
                'build'    => fn(array $p) => '/usr/local/sbin/opnsense-update -c',
            ],
            'install_updates' => [
                'label'    => 'Install updates',
                'category' => 'packages',
                'risk'     => 'HIGH',
                'params'   => [],
                // Redirect the updater's own output to a log rather than letting
                // it stream back through the agent.
                //
                // The agent executes commands as `eval "$cmd" 2>&1 | head -1000`.
                // A real upgrade emits far more than 1000 lines (fw48's
                // 26.1.3 -> 26.1.11 run pulled 99 packages), and when head exits
                // the writer gets SIGPIPE - which can kill opnsense-update
                // partway through an upgrade. Writing to a file keeps the
                // updater's stdout off that pipe entirely, and the bounded tail
                // below is all that ever reaches head.
                //
                // The exit code is captured and echoed as a parseable marker
                // because the agent hardcodes "status":"completed" when it
                // reports back, so the transport cannot tell us whether the
                // upgrade actually succeeded. It is written into the log too, so
                // the outcome survives a reboot that cuts the report short.
                'build'    => fn(array $p) =>
                    'LOG=/var/log/opnmanager_update.log; '
                    . ': >"$LOG" 2>/dev/null; '
                    . '/usr/local/sbin/opnsense-update -bkp >>"$LOG" 2>&1; '
                    . 'rc=$?; '
                    . 'echo "OPNMGR_UPDATE_EXIT=$rc" >>"$LOG"; '
                    . 'echo "OPNMGR_UPDATE_EXIT=$rc"; '
                    . 'echo "--- opnsense-update output (last 80 lines) ---"; '
                    . 'tail -n 80 "$LOG"',
            ],

            // --- diagnostics -------------------------------------------------
            'ping' => [
                'label'    => 'Ping host',
                'category' => 'network',
                'risk'     => 'LOW',
                'params'   => [
                    'target' => ['type' => 'host', 'required' => true],
                    'count'  => ['type' => 'int', 'min' => 1, 'max' => 20, 'required' => false],
                ],
                'build'    => fn(array $p) => sprintf(
                    '/sbin/ping -c %d -t 10 %s',
                    $p['count'] ?? 4,
                    escapeshellarg($p['target'])
                ),
            ],
            'traceroute' => [
                'label'    => 'Traceroute',
                'category' => 'network',
                'risk'     => 'LOW',
                'params'   => ['target' => ['type' => 'host', 'required' => true]],
                'build'    => fn(array $p) => '/usr/sbin/traceroute -w 2 -q 1 -m 20 ' . escapeshellarg($p['target']),
            ],
            'dns_lookup' => [
                'label'    => 'DNS lookup',
                'category' => 'network',
                'risk'     => 'LOW',
                'params'   => [
                    'target' => ['type' => 'host', 'required' => true],
                    'type'   => ['type' => 'enum', 'values' => ['A','AAAA','MX','TXT','NS','CNAME','SOA','PTR'], 'required' => false],
                ],
                'build'    => fn(array $p) => sprintf(
                    '/usr/bin/drill %s %s',
                    escapeshellarg($p['target']),
                    escapeshellarg($p['type'] ?? 'A')
                ),
            ],
            'gateway_status' => [
                'label'    => 'Gateway diagnostics',
                'category' => 'network',
                'risk'     => 'LOW',
                'params'   => [],
                'build'    => fn(array $p) => '/usr/local/sbin/configctl interface gateways status',
            ],
            'interface_list' => [
                'label'    => 'Interface list',
                'category' => 'network',
                'risk'     => 'LOW',
                'params'   => [],
                'build'    => fn(array $p) => '/sbin/ifconfig -a',
            ],

            // --- VPN ----------------------------------------------------------
            'vpn_status' => [
                'label'    => 'VPN status',
                'category' => 'network',
                'risk'     => 'LOW',
                'params'   => ['vpn_type' => ['type' => 'enum', 'values' => ['wireguard','openvpn','ipsec'], 'required' => true]],
                'build'    => function (array $p) {
                    return match ($p['vpn_type']) {
                        'wireguard' => '/usr/local/bin/wg show all',
                        'openvpn'   => '/usr/local/sbin/configctl openvpn status',
                        'ipsec'     => '/usr/local/sbin/configctl ipsec status',
                    };
                },
            ],
            'vpn_restart' => [
                'label'    => 'Restart VPN',
                'category' => 'network',
                'risk'     => 'HIGH',
                'params'   => ['vpn_type' => ['type' => 'enum', 'values' => ['wireguard','openvpn','ipsec'], 'required' => true]],
                'build'    => function (array $p) {
                    return match ($p['vpn_type']) {
                        'wireguard' => '/usr/local/sbin/configctl wireguard restart',
                        'openvpn'   => '/usr/local/sbin/configctl openvpn restart',
                        'ipsec'     => '/usr/local/sbin/configctl ipsec restart',
                    };
                },
            ],

            // --- configuration -------------------------------------------------
            'retrieve_config' => [
                'label'    => 'Retrieve configuration',
                'category' => 'backup',
                'risk'     => 'MEDIUM',
                'params'   => [],
                // The real work is done by the backup upload builder; this entry
                // exists so the action shows up in the catalogue and audit log.
                'build'    => fn(array $p) => '/bin/cat /conf/config.xml | /usr/bin/head -c 1',
            ],

            // --- agent ----------------------------------------------------------
            'agent_status' => [
                'label'    => 'Agent status',
                'category' => 'agent',
                'risk'     => 'LOW',
                'params'   => [],
                'build'    => fn(array $p) => '/usr/local/etc/rc.d/opnmanager_agent status 2>&1 || pgrep -lf opnmanager_agent',
            ],
            'agent_restart' => [
                'label'    => 'Restart agent',
                'category' => 'agent',
                'risk'     => 'MEDIUM',
                'params'   => [],
                'build'    => fn(array $p) => '/usr/local/etc/rc.d/opnmanager_agent restart',
            ],
        ];

        return $catalog;
    }
}

if (!function_exists('validate_action_params')) {
    /**
     * Validate and normalise parameters for a structured action.
     *
     * Rejects rather than sanitises: an out-of-range value is an error, not
     * something to silently clamp, so an operator is never surprised by a
     * command that did something other than what they asked for.
     *
     * @param string $action
     * @param array  $params
     * @return array{ok:bool, error:string, params:array}
     */
    function validate_action_params(string $action, array $params): array {
        $catalog = agent_command_catalog();
        if (!isset($catalog[$action])) {
            return ['ok' => false, 'error' => 'Unknown action', 'params' => []];
        }

        $spec  = $catalog[$action]['params'];
        $clean = [];

        // Reject unexpected parameters outright.
        foreach (array_keys($params) as $given) {
            if (!isset($spec[$given])) {
                return ['ok' => false, 'error' => "Unexpected parameter '{$given}'", 'params' => []];
            }
        }

        foreach ($spec as $name => $rule) {
            $present = array_key_exists($name, $params)
                && $params[$name] !== null
                && $params[$name] !== '';

            if (!$present) {
                if (!empty($rule['required'])) {
                    return ['ok' => false, 'error' => "Missing required parameter '{$name}'", 'params' => []];
                }
                continue;
            }

            $value = $params[$name];

            switch ($rule['type']) {
                case 'int':
                    if (!is_numeric($value) || (int)$value != $value) {
                        return ['ok' => false, 'error' => "Parameter '{$name}' must be an integer", 'params' => []];
                    }
                    $value = (int)$value;
                    if (isset($rule['min']) && $value < $rule['min']) {
                        return ['ok' => false, 'error' => "Parameter '{$name}' is below the minimum of {$rule['min']}", 'params' => []];
                    }
                    if (isset($rule['max']) && $value > $rule['max']) {
                        return ['ok' => false, 'error' => "Parameter '{$name}' exceeds the maximum of {$rule['max']}", 'params' => []];
                    }
                    break;

                case 'service':
                    if (!in_array($value, agent_service_allowlist(), true)) {
                        return ['ok' => false, 'error' => "Service '{$value}' is not in the allowed list", 'params' => []];
                    }
                    break;

                case 'enum':
                    if (!in_array($value, $rule['values'], true)) {
                        return [
                            'ok'     => false,
                            'error'  => "Parameter '{$name}' must be one of: " . implode(', ', $rule['values']),
                            'params' => [],
                        ];
                    }
                    break;

                case 'host':
                    $value = trim((string)$value);
                    $isIp   = filter_var($value, FILTER_VALIDATE_IP) !== false;
                    // Hostname: labels of alphanumerics and hyphens, max 253 chars.
                    $isHost = strlen($value) <= 253
                        && preg_match('/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))*$/', $value) === 1;
                    if (!$isIp && !$isHost) {
                        return ['ok' => false, 'error' => "Parameter '{$name}' is not a valid host or IP address", 'params' => []];
                    }
                    break;

                default:
                    return ['ok' => false, 'error' => "Unsupported parameter type for '{$name}'", 'params' => []];
            }

            $clean[$name] = $value;
        }

        return ['ok' => true, 'error' => '', 'params' => $clean];
    }
}

if (!function_exists('build_structured_command')) {
    /**
     * Turn a validated action into the shell text the agent will run.
     *
     * @return array{ok:bool, error:string, command:string, risk:string, label:string, params:array}
     */
    function build_structured_command(string $action, array $params): array {
        $validated = validate_action_params($action, $params);
        if (!$validated['ok']) {
            return ['ok' => false, 'error' => $validated['error'], 'command' => '',
                    'risk' => 'LOW', 'label' => '', 'params' => []];
        }

        $entry = agent_command_catalog()[$action];

        return [
            'ok'      => true,
            'error'   => '',
            'command' => ($entry['build'])($validated['params']),
            'risk'    => $entry['risk'],
            'label'   => $entry['label'],
            'params'  => $validated['params'],
        ];
    }
}

if (!function_exists('queue_firewall_command')) {
    /**
     * The only place a command is inserted into firewall_commands.
     *
     * Verifies the target firewall exists (so a manipulated id cannot queue
     * work against a firewall that is not there, or against the wrong one via
     * a stale reference), records the acting user, and writes an audit entry.
     *
     * @param int    $firewallId
     * @param string $command     Shell text
     * @param string $description Human-readable summary
     * @param array  $opts        action, parameters, is_raw, risk
     * @return array{ok:bool, error:string, command_id:int, hostname:string}
     */
    function queue_firewall_command(int $firewallId, string $command, string $description, array $opts = []): array {
        $isRaw = (bool)($opts['is_raw'] ?? true);
        $risk  = $opts['risk'] ?? ($isRaw ? 'CRITICAL' : 'MEDIUM');
        $action = $opts['action'] ?? null;

        if ($command === '') {
            return ['ok' => false, 'error' => 'Empty command', 'command_id' => 0, 'hostname' => ''];
        }

        $stmt = db()->prepare('SELECT id, hostname FROM firewalls WHERE id = ?');
        $stmt->execute([$firewallId]);
        $firewall = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$firewall) {
            audit_log('command.queue', [
                'success'     => false,
                'object_type' => 'firewall',
                'object_id'   => (string)$firewallId,
                'message'     => 'Attempt to queue a command against a firewall that does not exist',
            ]);
            return ['ok' => false, 'error' => 'Firewall not found', 'command_id' => 0, 'hostname' => ''];
        }

        $params = isset($opts['parameters']) && is_array($opts['parameters'])
            ? json_encode($opts['parameters'])
            : null;

        db()->prepare(
            'INSERT INTO firewall_commands
                (firewall_id, command, command_type, description, status,
                 action, parameters, is_raw, risk_level,
                 queued_by_user_id, queued_by_username, queued_from_ip)
             VALUES (?,?,?,?,"pending",?,?,?,?,?,?,?)'
        )->execute([
            $firewallId,
            $command,
            $isRaw ? 'shell' : 'structured',
            substr($description, 0, 255),
            $action,
            $params,
            $isRaw ? 1 : 0,
            $risk,
            $_SESSION['user_id']  ?? null,
            $_SESSION['username'] ?? null,
            audit_client_ip(),
        ]);

        $commandId = (int)db()->lastInsertId();

        audit_log($isRaw ? 'command.raw' : 'command.action', [
            'success'     => true,
            'object_type' => 'command',
            'object_id'   => (string)$commandId,
            'firewall_id' => $firewallId,
            'message'     => sprintf(
                '%s queued against %s: %s',
                $isRaw ? 'Raw shell command' : ('Action ' . $action),
                $firewall['hostname'],
                substr($description !== '' ? $description : $command, 0, 200)
            ),
            'metadata'    => [
                'action'     => $action,
                'is_raw'     => $isRaw,
                'risk'       => $risk,
                'parameters' => $opts['parameters'] ?? null,
                // The raw command text is recorded deliberately: the spec
                // requires the executed command to be in the audit log.
                'command'    => substr($command, 0, 2000),
            ],
        ]);

        return ['ok' => true, 'error' => '', 'command_id' => $commandId, 'hostname' => $firewall['hostname']];
    }
}

if (!function_exists('raw_commands_enabled')) {
    /**
     * Whether the raw shell path is available at all.
     *
     * An administrator can turn it off entirely without losing structured
     * operations.
     */
    function raw_commands_enabled(): bool {
        try {
            $stmt = db()->prepare('SELECT `value` FROM settings WHERE `name` = ?');
            $stmt->execute(['raw_command_enabled']);
            $v = $stmt->fetchColumn();
            return $v === false || (string)$v === '1';
        } catch (Throwable $e) {
            return true; // fail open on a settings read error, not closed
        }
    }
}

/**
 * Determine the real outcome of an install_updates command from its output.
 *
 * The agent reports every command as "completed" regardless of exit status, so
 * a failed upgrade is indistinguishable from a successful one at the transport
 * level. install_updates therefore echoes its own exit code, which this reads.
 *
 * @param string $result Decoded command output
 * @return array{known:bool, exit_code:?int, ok:bool}
 * @since 3.19.1
 */
function interpret_update_result(string $result): array {
    if (preg_match('/OPNMGR_UPDATE_EXIT=(\d{1,3})\b/', $result, $m)) {
        $code = (int)$m[1];
        return ['known' => true, 'exit_code' => $code, 'ok' => $code === 0];
    }
    // No marker: an older agent, or the box rebooted before it could report.
    // Say so rather than assuming the upgrade worked.
    return ['known' => false, 'exit_code' => null, 'ok' => false];
}
