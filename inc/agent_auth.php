<?php
/**
 * OPNMGR Agent Authentication
 *
 * Single place where agent-originated HTTP requests are authenticated. Every
 * agent-facing endpoint must call authenticateAgentRequest() instead of
 * hand-rolling its own hardware_id comparison.
 *
 * Credentials, weakest to strongest:
 *
 *   1. hardware_id  - a device fingerprint (md5 of hostid/SMBIOS UUID/WAN MAC).
 *                     It is an IDENTIFIER, not a secret: anyone who can observe
 *                     the firewall's MAC can derive it. Historically it was the
 *                     only credential, which is why 2 and 3 exist.
 *   2. api_key      - a 256-bit server-issued bearer secret, provisioned to the
 *                     agent on check-in and pinned once first presented.
 *   3. HMAC-SHA256  - per-request signature over method/path/timestamp/nonce/
 *                     body-hash using the firewall's api_secret. Defeats replay
 *                     and bearer-token theft.
 *
 * Backward compatibility is the hard constraint: an installed fleet must not
 * stop checking in when the server is upgraded. The upgrade is therefore
 * per-firewall and self-closing:
 *
 *   - A firewall with no api_key has one generated and handed back in the
 *     check-in response. Until the agent presents it, hardware_id alone still
 *     authenticates (exactly the pre-upgrade behaviour - no regression).
 *   - The first time the agent presents the correct api_key we set
 *     api_key_confirmed = 1. From that moment authentication FAILS CLOSED for
 *     that firewall: the key is mandatory and a downgrade is rejected.
 *   - The same ratchet applies to signatures via agent_signing_supported.
 *
 * The bootstrap window can be closed manually by an administrator
 * (agent_auth_mode = require_signed) or by clearing an agent's credentials to
 * re-provision it.
 *
 * @since 3.12.0
 */

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/audit.php';

// Failure reasons are internal-only. Endpoints return a generic message.
if (!defined('AGENT_AUTH_GENERIC_ERROR')) {
    define('AGENT_AUTH_GENERIC_ERROR', 'Authentication failed');
}

if (!function_exists('agent_raw_body')) {
    /**
     * Raw request body, read once and cached.
     *
     * Signature verification hashes the exact bytes received, so the body must
     * not be re-read (or re-encoded) between verification and use.
     */
    function agent_raw_body(): string {
        static $body = null;
        if ($body === null) {
            $body = file_get_contents('php://input');
            if ($body === false) {
                $body = '';
            }
        }
        return $body;
    }
}

if (!function_exists('agent_auth_setting')) {
    /**
     * Read a setting from the settings table with a fallback default.
     */
    function agent_auth_setting(string $name, string $default): string {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            try {
                $cache = db()->query('SELECT `name`,`value` FROM settings')
                             ->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            } catch (Throwable $e) {
                error_log('OPNMGR: could not load settings for agent auth: ' . $e->getMessage());
            }
        }
        $value = $cache[$name] ?? '';
        return ($value === '' || $value === null) ? $default : (string)$value;
    }
}

if (!function_exists('agent_auth_mode')) {
    /**
     * Current fleet-wide signing policy.
     *
     * compatibility  - signatures verified when present, never required
     * prefer_signed  - as above, but required once an agent has proven it can sign
     * require_signed - required from every agent, no exceptions
     */
    function agent_auth_mode(): string {
        $mode = agent_auth_setting('agent_auth_mode', 'compatibility');
        return in_array($mode, ['compatibility', 'prefer_signed', 'require_signed'], true)
            ? $mode
            : 'compatibility';
    }
}

