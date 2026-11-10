<?php
/**
 * OPNMGR Secret Encryption
 *
 * Authenticated encryption for secrets held at rest in the database:
 * firewall API keys/secrets, SSH private keys, AI provider keys, SMTP and
 * webhook credentials, enrollment secrets and MFA recovery codes.
 *
 * Design notes
 * ------------
 * - XChaCha20-Poly1305 (libsodium AEAD) with a random 24-byte nonce per value.
 * - The master key lives in .env as OPNMGR_MASTER_KEY, never in the database,
 *   so a database-only compromise (SQL injection, stolen dump, replica) does
 *   not yield plaintext secrets.
 * - Ciphertext is stored as  enc:v1:<base64(nonce || ciphertext)>  so that
 *   encrypted and legacy plaintext values can coexist in the same column
 *   during migration. opnmgr_decrypt() passes plaintext through unchanged,
 *   which lets the application read pre-encryption rows until
 *   scripts/encrypt_secrets.php has run (and lets an operator recover if the
 *   key is ever lost). New secrets are never written in plaintext: without a
 *   usable key opnmgr_encrypt() throws (3.80.3).
 * - Passwords are NOT encrypted anywhere: they are hashed with password_hash().
 *   Encryption is only for values the application must be able to read back.
 *
 * @since 3.12.0
 */

if (!defined('OPNMGR_CRYPTO_PREFIX')) {
    define('OPNMGR_CRYPTO_PREFIX', 'enc:v1:');
}

if (!class_exists('OpnmgrCryptoUnavailable')) {
    /**
     * Thrown by opnmgr_encrypt() when no usable master key is configured.
     * Callers that store a secret catch it and tell the user what to fix;
     * nothing is written in plaintext instead (before 3.80.3 it was).
     */
    class OpnmgrCryptoUnavailable extends RuntimeException {}
}

if (!function_exists('opnmgr_crypto_status')) {
    /**
     * Why secrets can or cannot be encrypted, for refusals and the admin banner.
     *
     * @return array{ok:bool, reason:string, message:string}
     *         reason: ok | no_sodium | missing_key | malformed_key
     */
    function opnmgr_crypto_status(): array {
        if (!extension_loaded('sodium')) {
            return ['ok' => false, 'reason' => 'no_sodium',
                'message' => 'The PHP sodium extension is not loaded, so secrets cannot be encrypted. Install php-sodium and restart PHP.'];
        }
        $raw = getenv('OPNMGR_MASTER_KEY') ?: ($_ENV['OPNMGR_MASTER_KEY'] ?? '');
        if (!is_string($raw) || trim($raw) === '') {
            return ['ok' => false, 'reason' => 'missing_key',
                'message' => 'OPNMGR_MASTER_KEY is not set, so secrets cannot be encrypted. Run: php scripts/generate_master_key.php'];
        }
        if (opnmgr_master_key() === null) {
            return ['ok' => false, 'reason' => 'malformed_key',
                'message' => 'OPNMGR_MASTER_KEY is set but is not a valid 32-byte base64 or 64-character hex key, so secrets cannot be encrypted. Fix it in .env (php scripts/generate_master_key.php writes a valid one).'];
        }
        return ['ok' => true, 'reason' => 'ok', 'message' => ''];
    }
}

if (!function_exists('opnmgr_crypto_available')) {
    /**
     * Whether libsodium and a usable master key are both present.
     */
    function opnmgr_crypto_available(): bool {
        return opnmgr_crypto_status()['ok'];
    }
}

if (!function_exists('opnmgr_master_key')) {
    /**
     * Resolve the 32-byte master key from OPNMGR_MASTER_KEY.
     *
     * Accepts base64 (preferred, as written by scripts/generate_master_key.php)
     * or 64 hex characters. Returns null when unset or malformed, in which case
     * opnmgr_encrypt() refuses to store secrets (see opnmgr_crypto_status()).
     *
     * @return string|null Raw 32-byte key
     */
    function opnmgr_master_key(): ?string {
        static $key = false; // false = not yet resolved, null = unavailable

        if ($key !== false) {
            return $key;
        }

        $raw = getenv('OPNMGR_MASTER_KEY') ?: ($_ENV['OPNMGR_MASTER_KEY'] ?? '');
        $raw = is_string($raw) ? trim($raw) : '';

        if ($raw === '') {
            $key = null;
            return $key;
        }

        // 64 hex chars
        if (strlen($raw) === 64 && ctype_xdigit($raw)) {
            $key = hex2bin($raw);
            return $key;
        }

        // 32 = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, written as a
        // literal so a missing sodium extension is reported, not a fatal error.
        $decoded = base64_decode($raw, true);
        if ($decoded !== false && strlen($decoded) === 32) {
            $key = $decoded;
            return $key;
        }

        error_log('OPNMGR: OPNMGR_MASTER_KEY is set but is not a valid 32-byte base64 or hex key; new secrets will be refused until it is fixed.');
        $key = null;
        return $key;
    }
}

