<?php
/**
 * OPNMGR enrollment installer: the one place a shell installer is rendered.
 *
 * Five endpoints used to build the legacy enrollment script, each its own way
 * (api/get_enroll_script.php, api/get_enroll_script_http.php,
 * api/get_enrollment_script.php, enroll_firewall.php?action=download and,
 * until 3.80.3, enroll_firewall_new.php?action=download). They
 * accepted any non-empty token and pasted it between double quotes:
 *
 *     ENROLLMENT_TOKEN="__ENROLLMENT_TOKEN__"
 *
 * so a "token" such as $(...) was command substitution that ran, as root, on
 * whichever firewall executed the downloaded installer. Two of them also built
 * the manager URL from the client's Host header.
 *
 * All of them now call enrollment_render_installer(), which:
 *   - accepts only the token format the manager issues (64 lowercase hex)
 *   - requires a live record: known, unexpired, never used
 *   - takes the manager URL from configuration, never the request
 *   - validates every substituted value against its own format, and
 *     single-quotes it for the shell, so nothing substituted is ever parsed
 *     as shell syntax
 *
 * Rendering does not consume the token. It is consumed once, atomically, by
 * api/enroll.php when the firewall actually enrolls.
 *
 * @since 3.79.0
 */

require_once __DIR__ . '/server_identity.php';

if (!function_exists('enrollment_token_format_ok')) {
    /** Tokens are bin2hex(random_bytes(32)) everywhere they are issued. */
    function enrollment_token_format_ok($token): bool {
        return is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
    }
}

if (!function_exists('enrollment_token_state')) {
    /**
     * Why a token is or is not usable. The reason is for logs and tests; the
     * client gets one undifferentiated refusal so the endpoint cannot be used
     * to probe which tokens exist.
     *
     * @return array{ok:bool, reason:string, row:?array}
     */
    function enrollment_token_state($token): array {
        if (!enrollment_token_format_ok($token)) {
            return ['ok' => false, 'reason' => 'malformed', 'row' => null];
        }
        $stmt = db()->prepare('SELECT * FROM enrollment_tokens WHERE token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row === null) {
            return ['ok' => false, 'reason' => 'unknown', 'row' => null];
        }
        if (!empty($row['used']) || !empty($row['used_at'])) {
            return ['ok' => false, 'reason' => 'used', 'row' => $row];
        }
        if (strtotime((string)$row['expires_at']) <= time()) {
            return ['ok' => false, 'reason' => 'expired', 'row' => $row];
        }
        return ['ok' => true, 'reason' => '', 'row' => $row];
    }
}

if (!function_exists('enrollment_token_claim')) {
    /**
     * Consume a token exactly once. A conditional UPDATE is the claim, so two
     * concurrent enrollments with the same token cannot both succeed.
     */
    function enrollment_token_claim(string $token, ?string $hardwareId = null): bool {
        if (!enrollment_token_format_ok($token)) {
            return false;
        }
        $stmt = db()->prepare(
            'UPDATE enrollment_tokens SET used = 1, used_at = NOW(), hardware_id = COALESCE(?, hardware_id)
              WHERE token = ? AND used = 0 AND used_at IS NULL AND expires_at > NOW()'
        );
        $stmt->execute([$hardwareId, $token]);
        return $stmt->rowCount() === 1;
    }
}

if (!function_exists('enrollment_shell_quote')) {
    /** POSIX single-quoting: the only character needing care is ' itself. */
    function enrollment_shell_quote(string $value): string {
        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
    }
}

