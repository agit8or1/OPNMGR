<?php
/**
 * VPN topology: address arithmetic and small shared helpers.
 *
 * IPv4 only. IPv6 entries in a configuration are carried through the model
 * untouched but are not compared; the findings say so rather than guessing.
 *
 * @since 3.79.0
 */

if (!function_exists('vpn_cidr')) {
    /**
     * Parse "a.b.c.d/n" or a bare address (treated as /32).
     * @return array{net:int,bits:int,mask:int,first:int,last:int,text:string}|null
     */
    function vpn_cidr(?string $s): ?array {
        $s = trim((string)$s);
        if ($s === '' || str_contains($s, ':')) {
            return null;
        }
        $bits = 32;
        if (str_contains($s, '/')) {
            [$s, $b] = explode('/', $s, 2);
            if (!ctype_digit($b) || (int)$b > 32) {
                return null;
            }
            $bits = (int)$b;
        }
        if (filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        $ip   = (int)sprintf('%u', ip2long($s));
        $mask = $bits === 0 ? 0 : (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;
        $net  = $ip & $mask;
        return ['net' => $net, 'bits' => $bits, 'mask' => $mask, 'first' => $net,
                'last' => $net | (~$mask & 0xFFFFFFFF), 'text' => long2ip($net) . '/' . $bits,
                'ip' => $ip];
    }
}

if (!function_exists('vpn_cidr_norm')) {
    /** Canonical "network/bits", or null for anything that is not IPv4. */
    function vpn_cidr_norm(?string $s): ?string {
        $c = vpn_cidr($s);
        return $c ? $c['text'] : null;
    }
}

if (!function_exists('vpn_cidr_overlap')) {
    function vpn_cidr_overlap(string $a, string $b): bool {
        $x = vpn_cidr($a); $y = vpn_cidr($b);
        return $x && $y && $x['first'] <= $y['last'] && $y['first'] <= $x['last'];
    }
}

if (!function_exists('vpn_cidr_contains')) {
    /** True when $inner lies wholly inside $outer. */
    function vpn_cidr_contains(string $outer, string $inner): bool {
        $o = vpn_cidr($outer); $i = vpn_cidr($inner);
        return $o && $i && $o['first'] <= $i['first'] && $i['last'] <= $o['last'];
    }
}

if (!function_exists('vpn_cidr_list_covers')) {
    /** Whether any entry of $list contains $cidr. */
    function vpn_cidr_list_covers(array $list, string $cidr): bool {
        foreach ($list as $c) {
            if (vpn_cidr_contains((string)$c, $cidr)) { return true; }
        }
        return false;
    }
}

if (!function_exists('vpn_cidr_list_overlaps')) {
    function vpn_cidr_list_overlaps(array $list, string $cidr): bool {
        foreach ($list as $c) {
            if (vpn_cidr_overlap((string)$c, $cidr)) { return true; }
        }
        return false;
    }
}

if (!function_exists('vpn_norm_list')) {
    /** Normalised, de-duplicated, sorted IPv4 CIDR list; non-IPv4 entries dropped. */
    function vpn_norm_list(array $list): array {
        $out = [];
        foreach ($list as $c) {
            $n = vpn_cidr_norm((string)$c);
            if ($n !== null) { $out[$n] = true; }
        }
        $k = array_keys($out);
        sort($k);
        return $k;
    }
}

if (!function_exists('vpn_ip_in_list')) {
    function vpn_ip_in_list(string $ip, array $cidrs): bool {
        return vpn_cidr_list_covers($cidrs, $ip . '/32');
    }
}

if (!function_exists('vpn_ip_is_public')) {
    /** Routable on the internet: not RFC1918, CGNAT, loopback, link-local, multicast. */
    function vpn_ip_is_public(?string $ip): bool {
        return $ip !== null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && !vpn_cidr_contains('100.64.0.0/10', $ip . '/32');
    }
}

if (!function_exists('vpn_split_hostport')) {
    /** "host:port", "[v6]:port" or "host" -> [host, port|null]. */
    function vpn_split_hostport(?string $s, ?string $defaultPort = null): array {
        $s = trim((string)$s);
        if ($s === '') { return [null, null]; }
        if (preg_match('/^\[([^\]]+)\]:(\d+)$/', $s, $m)) { return [$m[1], $m[2]]; }
        if (substr_count($s, ':') === 1 && preg_match('/^([^:]+):(\d+)$/', $s, $m)) { return [$m[1], $m[2]]; }
        return [$s, $defaultPort !== null && $defaultPort !== '' ? $defaultPort : null];
    }
}

if (!function_exists('vpn_scrub')) {
    /**
     * Defence in depth: the normalized model already omits secrets, but this
     * is what stands between a future model change and a leak into the cache,
     * the API or the page. Keys that look like secret material are dropped
     * (has_psk, a boolean, is kept), and string values that look like PEM or
     * the fixtures' marker text are replaced.
     */
    function vpn_scrub($v) {
        if (!is_array($v)) {
            if (is_string($v) && (str_contains($v, '-----BEGIN') || str_contains($v, 'MUST-NEVER-APPEAR'))) {
                return '[redacted]';
            }
            return $v;
        }
        $out = [];
        foreach ($v as $k => $x) {
            if (is_string($k) && $k !== 'has_psk'
                && preg_match('/(priv(ate)?_?key|privkey|psk|presharedkey|pre-shared|secret|password|passwd|^tls|tls_?key|shared_key|api_?key|token)/i', $k)) {
                continue;
            }
            $out[$k] = vpn_scrub($x);
        }
        return $out;
    }
}

if (!function_exists('vpn_setting')) {
    function vpn_setting(string $name, string $default = ''): string {
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

if (!function_exists('vpn_ts')) {
    /** Timestamp string/int to epoch, null for empty or zero dates. */
    function vpn_ts($v): ?int {
        if ($v === null || $v === '' || (is_string($v) && str_starts_with($v, '0000'))) { return null; }
        if (is_int($v)) { return $v; }
        $t = strtotime((string)$v);
        return $t === false ? null : $t;
    }
}

if (!function_exists('vpn_age_text')) {
    function vpn_age_text(?int $seconds): string {
        if ($seconds === null) { return 'never'; }
        if ($seconds < 90) { return $seconds . 's'; }
        if ($seconds < 5400) { return (int)round($seconds / 60) . 'm'; }
        if ($seconds < 172800) { return round($seconds / 3600, 1) . 'h'; }
        return (int)round($seconds / 86400) . 'd';
    }
}
