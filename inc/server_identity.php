<?php
/**
 * Where this OPNManager installation lives.
 *
 * Agents fetch their plugin, upload backups and open tunnels against a URL the
 * manager hands them, and a firewall policy has to know which address to allow
 * in. Those values used to be typed into each call site as the maintainer's own
 * host - `opn.agit8or.net` and its public IP appeared in more than thirty files,
 * including the agent download URL and the enrolment script. A self-hosted
 * install therefore pointed its firewalls at somebody else's server, which is
 * both broken and a disclosure.
 *
 * Everything now resolves through here, from configuration, with no host of any
 * kind compiled in. If nothing is configured these return an empty string; the
 * callers treat that as "not configured" and say so, rather than quietly
 * emitting a URL that points somewhere wrong.
 *
 * @since 3.30.0
 */

if (!function_exists('opnmgr_instance_config')) {
    /** config/instance.json, or an empty array if it is missing or malformed. */
    function opnmgr_instance_config(): array
    {
        static $config = null;
        if ($config !== null) {
            return $config;
        }

        $config = [];
        $path = dirname(__DIR__) . '/config/instance.json';
        if (is_readable($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }
        return $config;
    }
}

if (!function_exists('opnmgr_server_url')) {
    /**
     * Base URL agents should call back to, without a trailing slash.
     *
     * Resolution order, most explicit first: the `server_url` setting, APP_URL
     * in .env, the `manager_fqdn` setting, `main_server` in instance.json, and
     * finally the host of the request being served. Returns '' if none of those
     * yield a usable URL.
     */
    function opnmgr_server_url(): string
    {
        static $url = null;
        if ($url !== null) {
            return $url;
        }

        $candidates = [];

        foreach (['server_url', 'manager_fqdn'] as $setting) {
            try {
                $stmt = db()->prepare('SELECT `value` FROM settings WHERE `name` = ?');
                $stmt->execute([$setting]);
                $candidates[$setting] = (string) ($stmt->fetchColumn() ?: '');
            } catch (Throwable $e) {
                $candidates[$setting] = '';
            }
        }

        $ordered = [
            $candidates['server_url'],
            (string) (getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? '')),
            $candidates['manager_fqdn'],
            (string) (opnmgr_instance_config()['main_server'] ?? ''),
            (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''),
        ];

        $url = '';
        foreach ($ordered as $candidate) {
            $candidate = opnmgr_normalise_server_url($candidate);
            if ($candidate !== '') {
                $url = $candidate;
                break;
            }
        }
        return $url;
    }
}

if (!function_exists('opnmgr_normalise_server_url')) {
    /**
     * Turn a configured value into a validated absolute URL.
     *
     * Settings are entered by hand and arrive as any of `example.com`,
     * `https://example.com/`, or empty. A bare host is assumed to be https,
     * since agents will not be asked to send credentials over plaintext.
     */
    function opnmgr_normalise_server_url(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('~^https?://~i', $value)) {
            $value = 'https://' . $value;
        }
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return '';
        }
        $host = parse_url($value, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }
        return rtrim($value, '/');
    }
}

if (!function_exists('opnmgr_server_host')) {
    /** Hostname agents connect to, without scheme or port. '' if not configured. */
    function opnmgr_server_host(): string
    {
        $host = parse_url(opnmgr_server_url(), PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }
}
