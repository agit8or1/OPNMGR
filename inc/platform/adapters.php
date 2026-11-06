<?php
/**
 * OPNMGR platform adapters.
 *
 * Everything that differs between OPNsense and pfSense goes through one of
 * these. Callers ask the adapter for "the shell that restarts unbound" or "is
 * policy application implemented here"; they never write `configctl` or
 * `pfSsh.php` themselves.
 *
 * An adapter answers null / "unsupported" rather than guessing. Commands that
 * exist only on the other product are listed in foreignTools(), which the
 * delivery gate in platform.php uses to stop legacy endpoints that build their
 * own shell text from bypassing this layer.
 *
 * Sources for the pfSense side (verified against pfsense/pfsense master, not
 * recalled): globals.inc (xml_rootobj 'pfsense', /etc/version,
 * globals.plus.inc on Plus), config.lib.inc (config_restore(), lock()),
 * phpshellsessions/svc and gatewaystatus, service-utils.inc.
 *
 * @since 3.79.0
 */

interface OpnmgrPlatformAdapter {
    /** opnsense | pfsense | unknown */
    public function family(): string;
    public function label(?string $edition = null): string;
    /** Root element of this platform's config.xml. */
    public function configRoot(): ?string;
    /** Absolute path of the live configuration on the firewall. */
    public function configPath(): ?string;
    /** Agent package flavour expected on this platform. */
    public function agentFlavor(): ?string;
    /** Services a structured action may target. */
    public function serviceAllowlist(): array;
    /**
     * Shell for a catalogue action, or null when not implemented here.
     * $params have already been validated against the catalogue.
     */
    public function buildAction(string $action, array $params): ?string;
    /** Why buildAction() returned null for an action, for the UI. */
    public function unsupportedReason(string $action): string;
    /**
     * Implementation status of a capability on this platform, before
     * validation and freshness are considered.
     * @return array{implemented:bool, reason:string, requires_probe?:string}
     */
    public function capabilityImplementation(string $capability, array $firewall): array;
    /** Command-text fragments that only exist on the OTHER product. */
    public function foreignTools(): array;
    /**
     * Shell lines that install $tmpVar (a shell variable holding the path of
     * a validated config file) as the running configuration using the
     * platform's own locked restore mechanism, then reload. Exit non-zero on
     * failure. Null when not implemented.
     */
    public function installConfigShell(string $tmpVar, string $reason): ?string;
}

abstract class OpnmgrBaseAdapter implements OpnmgrPlatformAdapter {
    public function unsupportedReason(string $action): string {
        return sprintf('"%s" is not implemented for %s', $action, $this->label());
    }

    /** Actions that behave identically on both products (FreeBSD base tools). */
    protected function commonAction(string $action, array $p): ?string {
        return match ($action) {
            'reboot' => sprintf('/sbin/shutdown -r +%d "Reboot requested via OPNManager"', $p['delay_minutes'] ?? 1),
            'ping' => sprintf('/sbin/ping -c %d -t 10 %s', $p['count'] ?? 4, escapeshellarg($p['target'])),
            'traceroute' => '/usr/sbin/traceroute -w 2 -q 1 -m 20 ' . escapeshellarg($p['target']),
            'dns_lookup' => sprintf('/usr/bin/drill %s %s', escapeshellarg($p['target']), escapeshellarg($p['type'] ?? 'A')),
            'interface_list' => '/sbin/ifconfig -a',
            'vpn_probe' => self::vpnProbeShell($p),
            default => null,
        };
    }

