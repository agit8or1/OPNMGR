<?php
/**
 * One place that decides how this manager connects to a firewall over SSH.
 *
 * Every call site disabled strict host key checking. That accepts an unknown host
 * silently, which means first contact with any firewall is trusted blindly - and
 * this manager holds root keys to every firewall in the fleet, so first contact
 * is exactly when it matters. It also handles a *changed* key badly in a way
 * that is hard to diagnose: ssh connects but refuses port forwarding, which
 * surfaced as "Proxy Error: Failed to connect to 127.0.0.1:8101" with no mention
 * of host keys anywhere.
 *
 * accept-new keeps first contact working without a manual step, and refuses a
 * changed key outright rather than half-connecting. Where a firewall's keys have
 * been pinned in advance - see scripts/pin_host_keys.php, which reads them from
 * the firewall over the agent's signed channel rather than over SSH - even first
 * contact is verified.
 *
 * @since 3.60.0
 */

if (!defined('OPNMGR_KNOWN_HOSTS')) {
    // Beside the firewall keys, and owned by the web server user for the same
    // reason: every SSH caller in the product runs as www-data.
    define('OPNMGR_KNOWN_HOSTS', '/etc/opnmgr/known_hosts');
}

if (!function_exists('opnmgr_known_hosts_file')) {
    /**
     * The managed known_hosts path, created if absent.
     *
     * Returns '' when it cannot be created, and callers then fall back to the
     * user default rather than failing the connection: a manager that cannot
     * write this file should still be able to reach its fleet.
     */
    function opnmgr_known_hosts_file(): string
    {
        $path = OPNMGR_KNOWN_HOSTS;
        if (is_file($path)) {
            return is_readable($path) ? $path : '';
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            error_log('OPNMGR: cannot create ' . $dir . ' for known_hosts');
            return '';
        }
        if (@touch($path)) {
            @chmod($path, 0640);
            return $path;
        }

        error_log('OPNMGR: cannot create ' . $path);
        return '';
    }
}

if (!function_exists('opnmgr_ssh_options')) {
    /**
     * Standard ssh(1) options for reaching a firewall, as a shell fragment.
     *
     * @param array<int,string> $extra Additional -o options, already shell-safe.
     */
    function opnmgr_ssh_options(array $extra = []): string
    {
        $opts = [
            '-o StrictHostKeyChecking=accept-new',
            '-o BatchMode=yes',
        ];

        $kh = opnmgr_known_hosts_file();
        if ($kh !== '') {
            $opts[] = '-o UserKnownHostsFile=' . escapeshellarg($kh);
        }

        return implode(' ', array_merge($opts, $extra));
    }
}

if (!function_exists('opnmgr_host_key_pinned')) {
    /**
     * Whether this host already has a pinned key, i.e. whether the next
     * connection is verified rather than trusted on first use.
     */
    function opnmgr_host_key_pinned(string $host): bool
    {
        $kh = opnmgr_known_hosts_file();
        if ($kh === '') {
            return false;
        }
        $out = [];
        $rc  = 0;
        exec('ssh-keygen -F ' . escapeshellarg($host) . ' -f ' . escapeshellarg($kh) . ' 2>/dev/null', $out, $rc);
        return $rc === 0 && $out !== [];
    }
}
