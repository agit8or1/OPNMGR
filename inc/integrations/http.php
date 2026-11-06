<?php
/**
 * Integrations: outbound HTTP with SSRF, redirect and DNS-rebinding defences.
 *
 * Every connector request goes through integration_http_request():
 *   - the URL is validated (scheme, no userinfo, sane port);
 *   - the host is resolved ONCE here, every address is checked against the
 *     blocklist, and the chosen address is pinned with CURLOPT_RESOLVE so curl
 *     cannot resolve again to something else (DNS rebinding);
 *   - redirects are never followed (a 3xx is a permanent failure);
 *   - the response body is capped.
 * Private, loopback, link-local, CGNAT, multicast and metadata ranges are
 * refused unless the destination explicitly allows internal endpoints.
 *
 * @since 3.79.0
 */

if (!function_exists('integration_ip_block_reason')) {
    /** Why an IP must not be contacted by default, or null if it is public. */
    function integration_ip_block_reason(string $ip): ?string {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { return 'not an IP address'; }
        $bin = inet_pton($ip);
        if ($bin === false) { return 'not an IP address'; }
        if (strlen($bin) === 16) {
            // IPv4-mapped (::ffff:a.b.c.d) and NAT64 (64:ff9b::/96): judge the embedded v4.
            if (substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff"
                || substr($bin, 0, 12) === "\x00\x64\xff\x9b\0\0\0\0\0\0\0\0") {
                return integration_ip_block_reason(inet_ntop(substr($bin, 12)));
            }
            $v6 = [['::', 128, 'unspecified'], ['::1', 128, 'loopback'], ['fe80::', 10, 'link-local'],
                   ['fc00::', 7, 'unique-local'], ['ff00::', 8, 'multicast'], ['fd00:ec2::254', 128, 'cloud metadata'],
                   ['2001:db8::', 32, 'documentation'], ['100::', 64, 'discard-only']];
            foreach ($v6 as [$net, $bits, $why]) {
                if (integration_cidr_match($bin, inet_pton($net), $bits)) { return $why; }
            }
            return null;
        }
        $v4 = [['0.0.0.0', 8, 'unspecified'], ['10.0.0.0', 8, 'private'], ['100.64.0.0', 10, 'carrier-grade NAT'],
               ['127.0.0.0', 8, 'loopback'], ['169.254.0.0', 16, 'link-local / cloud metadata'],
               ['172.16.0.0', 12, 'private'], ['192.0.0.0', 24, 'reserved'], ['192.0.2.0', 24, 'documentation'],
               ['192.168.0.0', 16, 'private'], ['198.18.0.0', 15, 'benchmarking'], ['198.51.100.0', 24, 'documentation'],
               ['203.0.113.0', 24, 'documentation'], ['224.0.0.0', 4, 'multicast'], ['240.0.0.0', 4, 'reserved']];
        foreach ($v4 as [$net, $bits, $why]) {
            if (integration_cidr_match($bin, inet_pton($net), $bits)) { return $why; }
        }
        return null;
    }
}

if (!function_exists('integration_cidr_match')) {
    function integration_cidr_match(string $bin, string $net, int $bits): bool {
        if (strlen($bin) !== strlen($net)) { return false; }
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($bin, 0, $bytes) !== substr($net, 0, $bytes)) { return false; }
        $rem = $bits % 8;
        if ($rem === 0) { return true; }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($bin[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }
}

if (!function_exists('integration_resolve_host')) {
    /** All A/AAAA addresses for a host (literal IPs returned as-is). Overridable in tests via $GLOBALS. */
    function integration_resolve_host(string $host): array {
        if (isset($GLOBALS['OPNMGR_INTEGRATION_RESOLVER']) && is_callable($GLOBALS['OPNMGR_INTEGRATION_RESOLVER'])) {
            return ($GLOBALS['OPNMGR_INTEGRATION_RESOLVER'])($host);
        }
        $h = trim($host, '[]');
        if (filter_var($h, FILTER_VALIDATE_IP)) { return [$h]; }
        $ips = [];
        foreach (@dns_get_record($host, DNS_A) ?: [] as $r) { if (!empty($r['ip'])) { $ips[] = $r['ip']; } }
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) { if (!empty($r['ipv6'])) { $ips[] = $r['ipv6']; } }
        if (!$ips) { $l = @gethostbynamel($host); if ($l) { $ips = $l; } }
        return array_values(array_unique($ips));
    }
}

if (!function_exists('integration_validate_url')) {
    /**
     * Static URL checks. Returns [parts|null, error].
     * http:// is accepted only for internal endpoints.
     */
    function integration_validate_url(string $url, bool $allowInternal): array {
        $p = parse_url($url);
        if ($p === false || empty($p['host']) || empty($p['scheme'])) { return [null, 'invalid URL']; }
        $scheme = strtolower($p['scheme']);
        if (!in_array($scheme, ['https', 'http'], true)) { return [null, 'only http(s) URLs are allowed']; }
        if ($scheme === 'http' && !$allowInternal) { return [null, 'plain http is only allowed for internal endpoints']; }
        if (isset($p['user']) || isset($p['pass'])) { return [null, 'credentials in the URL are not allowed']; }
        if (preg_match('/[\x00-\x20]/', $url)) { return [null, 'invalid characters in URL']; }
        $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) { return [null, 'invalid port']; }
        $p['scheme'] = $scheme;
        $p['port'] = $port;
        return [$p, ''];
    }
}