if (!function_exists('opnmgr_is_encrypted')) {
    /**
     * Whether a stored value is in OPNMGR ciphertext form.
     */
    function opnmgr_is_encrypted(?string $value): bool {
        return $value !== null && str_starts_with($value, OPNMGR_CRYPTO_PREFIX);
    }
}

if (!function_exists('opnmgr_encrypt')) {
    /**
     * Encrypt a secret for storage.
     *
     * Returns the input unchanged when it is null/empty (nothing to protect) or
     * already encrypted (so callers can encrypt idempotently). Throws
     * OpnmgrCryptoUnavailable when the master key is missing or malformed or
     * libsodium is absent: a secret is never stored in plaintext.
     *
     * @param string|null $plaintext
     * @return string|null
     * @throws OpnmgrCryptoUnavailable
     */
    function opnmgr_encrypt(?string $plaintext): ?string {
        if ($plaintext === null || $plaintext === '') {
            return $plaintext;
        }
        if (opnmgr_is_encrypted($plaintext)) {
            return $plaintext;
        }

        $status = opnmgr_crypto_status();
        if (!$status['ok']) {
            error_log('OPNMGR: refused to store a secret: ' . $status['message']);
            throw new OpnmgrCryptoUnavailable('Refusing to store a secret unencrypted. ' . $status['message']);
        }
        $key = opnmgr_master_key();

        $nonce      = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, '', $nonce, $key);

        return OPNMGR_CRYPTO_PREFIX . base64_encode($nonce . $ciphertext);
    }
}

if (!function_exists('opnmgr_decrypt')) {
    /**
     * Decrypt a stored secret.
     *
     * Legacy plaintext values (written before encryption was introduced, or
     * while no master key was configured) are returned unchanged so that reads
     * keep working throughout the migration window.
     *
     * @param string|null $stored
     * @return string|null Plaintext, or null when the value cannot be decrypted
     */
    function opnmgr_decrypt(?string $stored): ?string {
        if ($stored === null || $stored === '') {
            return $stored;
        }
        if (!opnmgr_is_encrypted($stored)) {
            return $stored; // legacy plaintext
        }

        $key = opnmgr_master_key();
        if ($key === null || !extension_loaded('sodium')) {
            error_log('OPNMGR: encrypted value encountered but OPNMGR_MASTER_KEY is unavailable.');
            return null;
        }

        $blob = base64_decode(substr($stored, strlen(OPNMGR_CRYPTO_PREFIX)), true);
        if ($blob === false || strlen($blob) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            error_log('OPNMGR: malformed ciphertext encountered while decrypting a stored secret.');
            return null;
        }

        $nonce      = substr($blob, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = substr($blob, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, '', $nonce, $key);
        if ($plaintext === false) {
            // Wrong key, or the value was tampered with.
            error_log('OPNMGR: failed to authenticate a stored secret (wrong OPNMGR_MASTER_KEY or tampered ciphertext).');
            return null;
        }

        return $plaintext;
    }
}

if (!function_exists('opnmgr_mask_secret')) {
    /**
     * Render a secret for display without revealing it.
     *
     * Used by settings screens that need to show whether a credential is
     * configured without sending the credential itself to the browser.
     *
     * @param string|null $plaintext
     * @param int         $visible   Trailing characters to keep visible
     */
    function opnmgr_mask_secret(?string $plaintext, int $visible = 4): string {
        if ($plaintext === null || $plaintext === '') {
            return '';
        }
        $len = strlen($plaintext);
        if ($len <= $visible) {
            return str_repeat('•', $len);
        }
        return str_repeat('•', min(12, $len - $visible)) . substr($plaintext, -$visible);
    }
}

if (!function_exists('opnmgr_random_secret')) {
    /**
     * Generate a URL-safe random secret.
     *
     * @param int $bytes Entropy in bytes (default 32 = 256 bits)
     */
    function opnmgr_random_secret(int $bytes = 32): string {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