if (!function_exists('agent_auth_fail')) {
    /**
     * Reject an agent request.
     *
     * Records the real reason server-side (audit log + error log) but returns a
     * deliberately uninformative body so a prober cannot distinguish "unknown
     * firewall" from "bad key" from "stale timestamp".
     *
     * @param int         $status      HTTP status (401 credentials, 403 identity)
     * @param string      $reason      Internal reason code
     * @param int|null    $firewallId  Claimed firewall, if any
     * @param array       $meta        Extra non-secret context for the audit row
     */
    function agent_auth_fail(int $status, string $reason, ?int $firewallId = null, array $meta = []): void {
        error_log(sprintf(
            'OPNMGR agent auth REJECTED: reason=%s firewall_id=%s ip=%s path=%s',
            $reason,
            $firewallId ?? 'unknown',
            audit_client_ip() ?? 'cli',
            $_SERVER['REQUEST_URI'] ?? '-'
        ));

        if ($firewallId !== null) {
            try {
                db()->prepare(
                    'UPDATE firewalls
                        SET agent_auth_failures = agent_auth_failures + 1,
                            agent_last_auth_failure_at = NOW()
                      WHERE id = ?'
                )->execute([$firewallId]);
            } catch (Throwable $e) {
                error_log('OPNMGR: could not record agent auth failure: ' . $e->getMessage());
            }
        }

        audit_log_agent('agent.auth.failed', $firewallId, [
            'success'  => false,
            'message'  => 'Agent authentication rejected: ' . $reason,
            'metadata' => array_merge($meta, ['reason' => $reason, 'status' => $status]),
        ]);

        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => AGENT_AUTH_GENERIC_ERROR]);
        exit;
    }
}

if (!function_exists('agent_verify_signature')) {
    /**
     * Verify an HMAC-SHA256 request signature.
     *
     * Canonical string (newline separated):
     *   METHOD \n PATH \n TIMESTAMP \n NONCE \n sha256hex(raw body)
     *
     * @param array  $firewall Firewall row (api_secret already decrypted)
     * @param string $secret   Plaintext signing secret
     * @return array{present:bool, valid:bool, reason:string, skew:int|null}
     */
    function agent_verify_signature(array $firewall, string $secret): array {
        $timestamp = $_SERVER['HTTP_X_OPNMGR_TIMESTAMP'] ?? '';
        $nonce     = $_SERVER['HTTP_X_OPNMGR_NONCE'] ?? '';
        $signature = $_SERVER['HTTP_X_OPNMGR_SIGNATURE'] ?? '';

        if ($timestamp === '' && $nonce === '' && $signature === '') {
            return ['present' => false, 'valid' => false, 'reason' => 'absent', 'skew' => null];
        }
        if ($timestamp === '' || $nonce === '' || $signature === '') {
            return ['present' => true, 'valid' => false, 'reason' => 'incomplete_signature_headers', 'skew' => null];
        }
        if ($secret === '') {
            return ['present' => true, 'valid' => false, 'reason' => 'no_signing_secret_provisioned', 'skew' => null];
        }

        // --- freshness ------------------------------------------------------
        if (!ctype_digit(ltrim($timestamp, '-'))) {
            return ['present' => true, 'valid' => false, 'reason' => 'malformed_timestamp', 'skew' => null];
        }
        $window = (int)agent_auth_setting('agent_signature_window', '300');
        $window = ($window >= 30 && $window <= 3600) ? $window : 300;
        $skew   = time() - (int)$timestamp;

        if (abs($skew) > $window) {
            return ['present' => true, 'valid' => false, 'reason' => 'stale_timestamp', 'skew' => $skew];
        }

        // --- nonce format ---------------------------------------------------
        if (strlen($nonce) < 8 || strlen($nonce) > 128 || !preg_match('/^[A-Za-z0-9_\-]+$/', $nonce)) {
            return ['present' => true, 'valid' => false, 'reason' => 'malformed_nonce', 'skew' => $skew];
        }

        // --- signature ------------------------------------------------------
        // Use the URL path only; query strings are not part of agent requests
        // and including them would break behind path-rewriting proxies.
        $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'POST');

        $canonical = implode("\n", [
            $method,
            $path,
            $timestamp,
            $nonce,
            hash('sha256', agent_raw_body()),
        ]);

        $expected = hash_hmac('sha256', $canonical, $secret);

        if (!hash_equals($expected, strtolower(trim($signature)))) {
            return ['present' => true, 'valid' => false, 'reason' => 'signature_mismatch', 'skew' => $skew];
        }

        // --- replay ---------------------------------------------------------
        // Insert first, then check: the UNIQUE (firewall_id, nonce) constraint
        // makes this atomic, so two concurrent replays cannot both pass.
        try {
            $insert = db()->prepare(
                'INSERT IGNORE INTO agent_request_nonces (firewall_id, nonce) VALUES (?, ?)'
            );
            $insert->execute([(int)$firewall['id'], $nonce]);
            if ($insert->rowCount() === 0) {
                return ['present' => true, 'valid' => false, 'reason' => 'nonce_replay', 'skew' => $skew];
            }
        } catch (Throwable $e) {
            error_log('OPNMGR: nonce store unavailable, rejecting signed request: ' . $e->getMessage());
            return ['present' => true, 'valid' => false, 'reason' => 'nonce_store_unavailable', 'skew' => $skew];
        }

        agent_prune_nonces($window);

        return ['present' => true, 'valid' => true, 'reason' => 'ok', 'skew' => $skew];
    }
}