    /**
     * Shell for one bounded VPN probe. FreeBSD base tools only, so it is the
     * same on OPNsense and pfSense. Every value is escapeshellarg()'d; the
     * numeric ones are already range-checked by validate_action_params().
     * The exit status is echoed because the agent reports every command as
     * completed regardless of how it ended.
     *
     * @param array $p method (icmp|tcp|udp|traceroute), target, source?, port?, size?
     */
    public static function vpnProbeShell(array $p): string {
        $src = !empty($p['source']) ? ' -s ' . escapeshellarg($p['source']) : '';
        $dst = escapeshellarg($p['target']);
        $cmd = match ($p['method']) {
            'icmp' => '/sbin/ping -c 3 -t 10'
                    . (!empty($p['source']) ? ' -S ' . escapeshellarg($p['source']) : '')
                    . (isset($p['size']) ? ' -D -s ' . (int)$p['size'] : '') . ' ' . $dst,
            'tcp' => '/usr/bin/nc -z -w 3' . $src . ' ' . $dst . ' ' . (int)($p['port'] ?? 0),
            'udp' => '/usr/bin/nc -z -u -w 3' . $src . ' ' . $dst . ' ' . (int)($p['port'] ?? 0),
            default => '/usr/sbin/traceroute -w 2 -q 1 -m 20' . $src . ' ' . $dst,
        };
        return $cmd . '; echo "OPNMGR_VPN_PROBE_EXIT=$?"';
    }
}

final class OpnmgrOpnsenseAdapter extends OpnmgrBaseAdapter {
    public function family(): string { return 'opnsense'; }
    public function label(?string $edition = null): string { return 'OPNsense'; }
    public function configRoot(): ?string { return 'opnsense'; }
    public function configPath(): ?string { return '/conf/config.xml'; }
    public function agentFlavor(): ?string { return 'opnsense-plugin'; }

    public function serviceAllowlist(): array {
        return [
            'unbound', 'dnsmasq', 'dhcpd', 'kea-dhcp4', 'kea-dhcp6',
            'openvpn', 'ipsec', 'strongswan', 'wireguard', 'frr',
            'nginx', 'lighttpd', 'openssh', 'sshd', 'ntpd', 'chronyd',
            'syslog-ng', 'cron', 'configd', 'suricata', 'zabbix-agent',
            'haproxy', 'squid', 'radvd', 'miniupnpd', 'telegraf',
            'monit', 'netdata', 'collectd', 'apcupsd', 'pf', 'filter',
        ];
    }

    public function buildAction(string $action, array $p): ?string {
        $common = $this->commonAction($action, $p);
        if ($common !== null) {
            return $common;
        }
        // The OPNsense builds are the long-standing catalogue entries in
        // inc/agent_commands.php; returning null here would hide them.
        $catalog = agent_command_catalog();
        if (!isset($catalog[$action])) {
            return null;
        }
        return ($catalog[$action]['build'])($p);
    }

    public function capabilityImplementation(string $capability, array $firewall): array {
        return match ($capability) {
            'firmware.install' => ['implemented' => true, 'reason' => 'opnsense-update -bkp'],
            'config.restore'   => ['implemented' => true, 'reason' => 'OPNsense\\Core\\Config::restoreBackup() then rc.reload_all'],
            'policy.apply'     => ['implemented' => true, 'reason' => 'Full-config install through Config::restoreBackup() with a local recovery timer',
                                   'requires_probe' => 'opnsense_config_api'],
            'remote.tunnel'    => ['implemented' => true, 'reason' => 'On-demand reverse SSH tunnel'],
            default            => ['implemented' => true, 'reason' => ''],
        };
    }

    public function foreignTools(): array {
        return ['pfSsh.php', 'pfSense-upgrade', '/etc/inc/globals.inc', 'opnmgr_agent.sh', '/cf/conf/'];
    }

    public function installConfigShell(string $tmpVar, string $reason): ?string {
        // Config::restoreBackup() takes the config lock, validates the file
        // and falls back to the previous config if loading it fails. It is
        // what OPNsense's own backup restore uses; there is no configd action
        // for it (`configctl firmware restore` does not exist in core, which
        // is why the pre-3.79 restore command could never have worked).
        $php = 'require_once "script/load_phalcon.php";'
             . '$f = getenv("OPNMGR_CFG");'
             . '$ok = OPNsense\\Core\\Config::getInstance()->restoreBackup($f);'
             . 'echo $ok ? "OPNMGR_INSTALL=ok\\n" : "OPNMGR_INSTALL=rejected\\n";'
             . 'exit($ok ? 0 : 3);';
        return 'cd /usr/local/opnsense/mvc || exit 4' . "\n"
             . 'OPNMGR_CFG="$' . $tmpVar . '" /usr/local/bin/php -r ' . escapeshellarg($php) . ' || exit $?' . "\n"
             . '/usr/local/etc/rc.reload_all >/dev/null 2>&1 &' . "\n";
    }
}