if (!function_exists('integration_pin_target')) {
    /**
     * Resolve once and pick the address to pin.
     * @return array{ok:bool, ip:string, parts:?array, error:string, blocked:bool}
     */
    function integration_pin_target(string $url, bool $allowInternal): array {
        [$parts, $err] = integration_validate_url($url, $allowInternal);
        if (!$parts) { return ['ok' => false, 'ip' => '', 'parts' => null, 'error' => $err, 'blocked' => true]; }
        $ips = integration_resolve_host($parts['host']);
        if (!$ips) { return ['ok' => false, 'ip' => '', 'parts' => $parts, 'error' => 'host did not resolve', 'blocked' => false]; }
        foreach ($ips as $ip) {
            $why = integration_ip_block_reason($ip);
            if ($why === null || $allowInternal) {
                // Per-delivery hook: records/blocks changes of the resolved address.
                if (!empty($GLOBALS['OPNMGR_INTEGRATION_IP_GUARD'])) {
                    $g = ($GLOBALS['OPNMGR_INTEGRATION_IP_GUARD'])($parts['host'], $ip);
                    if ($g) { return ['ok' => false, 'ip' => $ip, 'parts' => $parts, 'error' => $g, 'blocked' => false]; }
                }
                return ['ok' => true, 'ip' => $ip, 'parts' => $parts, 'error' => '', 'blocked' => false];
            }
        }
        $why = integration_ip_block_reason($ips[0]);
        return ['ok' => false, 'ip' => $ips[0], 'parts' => $parts, 'blocked' => true,
                'error' => "target address {$ips[0]} is blocked ({$why}); enable \"allow internal endpoint\" on the destination if intended"];
    }
}

if (!function_exists('integration_http_request')) {
    /**
     * @param array $opt method, headers (list of "K: V"), body, timeout, allow_internal, max_bytes
     * @return array{ok:bool, status:int, body:string, headers:array, ip:string, error:string,
     *               blocked:bool, sent:bool, retry_after:?int, duration_ms:int}
     *   sent=false means curl never completed a connection, so the request cannot have been processed.
     */
    function integration_http_request(string $url, array $opt = []): array {
        $r = ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'ip' => '', 'error' => '',
              'blocked' => false, 'sent' => false, 'retry_after' => null, 'duration_ms' => 0];
        $t0 = microtime(true);
        $allow = !empty($opt['allow_internal']);
        $pin = integration_pin_target($url, $allow);
        $r['ip'] = $pin['ip'];
        if (!$pin['ok']) {
            $r['error'] = $pin['error']; $r['blocked'] = $pin['blocked'];
            return $r;
        }
        $parts = $pin['parts'];
        $ipLit = str_contains($pin['ip'], ':') ? '[' . $pin['ip'] . ']' : $pin['ip'];
        $max = (int)($opt['max_bytes'] ?? 65536);
        $body = '';
        $hdrs = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $opt['method'] ?? 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => 0,
            CURLOPT_RESOLVE        => [$parts['host'] . ':' . $parts['port'] . ':' . $ipLit],
            CURLOPT_CONNECTTIMEOUT => min(10, (int)($opt['timeout'] ?? 10)),
            CURLOPT_TIMEOUT        => (int)($opt['timeout'] ?? 10),
            CURLOPT_HTTPHEADER     => $opt['headers'] ?? [],
            CURLOPT_USERAGENT      => 'OPNManager-Integrations/1',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY          => '',
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_WRITEFUNCTION  => function ($c, $chunk) use (&$body, $max) {
                if (strlen($body) < $max) { $body .= substr($chunk, 0, $max - strlen($body)); }
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$hdrs) {
                if (strpos($line, ':') !== false) {
                    [$k, $v] = explode(':', $line, 2);
                    $hdrs[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        if (isset($opt['body']) && $opt['body'] !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['body']); }
        curl_exec($ch);
        $errno = curl_errno($ch);
        $r['status'] = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $r['error'] = $errno ? curl_error($ch) : '';
        // The request may have been processed unless we failed before/at connect.
        // 3 malformed URL, 6 resolve, 7 connect, 35 TLS handshake, 51/60 certificate verification failed.
        $preConnect = in_array($errno, [3, 6, 7, 35, 51, 60], true);
        $r['sent'] = !$preConnect;
        curl_close($ch);
        $r['body'] = $body; $r['headers'] = $hdrs;
        if (isset($hdrs['retry-after'])) {
            $ra = $hdrs['retry-after'];
            $r['retry_after'] = ctype_digit($ra) ? (int)$ra : (($ts = strtotime($ra)) ? max(0, $ts - time()) : null);
        }
        $r['ok'] = ($errno === 0 && $r['status'] >= 200 && $r['status'] < 300);
        if ($errno === 0 && $r['status'] >= 300 && $r['status'] < 400) { $r['error'] = 'redirect not followed (HTTP ' . $r['status'] . ')'; }
        $r['duration_ms'] = (int)round((microtime(true) - $t0) * 1000);
        return $r;
    }
}