if (!function_exists('enrollment_render_installer')) {
    /**
     * Render simple_enroll.sh for a token.
     *
     * @param string $token     As supplied by the client
     * @param array  $overrides For tests: panel_url, ssh_key, mgmt_ip
     * @return array{ok:bool, status:int, error:string, reason:string, script:string}
     */
    function enrollment_render_installer($token, array $overrides = []): array {
        $fail = fn(int $status, string $error, string $reason) =>
            ['ok' => false, 'status' => $status, 'error' => $error, 'reason' => $reason, 'script' => ''];

        $state = enrollment_token_state($token);
        if (!$state['ok']) {
            return $fail($state['reason'] === 'malformed' ? 400 : 404,
                'Invalid, expired or already used enrollment token', $state['reason']);
        }

        $panelUrl = $overrides['panel_url'] ?? opnmgr_server_url();
        if (!preg_match('#^https?://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9._~/-]*)?$#D', (string)$panelUrl)) {
            return $fail(500, "This manager's URL is not configured (Settings > Manager FQDN, or APP_URL)", 'panel_url');
        }

        $host = (string)parse_url($panelUrl, PHP_URL_HOST);
        $mgmtIp = $overrides['mgmt_ip'] ?? (filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host));
        if (!filter_var($mgmtIp, FILTER_VALIDATE_IP)) {
            return $fail(500, "Could not resolve this manager's address for the SSH rule", 'mgmt_ip');
        }

        if (array_key_exists('ssh_key', $overrides)) {
            $sshKey = (string)$overrides['ssh_key'];
        } else {
            require_once __DIR__ . '/enrollment_key.php';
            $sshKey = opnmgr_enrollment_public_key();
        }
        $sshKey = trim($sshKey);
        if (!preg_match('#^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521)) [A-Za-z0-9+/]+={0,3}( [A-Za-z0-9@._-]{1,128})?$#D', $sshKey)) {
            return $fail(500, 'This manager has no usable enrollment SSH key', 'ssh_key');
        }

        $template = (string)@file_get_contents(dirname(__DIR__) . '/simple_enroll.sh');
        // Only the assignment lines are rewritten. The MGMT_SERVER_IP case
        // statement deliberately keeps the literal placeholder: it is how an
        // unrendered copy of the script recognises itself and stops.
        $assignments = [
            'PANEL_URL'        => [$panelUrl, '__PANEL_URL__'],
            'ENROLLMENT_TOKEN' => [$token, '__ENROLLMENT_TOKEN__'],
            'SERVER_SSH_KEY'   => [$sshKey, '__SERVER_SSH_KEY__'],
            'MGMT_SERVER_IP'   => [$mgmtIp, '__MGMT_SERVER_IP__'],
        ];
        foreach ($assignments as $var => [$value, $placeholder]) {
            $line = $var . '="' . $placeholder . '"';
            if (substr_count($template, "\n" . $line . "\n") !== 1) {
                return $fail(500, 'The enrollment template is not in the expected form', 'template:' . $var);
            }
            $template = str_replace("\n" . $line . "\n", "\n" . $var . '=' . enrollment_shell_quote($value) . "\n", $template);
        }
        // Belt and braces: no placeholder may survive outside the guard.
        $leftover = preg_replace('/^\s*""\|__MGMT_SERVER_IP__\)\s*$/m', '', $template);
        if (preg_match('/__[A-Z_]+__/', $leftover)) {
            return $fail(500, 'The enrollment template has an unsubstituted placeholder', 'template:leftover');
        }

        return ['ok' => true, 'status' => 200, 'error' => '', 'reason' => '', 'script' => $template];
    }
}

if (!function_exists('enrollment_serve_installer')) {
    /** Shared HTTP handler for every installer endpoint. Ends the request. */
    function enrollment_serve_installer(): void {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        $token = $_GET['token'] ?? '';
        $r = enrollment_render_installer(is_string($token) ? $token : '');
        if (!$r['ok']) {
            http_response_code($r['status']);
            if (function_exists('audit_log')) {
                audit_log('enrollment.installer.refused', [
                    'actor_type' => 'anonymous', 'success' => false,
                    'message'    => 'Enrollment installer refused (' . $r['reason'] . ')',
                ]);
            }
            echo "# Error: " . $r['error'] . "\n";
            echo "exit 1\n";
            exit;
        }
        header('Content-Disposition: attachment; filename="opnsense_enroll.sh"');
        echo $r['script'];
        exit;
    }
}