final class OpnmgrPfsenseAdapter extends OpnmgrBaseAdapter {
    public function family(): string { return 'pfsense'; }
    public function label(?string $edition = null): string {
        return match ($edition) {
            'plus'      => 'pfSense Plus',
            'community' => 'pfSense CE',
            default     => 'pfSense',
        };
    }
    public function configRoot(): ?string { return 'pfsense'; }
    public function configPath(): ?string { return '/cf/conf/config.xml'; }
    public function agentFlavor(): ?string { return 'pfsense-agent'; }

    /** Service names as pfSense's service-utils.inc knows them. */
    public function serviceAllowlist(): array {
        return [
            'unbound', 'dnsmasq', 'dhcpd', 'kea-dhcp4', 'kea-dhcp6', 'dhcrelay',
            'ipsec', 'wireguard', 'ntpd', 'sshd', 'syslogd', 'dpinger',
            'radvd', 'miniupnpd', 'haproxy', 'squid', 'frr', 'bsnmpd',
        ];
    }

    private const SVC = '/usr/local/sbin/pfSsh.php playback svc ';

    public function buildAction(string $action, array $p): ?string {
        $common = $this->commonAction($action, $p);
        if ($common !== null) {
            return $common;
        }
        return match ($action) {
            'service_status'  => self::SVC . 'status '  . escapeshellarg($p['service']),
            'service_restart' => self::SVC . 'restart ' . escapeshellarg($p['service']),
            'service_start'   => self::SVC . 'start '   . escapeshellarg($p['service']),
            'service_stop'    => self::SVC . 'stop '    . escapeshellarg($p['service']),
            'gateway_status'  => '/usr/local/sbin/pfSsh.php playback gatewaystatus',
            'check_updates'   => '/usr/local/sbin/pfSense-upgrade -c 2>&1; echo "OPNMGR_UPDATE_CHECK_EXIT=$?"',
            'vpn_status'      => match ($p['vpn_type']) {
                'wireguard' => '/usr/local/bin/wg show all',
                'ipsec'     => '/usr/local/sbin/swanctl --list-sas',
                // pfSense has no configctl; each OpenVPN instance exposes a
                // management socket under /var/etc/openvpn/<instance>/sock.
                'openvpn'   => 'for s in /var/etc/openvpn/*/sock; do [ -S "$s" ] || continue; '
                             . 'echo "== $s"; printf "status 3\nquit\n" | /usr/bin/nc -U -w 3 "$s"; done',
            },
            'vpn_restart' => match ($p['vpn_type']) {
                'wireguard' => self::SVC . 'restart wireguard',
                'ipsec'     => self::SVC . 'restart ipsec',
                default     => null,
            },
            'retrieve_config' => '/bin/cat /cf/conf/config.xml | /usr/bin/head -c 1',
            'agent_status'    => '/usr/local/etc/rc.d/opnmgr_agent.sh status 2>&1 || pgrep -lf opnmgr_agent',
            'agent_restart'   => '/usr/local/etc/rc.d/opnmgr_agent.sh restart',
            'agent_install_verify' =>
                'printf \'OPNMGR_INSTALL_PROBE_V1\\n\'; '
              . 'for f in /usr/local/etc/rc.d/opnmgr_agent.sh /usr/local/opnmgr/opnmgr_agent.sh '
              . '/usr/local/opnmgr/opnmgr_health.php; do '
              . 'if [ -f "$f" ]; then printf \'%s=present\\n\' "$f"; '
              . 'else printf \'%s=MISSING\\n\' "$f"; fi; done',
            default => null,
        };
    }

