<?php
/**
 * Building the shell command that installs a manager public key on a firewall.
 *
 * One place, because both callers got it wrong: api/auto_onboard_firewall.php
 * shipped a hard-coded root key (and skipped deployment whenever any line
 * carried its comment), and scripts/manage_ssh_keys.php quoted the key with
 * addslashes() inside double quotes, which does not neutralise $ or backticks.
 *
 * @since 3.81.3
 */

if (!function_exists('opnmgr_valid_public_key')) {
    function opnmgr_valid_public_key(string $key): bool {
        $key = trim($key);
        return strlen($key) <= 4096
            && preg_match('#^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521)) [A-Za-z0-9+/]+={0,3}( [A-Za-z0-9@._-]{1,128})?$#', $key) === 1;
    }
}

if (!function_exists('opnmgr_authorized_key_command')) {
    /**
     * Idempotent: appends the exact line once. Returns null for anything that
     * is not a single well-formed public key line.
     */
    function opnmgr_authorized_key_command(string $publicKey, string $marker = 'SSH_KEY_DEPLOYED'): ?string {
        $publicKey = trim($publicKey);
        if (!opnmgr_valid_public_key($publicKey) || !preg_match('/^[A-Z_]{1,32}$/', $marker)) {
            return null;
        }
        $q = escapeshellarg($publicKey);
        return 'mkdir -p /root/.ssh && chmod 700 /root/.ssh && touch /root/.ssh/authorized_keys'
            . ' && (grep -qxF ' . $q . ' /root/.ssh/authorized_keys || printf \'%s\n\' ' . $q . ' >> /root/.ssh/authorized_keys)'
            . ' && chmod 600 /root/.ssh/authorized_keys && echo ' . $marker;
    }
}