if (!function_exists('agent_prune_nonces')) {
    /**
     * Drop nonces older than twice the signature window.
     *
     * Sampled rather than run on every request: the table only needs to retain
     * entries for as long as a signature could still be considered fresh.
     */
    function agent_prune_nonces(int $window): void {
        if (random_int(1, 50) !== 1) {
            return;
        }
        try {
            db()->prepare('DELETE FROM agent_request_nonces WHERE seen_at < (NOW() - INTERVAL ? SECOND)')
                ->execute([max(120, $window * 2)]);
        } catch (Throwable $e) {
            error_log('OPNMGR: nonce prune failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('authenticateAgentRequest')) {
    /**
     * Authenticate an agent-originated request, or terminate it.
     *
     * On success returns the firewall row with two extra keys:
     *   _auth  => ['signed' => bool, 'api_key_used' => bool, 'mode' => string]
     *   _issue => credentials to hand back to the agent (see
     *             agent_credentials_payload()), or an empty array
     *
     * On failure this function does not return: it emits a generic JSON error
     * and exits.
     *
     * @param array $input Decoded request body (JSON or form fields)
     * @param array $opts  'require_signature' => bool to force signing for this
     *                     endpoint regardless of fleet mode
     * @return array Firewall row
     */
    function authenticateAgentRequest(array $input, array $opts = []): array {
        $firewallId  = (int)($input['firewall_id'] ?? 0);
        $hardwareId  = trim((string)($input['hardware_id'] ?? ''));
        $apiKey      = trim((string)($input['api_key'] ?? ''));

        // Bearer header is accepted as an alternative to a body field so that
        // GET endpoints do not have to put the key in a query string (where it
        // would end up in access logs).
        if ($apiKey === '' && !empty($_SERVER['HTTP_X_OPNMGR_API_KEY'])) {
            $apiKey = trim($_SERVER['HTTP_X_OPNMGR_API_KEY']);
        }

        // --- resolve identity ------------------------------------------------
        if ($firewallId === 0 && $hardwareId !== '') {
            try {
                $lookup = db()->prepare('SELECT id FROM firewalls WHERE hardware_id = ?');
                $lookup->execute([$hardwareId]);
                $row = $lookup->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $firewallId = (int)$row['id'];
                }
            } catch (Throwable $e) {
                error_log('OPNMGR: firewall lookup by hardware_id failed: ' . $e->getMessage());
            }
        }

        if ($firewallId === 0 || $hardwareId === '') {
            agent_auth_fail(401, 'missing_identity', $firewallId ?: null);
        }

        try {
            $stmt = db()->prepare(
                'SELECT id, hostname, hardware_id, agent_api_key, agent_api_secret,
                        api_key_confirmed, agent_signing_supported, agent_auth_failures
                   FROM firewalls WHERE id = ?'
            );
            $stmt->execute([$firewallId]);
            $firewall = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('OPNMGR: agent auth database error: ' . $e->getMessage());
            agent_auth_fail(503, 'database_error', $firewallId);
        }

        if (!$firewall) {
            agent_auth_fail(403, 'unknown_firewall', $firewallId);
        }

        // --- hardware_id -----------------------------------------------------
        // A firewall with no stored hardware_id is claimed by the first agent to
        // present one (trust on first use); thereafter it is pinned.
        $storedHardwareId = (string)($firewall['hardware_id'] ?? '');
        if ($storedHardwareId === '') {
            try {
                db()->prepare('UPDATE firewalls SET hardware_id = ? WHERE id = ? AND (hardware_id IS NULL OR hardware_id = "")')
                    ->execute([$hardwareId, $firewallId]);
                $firewall['hardware_id'] = $hardwareId;
                audit_log_agent('agent.hardware_id.bound', $firewallId, [
                    'message' => 'hardware_id bound to firewall on first check-in',
                ]);
            } catch (Throwable $e) {
                error_log('OPNMGR: could not bind hardware_id: ' . $e->getMessage());
            }
        } elseif (!hash_equals($storedHardwareId, $hardwareId)) {
            agent_auth_fail(403, 'hardware_id_mismatch', $firewallId);
        }

        // --- api_key ---------------------------------------------------------
        $storedKey    = opnmgr_decrypt($firewall['agent_api_key'] ?? null) ?? '';
        $keyConfirmed = (int)($firewall['api_key_confirmed'] ?? 0) === 1;
        $issue        = [];
        $apiKeyUsed   = false;

        if ($storedKey === '') {
            // No credential yet: provision one and hand it back. Authentication
            // for this request rests on hardware_id, exactly as it did before
            // this module existed.
            $storedKey = opnmgr_random_secret(32);
            $newSecret = opnmgr_random_secret(32);
            try {
                db()->prepare(
                    'UPDATE firewalls
                        SET agent_api_key = ?, agent_api_secret = ?,
                            api_key_issued_at = NOW(), api_key_confirmed = 0
                      WHERE id = ?'
                )->execute([opnmgr_encrypt($storedKey), opnmgr_encrypt($newSecret), $firewallId]);

                $firewall['agent_api_secret'] = opnmgr_encrypt($newSecret);
                $issue = ['api_key' => $storedKey, 'api_secret' => $newSecret];

                audit_log_agent('agent.credentials.provisioned', $firewallId, [
                    'message' => 'API key and signing secret provisioned to agent',
                ]);
            } catch (Throwable $e) {
                error_log('OPNMGR: could not provision agent credentials: ' . $e->getMessage());
            }
        } elseif ($apiKey !== '' && hash_equals($storedKey, $apiKey)) {
            $apiKeyUsed = true;
            if (!$keyConfirmed) {
                // Ratchet: from now on this firewall must present its key.
                try {
                    db()->prepare('UPDATE firewalls SET api_key_confirmed = 1 WHERE id = ?')
                        ->execute([$firewallId]);
                    $keyConfirmed = true;
                    audit_log_agent('agent.credentials.confirmed', $firewallId, [
                        'message' => 'Agent presented its API key; key authentication is now mandatory for this firewall',
                    ]);
                } catch (Throwable $e) {
                    error_log('OPNMGR: could not confirm api_key: ' . $e->getMessage());
                }
            }
        } elseif ($keyConfirmed) {
            // Key is mandatory for this firewall and was absent or wrong.
            agent_auth_fail(401, $apiKey === '' ? 'api_key_missing' : 'api_key_mismatch', $firewallId);
        } else {
            // Key issued but not yet adopted by the agent. Re-issue it so an
            // agent that lost its config file can recover.
            $issue = ['api_key' => $storedKey, 'api_secret' => opnmgr_decrypt($firewall['agent_api_secret'] ?? null) ?? ''];
        }

        // --- signature -------------------------------------------------------
        $mode         = agent_auth_mode();
        $signedBefore = (int)($firewall['agent_signing_supported'] ?? 0) === 1;
        $secret       = opnmgr_decrypt($firewall['agent_api_secret'] ?? null) ?? '';
        $sig          = agent_verify_signature($firewall, $secret);

        $signatureRequired = ($opts['require_signature'] ?? false)
            || $mode === 'require_signed'
            || ($mode === 'prefer_signed' && $signedBefore);

        if ($sig['present'] && !$sig['valid']) {
            // A present-but-invalid signature is always fatal, in every mode.
            // Downgrading to unsigned on a bad signature would make signing
            // worthless.
            agent_auth_fail(401, 'signature_' . $sig['reason'], $firewallId, ['skew' => $sig['skew']]);
        }

        if (!$sig['present'] && $signatureRequired) {
            agent_auth_fail(401, 'signature_required', $firewallId, ['mode' => $mode]);
        }

        if ($sig['valid']) {
            try {
                db()->prepare(
                    'UPDATE firewalls
                        SET agent_signing_supported = 1,
                            agent_last_signed_at = NOW(),
                            agent_clock_skew_seconds = ?
                      WHERE id = ?'
                )->execute([$sig['skew'], $firewallId]);
            } catch (Throwable $e) {
                error_log('OPNMGR: could not record signed request: ' . $e->getMessage());
            }
        }

        // --- success ---------------------------------------------------------
        if ((int)($firewall['agent_auth_failures'] ?? 0) !== 0) {
            try {
                db()->prepare('UPDATE firewalls SET agent_auth_failures = 0 WHERE id = ?')
                    ->execute([$firewallId]);
            } catch (Throwable $e) {
                // Non-fatal.
            }
        }

        $firewall['_auth'] = [
            'signed'       => $sig['valid'],
            'api_key_used' => $apiKeyUsed,
            'confirmed'    => $keyConfirmed,
            'mode'         => $mode,
        ];
        $firewall['_issue'] = $issue;

        return $firewall;
    }
}

if (!function_exists('agent_credentials_payload')) {
    /**
     * Credentials block to merge into an agent response.
     *
     * Only ever returned over the authenticated check-in channel, and only
     * while the agent has not yet adopted its key.
     *
     * @param array $firewall Result of authenticateAgentRequest()
     */
    function agent_credentials_payload(array $firewall): array {
        if (empty($firewall['_issue']['api_key'])) {
            return [];
        }
        return [
            'agent_credentials' => [
                'api_key'      => $firewall['_issue']['api_key'],
                'api_secret'   => $firewall['_issue']['api_secret'] ?? '',
                'signing'      => [
                    'algorithm' => 'HMAC-SHA256',
                    'headers'   => ['X-OPNMGR-Timestamp', 'X-OPNMGR-Nonce', 'X-OPNMGR-Signature'],
                    'canonical' => 'METHOD\nPATH\nTIMESTAMP\nNONCE\nSHA256HEX(BODY)',
                ],
                'instructions' => 'Store these values and send api_key with every request. Sign requests once supported.',
            ],
        ];
    }
}

if (!function_exists('agent_request_input')) {
    /**
     * Normalised request body for agent endpoints.
     *
     * Accepts JSON (the agent's normal encoding) and falls back to form fields,
     * so endpoints do not each re-implement the same branch.
     */
    function agent_request_input(): array {
        $body = agent_raw_body();
        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if (!empty($_POST)) {
            return $_POST;
        }
        if (!empty($_GET)) {
            return $_GET;
        }
        return [];
    }
}

if (!function_exists('buildAgentReinstallCommand')) {
    /**
     * Shell command that makes a firewall re-fetch and reinstall its agent.
     *
     * reinstall_agent.php and uninstall_agent.php serve scripts that the
     * firewall pipes into sh as root, so they are authenticated like any other
     * agent request. The credentials are read on the firewall from the agent's
     * own files rather than being interpolated into the command, so the command
     * text - which is stored in firewall_commands and shown in the UI - never
     * contains a secret.
     *
     * @param string $serverHost Hostname the agent should call back to
     * @param int    $firewallId
     * @param string $action     'reinstall' or 'uninstall'
     */
    function buildAgentReinstallCommand(string $serverHost, int $firewallId, string $action = 'reinstall'): string {
        $endpoint = $action === 'uninstall' ? 'uninstall_agent.php' : 'reinstall_agent.php';
        $script   = $action === 'uninstall' ? '/tmp/uninstall_agent.sh' : '/tmp/reinstall_agent.sh';

        // Host comes from SERVER_NAME or configuration, never from a request
        // header, but strip anything that is not host-safe as a belt-and-braces
        // guard against it ever being used to inject shell metacharacters.
        $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', $serverHost);
        $host = $host !== '' ? $host : 'opn.agit8or.net';

        return sprintf(
            'HW=$(cat /usr/local/etc/opnmanager_hardware_id 2>/dev/null); '
            . 'KEY=$(cat /usr/local/etc/opnmanager_api_key 2>/dev/null); '
            . 'fetch -q -T 30 -o %s "https://%s/%s?firewall_id=%d&hardware_id=$HW&api_key=$KEY" '
            . '|| curl -sS -k -o %s "https://%s/%s?firewall_id=%d&hardware_id=$HW&api_key=$KEY"; '
            . 'chmod +x %s && sh %s',
            $script, $host, $endpoint, $firewallId,
            $script, $host, $endpoint, $firewallId,
            $script, $script
        );
    }
}
