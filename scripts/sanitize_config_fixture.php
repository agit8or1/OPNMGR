<?php
/**
 * Turn a real OPNsense/pfSense config.xml into a fixture safe to publish.
 *
 *   php scripts/sanitize_config_fixture.php in.xml out.xml
 *
 * Structure is what the tests need - element names, nesting, rule order, how
 * many of each thing exist - so that is kept. Everything that identifies or
 * protects the source is replaced:
 *
 *   - secrets (keys, PSKs, password hashes, API keys, TLS, SNMP communities)
 *     become "REDACTED"; certificate and CA bodies are emptied
 *   - public IPv4/IPv6 addresses map to documentation ranges (TEST-NET-3,
 *     2001:db8::/32), consistently, so the same address stays the same
 *   - domain names map to example.net names, consistently
 *   - MAC addresses, e-mail addresses and free-text descriptions are replaced
 *   - WireGuard public keys are replaced with stable fake keys, so peer
 *     matching still works across fixtures generated in one run
 *
 * Words that identify the source but are not domains (an organisation name in
 * an alias, a hostname) are passed in OPNMGR_FIXTURE_REDACT_WORDS, comma
 * separated, and replaced wherever they appear.
 *
 * RFC1918 addresses are kept: overlap and routing tests depend on them and
 * they identify nothing. Review the output before committing it anyway.
 */

require_once __DIR__ . '/../inc/cli_guard.php';
opnmgr_block_direct_web_access(__FILE__);

if ($argc < 3) {
    fwrite(STDERR, "usage: sanitize_config_fixture.php in.xml out.xml [in2.xml out2.xml ...]\n");
    exit(2);
}

$SECRET_TAGS = '/^(password|passwd|privkey|privatekey|private_key|psk|presharedkey|pre-shared-key|prv|'
    . 'sha512-hash|bcrypt-hash|md5-hash|nt-hash|authorizedkeys|apikeys|key|secret|shared_key|tls|'
    . 'tlsauth|community|rocommunity|rwcommunity|ldap_bindpw|radius_secret|otp_seed|totp|'
    . 'auth_pass|auth_user|pass|token|apikey|api_key|api_secret|secret_key|crt|csr|'
    . 'ipsec_psk|caref_key|keydata|mschapv2|webguicss_custom|audit|dashboard)$/i';
$FREE_TEXT_TAGS = '/^(descr|description|notes|text|name_full|contact|location|sysContact|sysLocation|'
    . 'username|user|email|hostname_override|comment)$/i';

$ipMap = []; $ip6Map = []; $domMap = []; $keyMap = []; $macMap = []; $text = 0;

