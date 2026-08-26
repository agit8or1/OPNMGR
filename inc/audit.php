<?php
/**
 * OPNMGR Audit Log
 *
 * Records security- and operations-relevant actions taken by MSP staff, by
 * agents and by the system itself. Deliberately separate from inc/logging.php
 * (system_logs), which is operational telemetry: audit rows are about *who did
 * what to which object*, and are never written with credential material in them.
 *
 * Secrets are stripped centrally in audit_scrub_metadata() rather than being
 * left to each call site to remember.
 *
 * @since 3.12.0
 */

require_once __DIR__ . '/version.php';

if (!defined('AUDIT_SENSITIVE_KEYS')) {
    /**
     * Metadata keys whose values are replaced with a redaction marker.
     * Matching is case-insensitive and substring-based, so 'smtp_password',
     * 'api_secret' and 'totpSecret' are all covered.
     */
    define('AUDIT_SENSITIVE_KEYS', [
        'password', 'passwd', 'secret', 'api_key', 'apikey', 'token',
        'private_key', 'privatekey', 'ssh_key', 'recovery_code', 'recovery_codes',
        'totp', 'mfa', 'signature', 'nonce', 'hardware_id', 'credential',
        'authorization', 'cookie', 'session', 'master_key', 'psk', 'preshared',
    ]);
}

if (!function_exists('audit_scrub_metadata')) {
    /**
     * Recursively redact credential-bearing values from audit metadata.
     *
     * @param mixed $data
     * @param int   $depth Recursion guard
     * @return mixed
     */
    function audit_scrub_metadata($data, int $depth = 0) {
        if ($depth > 8) {
            return '[truncated]';
        }
        if (!is_array($data)) {
            return $data;
        }

        $clean = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string)$key);
            $isSecret = false;
            foreach (AUDIT_SENSITIVE_KEYS as $needle) {
                if (str_contains($lowerKey, $needle)) {
                    $isSecret = true;
                    break;
                }
            }

            if ($isSecret) {
                $clean[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $clean[$key] = audit_scrub_metadata($value, $depth + 1);
            } elseif (is_string($value) && strlen($value) > 2000) {
                $clean[$key] = substr($value, 0, 2000) . '... [truncated]';
            } else {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }
}

if (!function_exists('audit_client_ip')) {
    /**
     * Best-effort client IP for audit rows.
     *
     * Only honours X-Forwarded-For when the immediate peer is a loopback
     * address, i.e. when we are genuinely behind the local reverse proxy.
     * Trusting it unconditionally would let any caller forge their own
     * source IP in the audit trail.
     */
    function audit_client_ip(): ?string {
        $remote = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($remote === null) {
            return null; // CLI
        }

        $isLocalProxy = in_array($remote, ['127.0.0.1', '::1'], true);
        if ($isLocalProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return $remote;
    }
}

if (!function_exists('audit_log')) {
    /**
     * Write an audit entry.
     *
     * Never throws: an audit write failure must not take down the action being
     * audited, but it is logged to the PHP error log so it is not silent.
     *
     * @param string $action  Stable key, e.g. 'auth.login', 'command.raw'
     * @param array  $opts    success, message, object_type, object_id,
     *                        firewall_id, customer_id, site_id, metadata,
     *                        actor_type, user_id, username, source_ip
     */
    function audit_log(string $action, array $opts = []): void {
        try {
            if (!function_exists('db')) {
                return;
            }

            // Resolve the actor from the session unless explicitly overridden.
            $actorType = $opts['actor_type'] ?? null;
            $userId    = $opts['user_id']    ?? ($_SESSION['user_id']  ?? null);
            $username  = $opts['username']   ?? ($_SESSION['username'] ?? null);

            if ($actorType === null) {
                if ($userId !== null) {
                    $actorType = 'user';
                } elseif (PHP_SAPI === 'cli') {
                    $actorType = 'system';
                } else {
                    $actorType = 'anonymous';
                }
            }

            $metadata = $opts['metadata'] ?? null;
            if (is_array($metadata)) {
                $metadata = json_encode(audit_scrub_metadata($metadata), JSON_UNESCAPED_SLASHES);
            }

            $message = $opts['message'] ?? null;
            if (is_string($message) && strlen($message) > 512) {
                $message = substr($message, 0, 509) . '...';
            }

            $stmt = db()->prepare(
                'INSERT INTO audit_log
                    (actor_type, user_id, username, source_ip, action, object_type,
                     object_id, firewall_id, customer_id, site_id, success, message, metadata)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $actorType,
                $userId !== null ? (int)$userId : null,
                $username,
                $opts['source_ip'] ?? audit_client_ip(),
                $action,
                $opts['object_type'] ?? null,
                isset($opts['object_id']) ? (string)$opts['object_id'] : null,
                isset($opts['firewall_id']) ? (int)$opts['firewall_id'] : null,
                isset($opts['customer_id']) ? (int)$opts['customer_id'] : null,
                isset($opts['site_id']) ? (int)$opts['site_id'] : null,
                array_key_exists('success', $opts) ? (int)(bool)$opts['success'] : 1,
                $message,
                $metadata,
            ]);
        } catch (Throwable $e) {
            error_log('OPNMGR audit_log failed for action "' . $action . '": ' . $e->getMessage());
        }
    }
}

if (!function_exists('audit_log_agent')) {
    /**
     * Convenience wrapper for agent-originated events.
     *
     * @param string   $action
     * @param int|null $firewallId
     * @param array    $opts
     */
    function audit_log_agent(string $action, ?int $firewallId, array $opts = []): void {
        audit_log($action, array_merge($opts, [
            'actor_type'  => 'agent',
            'user_id'     => null,
            'username'    => null,
            'firewall_id' => $firewallId,
            'object_type' => $opts['object_type'] ?? 'firewall',
            'object_id'   => $opts['object_id'] ?? ($firewallId !== null ? (string)$firewallId : null),
        ]));
    }
}
