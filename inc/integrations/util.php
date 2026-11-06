<?php
/**
 * Integrations: small shared helpers (ids, redaction, JSON paths, backoff,
 * customer scoping).
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/../audit.php';
require_once __DIR__ . '/../crypto.php';
require_once __DIR__ . '/../scope.php';

if (!defined('INTEGRATION_EVENT_TYPES')) {
    /** Event types the pipeline carries, with a label. */
    define('INTEGRATION_EVENT_TYPES', [
        'incident.opened'            => 'Incident opened',
        'incident.severity_changed'  => 'Incident severity changed',
        'incident.acknowledged'      => 'Incident acknowledged',
        'incident.resolved'          => 'Incident resolved',
        'incident.reopened'          => 'Incident reopened (recurrence)',
        'incident.escalated'         => 'Incident escalated (unacknowledged)',
        'policy.deployment_failed'   => 'Policy deployment failed',
        'report.ready'               => 'Scheduled report ready',
        'integration.test'           => 'Test message',
    ]);
}

if (!function_exists('integration_uuid')) {
    function integration_uuid(): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20));
    }
}

if (!function_exists('integration_setting')) {
    function integration_setting(string $name, string $default = ''): string {
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

if (!function_exists('integration_redact_string')) {
    /**
     * Remove credential-looking material from free text (error messages,
     * summaries). Deliberately aggressive: a redacted diagnostic is an
     * inconvenience, a leaked token is an incident.
     *
     * @param string[] $knownSecrets exact values to mask wherever they appear
     */
    function integration_redact_string(string $s, array $knownSecrets = []): string {
        foreach ($knownSecrets as $sec) {
            if (is_string($sec) && strlen($sec) >= 4) {
                $s = str_replace($sec, '[redacted]', $s);
            }
        }
        // PEM blocks
        $s = preg_replace('/-----BEGIN [A-Z ]*(PRIVATE KEY|CERTIFICATE|PGP)[A-Z ]*-----.*?(-----END [A-Z ]*-----|$)/s', '[redacted key material]', $s);
        // Authorization / bearer
        $s = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{6,}/i', '$1 [redacted]', $s);
        // key=value / key: value style secrets
        $s = preg_replace('/\b(pass(?:word|wd)?|secret|token|api[_-]?key|apikey|authorization|psk|private[_-]?key|signature)\b(["\']?\s*[:=]\s*["\']?)[^\s"\'&,;}]+/i', '$1$2[redacted]', $s);
        // URL userinfo and path/query of URLs (webhook URLs carry their token in the path)
        $s = preg_replace_callback('#\b(https?)://([^\s/"\'<>]+)(/[^\s"\'<>]*)?#i', function ($m) {
            $host = preg_replace('/^[^@]*@/', '', $m[2]);
            return $m[1] . '://' . $host . (isset($m[3]) && $m[3] !== '' && $m[3] !== '/' ? '/[redacted]' : '');
        }, $s);
        // Long opaque tokens (hex/base64url >= 32 chars)
        $s = preg_replace('/\b[A-Za-z0-9_\-]{40,}\b/', '[redacted]', $s);
        return $s;
    }
}

if (!function_exists('integration_scrub')) {
    /**
     * Recursively scrub an arbitrary structure for outbound use: audit key
     * scrubbing, extra raw-configuration keys, string redaction and size caps.
     */
    function integration_scrub($data, int $depth = 0) {
        static $extra = ['raw_config', 'config_xml', 'configuration', 'running_config', 'config_dump',
                         'backup_content', 'xml', 'pem', 'certificate_key', 'bearer', 'auth', 'cert_key'];
        if ($depth > 6) { return '[truncated]'; }
        if (is_string($data)) {
            $data = integration_redact_string($data);
            return strlen($data) > 500 ? substr($data, 0, 500) . '...' : $data;
        }
        if (!is_array($data)) { return is_scalar($data) || $data === null ? $data : '[object]'; }
        $data = audit_scrub_metadata($data, $depth);
        $out = [];
        $n = 0;
        foreach ($data as $k => $v) {
            if (++$n > 50) { break; }
            $lk = strtolower((string)$k);
            foreach ($extra as $needle) {
                if (str_contains($lk, $needle)) { $out[$k] = '[redacted]'; continue 2; }
            }
            $out[$k] = ($v === '[redacted]') ? $v : integration_scrub($v, $depth + 1);
        }
        return $out;
    }
}

if (!function_exists('integration_json_path')) {
    /** Read a dotted path ("data.ticket.id", "items.0.id") from decoded JSON. */
    function integration_json_path($data, string $path) {
        if ($path === '') { return null; }
        foreach (explode('.', $path) as $seg) {
            if (is_array($data) && array_key_exists($seg, $data)) { $data = $data[$seg]; }
            else { return null; }
        }
        return $data;
    }
}

if (!function_exists('integration_backoff_seconds')) {
    /**
     * Exponential backoff with +/-20% jitter: 30s, 60s, 120s ... capped at 1h.
     * $jitter in [0,1) may be injected for deterministic tests.
     */
    function integration_backoff_seconds(int $attempt, ?float $jitter = null): int {
        $base = min(3600, 30 * (2 ** max(0, $attempt - 1)));
        $j = $jitter ?? (mt_rand() / (mt_getrandmax() + 1));
        return max(1, (int)round($base * (0.8 + 0.4 * $j)));
    }
}

if (!function_exists('integration_scope_sql')) {
    /** Customer-scope SQL for a column; [sql, params]. Fails closed for scoped staff on NULL customers. */
    function integration_scope_sql(string $col): array {
        return scope_sql($col);
    }
}

if (!function_exists('integration_can_see_customer')) {
    function integration_can_see_customer(?int $customerId): bool {
        return scope_can_customer($customerId);
    }
}

if (!function_exists('integration_now')) {
    /** Database time, so comparisons against NOW() columns agree. */
    function integration_now(): string {
        try { return (string)db()->query('SELECT NOW()')->fetchColumn(); }
        catch (Throwable $e) { return date('Y-m-d H:i:s'); }
    }
}

if (!function_exists('integration_clean_text')) {
    /** Strip control characters and cap length. */
    function integration_clean_text(string $s, int $max = 300): string {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
        return mb_substr($s, 0, $max);
    }
}