function is_public_v4(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function map_v4(string $ip): string {
    global $ipMap;
    if (!is_public_v4($ip) || preg_match('/^(8\.8\.|1\.1\.1\.|9\.9\.9\.)/', $ip)) {
        return $ip; // private, reserved, or a well-known public resolver
    }
    if (!isset($ipMap[$ip])) {
        $n = count($ipMap) + 1;
        $ipMap[$ip] = '203.0.113.' . (10 + $n % 240);
    }
    return $ipMap[$ip];
}

function scrub_value(string $v): string {
    global $domMap, $keyMap, $macMap, $ip6Map;
    foreach (array_filter(array_map('trim', explode(',', (string)getenv('OPNMGR_FIXTURE_REDACT_WORDS')))) as $w) {
        $v = str_ireplace($w, 'example', $v);
    }
    $v = preg_replace_callback('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', fn($m) => map_v4($m[0]), $v);
    $v = preg_replace_callback('/\b(?:[0-9a-f]{2}:){5}[0-9a-f]{2}\b/i', function ($m) use (&$macMap) {
        $k = strtolower($m[0]);
        return $macMap[$k] ??= sprintf('02:00:00:00:%02x:%02x', intdiv(count($macMap), 256), count($macMap) % 256);
    }, $v);
    $v = preg_replace_callback('/\b(?:[0-9a-f]{1,4}:){2,7}[0-9a-f]{0,4}\b/i', function ($m) use (&$ip6Map) {
        if (stripos($m[0], 'fe80') === 0 || stripos($m[0], 'fd') === 0 || filter_var($m[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $m[0];
        }
        return $ip6Map[$m[0]] ??= '2001:db8::' . dechex(count($ip6Map) + 1);
    }, $v);
    // High-entropy tokens (API keys for DNS providers, ACME, dyndns...) that
    // live in fields with innocuous names. Mixed case plus digits, 20+ chars.
    $v = preg_replace_callback('/(?<![A-Za-z0-9])[A-Za-z0-9+\/_-]{20,}={0,2}(?![A-Za-z0-9])/', function ($m) {
        $t = $m[0];
        $mixed = preg_match('/[a-z]/', $t) && preg_match('/[A-Z]/', $t) && preg_match('/\d/', $t);
        return ($mixed && !preg_match('/^[0-9a-f-]{36}$/i', $t)) ? 'REDACTED' : $t;
    }, $v);
    $v = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', 'user@example.net', $v);
    // WireGuard-style base64 keys (44 chars ending in '=').
    $v = preg_replace_callback('/\b[A-Za-z0-9+\/]{43}=/', function ($m) use (&$keyMap) {
        return $keyMap[$m[0]] ??= substr(base64_encode(hash('sha256', 'fixture-key-' . count($keyMap), true)), 0, 43) . '=';
    }, $v);
    $v = preg_replace_callback('/\b(?:[a-z0-9-]+\.)+(?:net|com|org|io|us|ca|uk|de|info|biz|co)\b/i', function ($m) use (&$domMap) {
        $d = strtolower($m[0]);
        if (str_ends_with($d, 'example.net') || str_ends_with($d, 'opnsense.org') || str_ends_with($d, 'pfsense.org')
            || str_ends_with($d, 'netgate.com') || str_ends_with($d, 'pool.ntp.org')) {
            return $m[0];
        }
        return $domMap[$d] ??= 'host' . (count($domMap) + 1) . '.example.net';
    }, $v);
    return $v;
}

function scrub(DOMNode $node): void {
    global $SECRET_TAGS, $FREE_TEXT_TAGS, $text;
    foreach (iterator_to_array($node->childNodes) as $child) {
        if ($child instanceof DOMElement) {
            $name = $child->nodeName;
            $leaf = $child->childNodes->length === 1 && $child->firstChild instanceof DOMText;
            if ($leaf && trim($child->textContent) !== '') {
                if (preg_match($SECRET_TAGS, $name)) {
                    $child->nodeValue = 'REDACTED';
                    continue;
                }
                if (preg_match($FREE_TEXT_TAGS, $name)) {
                    $child->nodeValue = 'text-' . (++$text);
                    continue;
                }
            }
            foreach (iterator_to_array($child->attributes ?? []) as $attr) {
                if ($attr->nodeName !== 'uuid') {
                    $attr->nodeValue = scrub_value($attr->nodeValue);
                }
            }
            scrub($child);
        } elseif ($child instanceof DOMText) {
            $child->nodeValue = scrub_value($child->nodeValue);
        } elseif ($child instanceof DOMComment) {
            $node->removeChild($child);
        }
    }
}

for ($i = 1; $i + 1 < $argc; $i += 2) {
    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = true;
    if (!$doc->load($argv[$i], LIBXML_NONET)) {
        fwrite(STDERR, "cannot parse {$argv[$i]}\n");
        exit(1);
    }
    scrub($doc->documentElement);
    // The <revision> block names the last editor; reset it.
    foreach (['revision'] as $tag) {
        foreach (iterator_to_array($doc->documentElement->getElementsByTagName($tag)) as $n) {
            foreach (iterator_to_array($n->childNodes) as $c) {
                if ($c instanceof DOMElement) { $c->nodeValue = 'fixture'; }
            }
        }
    }
    $doc->save($argv[$i + 1]);
    echo "wrote {$argv[$i + 1]}\n";
}
