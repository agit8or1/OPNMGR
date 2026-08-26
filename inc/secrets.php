<?php
/**
 * OPNMGR Secret Accessors
 *
 * Thin, secret-aware wrappers around the settings table and other stores that
 * hold credentials the application must be able to read back.
 *
 * Read paths go through here rather than reading the column directly, so that
 * a value being encrypted or not is invisible to callers. opnmgr_decrypt()
 * passes legacy plaintext through unchanged, which is what makes the backfill
 * (scripts/encrypt_secrets.php) safe to run at any point rather than needing to
 * be synchronised with a code deploy.
 *
 * @since 3.12.0
 */

require_once __DIR__ . '/crypto.php';

if (!function_exists('opnmgr_secret_settings')) {
    /**
     * Setting names whose values are credentials.
     *
     * @return string[]
     */
    function opnmgr_secret_settings(): array {
        return [
            'smtp_password',
            'slack_webhook_url',
            'teams_webhook_url',
            'discord_webhook_url',
            'pushover_token',
            'pushover_user',
            'twilio_auth_token',
            'webhook_secret',
            'snyk_token',
        ];
    }
}

if (!function_exists('get_secret_setting')) {
    /**
     * Read and decrypt a credential from the settings table.
     */
    function get_secret_setting(string $name, string $default = ''): string {
        try {
            $stmt = db()->prepare('SELECT `value` FROM settings WHERE `name` = ?');
            $stmt->execute([$name]);
            $value = $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('OPNMGR: could not read secret setting ' . $name . ': ' . $e->getMessage());
            return $default;
        }

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return opnmgr_decrypt((string)$value) ?? $default;
    }
}

if (!function_exists('save_secret_setting')) {
    /**
     * Write a credential to the settings table, encrypted.
     */
    function save_secret_setting(string $name, string $value): void {
        db()->prepare(
            'INSERT INTO settings (`name`,`value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        )->execute([$name, opnmgr_encrypt($value)]);
    }
}

if (!function_exists('decrypt_settings_map')) {
    /**
     * Decrypt the credential entries of a settings name=>value map.
     *
     * Many pages load the whole settings table in one
     * fetchAll(PDO::FETCH_KEY_PAIR) call; passing the result through this keeps
     * those pages working without rewriting each one to fetch individually.
     *
     * @param array<string,string> $rows
     * @return array<string,string>
     */
    function decrypt_settings_map(array $rows): array {
        foreach (opnmgr_secret_settings() as $name) {
            if (isset($rows[$name]) && $rows[$name] !== '') {
                $rows[$name] = opnmgr_decrypt((string)$rows[$name]) ?? '';
            }
        }
        return $rows;
    }
}

if (!function_exists('get_firewall_ssh_private_key')) {
    /**
     * Decrypted SSH private key for a firewall.
     *
     * The stored value is base64 of the PEM (that predates encryption); this
     * returns the base64 form so existing callers can keep calling
     * base64_decode() on it.
     *
     * @param array|string|null $firewallOrValue Firewall row or the raw column
     */
    function get_firewall_ssh_private_key($firewallOrValue): string {
        $value = is_array($firewallOrValue)
            ? ($firewallOrValue['ssh_private_key'] ?? '')
            : ($firewallOrValue ?? '');

        if ($value === '' || $value === null) {
            return '';
        }

        return opnmgr_decrypt((string)$value) ?? '';
    }
}

if (!function_exists('get_active_ai_credentials')) {
    /**
     * Active AI provider with its API key decrypted.
     *
     * @return array|null
     */
    function get_active_ai_credentials(): ?array {
        try {
            $row = db()->query(
                'SELECT id, provider, api_key, model FROM ai_settings WHERE is_active = 1 ORDER BY updated_at DESC LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('OPNMGR: could not read ai_settings: ' . $e->getMessage());
            return null;
        }

        if (!$row) {
            return null;
        }

        $row['api_key'] = opnmgr_decrypt((string)$row['api_key']) ?? '';
        return $row;
    }
}