    public function unsupportedReason(string $action): string {
        return match ($action) {
            'install_updates' => 'Firmware installation on pfSense is not implemented: pfSense-upgrade is '
                . 'distributed outside the pfSense source tree and its non-interactive flags have not been '
                . 'verified against a lab device. Use the pfSense GUI (System > Update).',
            'vpn_restart' => 'Restarting OpenVPN on pfSense needs the instance id (svc restart openvpn server <id>); '
                . 'whole-service restart is not offered.',
            default => parent::unsupportedReason($action),
        };
    }

    public function capabilityImplementation(string $capability, array $firewall): array {
        return match ($capability) {
            'firmware.install' => ['implemented' => false, 'reason' => $this->unsupportedReason('install_updates')],
            'config.restore'   => ['implemented' => true,
                                   'reason' => 'config_restore() from config.lib.inc (takes the config lock, keeps a backup)',
                                   'requires_probe' => 'pfsense_config_restore'],
            'policy.apply'     => ['implemented' => true,
                                   'reason' => 'Full-config install through config_restore() with a local recovery timer',
                                   'requires_probe' => 'pfsense_config_restore'],
            'remote.tunnel'    => ['implemented' => false,
                                   'reason' => 'The on-demand SSH tunnel installs pf rules through an OPNsense syshook; '
                                             . 'no pfSense equivalent has been written.'],
            'agent.update'     => ['implemented' => true, 'reason' => 'pfSense agent installer re-run'],
            default            => ['implemented' => true, 'reason' => ''],
        };
    }

    public function foreignTools(): array {
        return ['configctl', 'opnsense-update', 'opnsense-version', 'pluginctl',
                '/usr/local/opnsense/', 'os-opnmanager-agent', 'install_opnmanager_agent.sh',
                'rc.d/opnmanager_agent', 'rc.syshook.d'];
    }

    public function installConfigShell(string $tmpVar, string $reason): ?string {
        // config_restore() (config.lib.inc) backs up the current config, takes
        // the 'config' lock, replaces /cf/conf/config.xml, drops the cache and
        // writes it back through write_config(). Reloading afterwards is the
        // same rc script the GUI's restore uses.
        $php = 'require_once("config.inc"); require_once("config.lib.inc");'
             . 'if (!function_exists("config_restore")) { echo "OPNMGR_INSTALL=unsupported\\n"; exit(5); }'
             . '$ok = config_restore(getenv("OPNMGR_CFG"), ' . var_export(substr($reason, 0, 120), true) . ');'
             . 'echo $ok ? "OPNMGR_INSTALL=ok\\n" : "OPNMGR_INSTALL=rejected\\n";'
             . 'exit($ok ? 0 : 3);';
        return 'OPNMGR_CFG="$' . $tmpVar . '" /usr/local/bin/php -r ' . escapeshellarg($php) . ' || exit $?' . "\n"
             . '/etc/rc.reload_all >/dev/null 2>&1 &' . "\n";
    }
}

/** Unknown or conflicting evidence: read-only, and only base FreeBSD tools. */
final class OpnmgrUnknownAdapter extends OpnmgrBaseAdapter {
    public function family(): string { return 'unknown'; }
    public function label(?string $edition = null): string { return 'Unknown platform'; }
    public function configRoot(): ?string { return null; }
    public function configPath(): ?string { return null; }
    public function agentFlavor(): ?string { return null; }
    public function serviceAllowlist(): array { return []; }

    public function buildAction(string $action, array $p): ?string {
        // Only the read-only base-system diagnostics. reboot is in
        // commonAction() but is a write and is refused here.
        if (in_array($action, ['ping', 'traceroute', 'dns_lookup', 'interface_list'], true)) {
            return $this->commonAction($action, $p);
        }
        return null;
    }

    public function unsupportedReason(string $action): string {
        return 'The platform of this firewall has not been verified, so it is read-only. '
             . 'It will be unlocked once the agent reports its platform.';
    }

    public function capabilityImplementation(string $capability, array $firewall): array {
        return ['implemented' => false, 'reason' => $this->unsupportedReason($capability)];
    }

    public function foreignTools(): array { return []; }
    public function installConfigShell(string $tmpVar, string $reason): ?string { return null; }
}
