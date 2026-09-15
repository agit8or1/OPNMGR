<?php
/**
 * The manager's enrollment SSH key.
 *
 * simple_enroll.sh appends a public key to root's authorized_keys on every
 * firewall it enrolls. That key used to be a literal in the script - and not
 * even a dedicated one: it was the per-firewall key generated for firewall 21
 * on the maintainer's own installation. Two consequences, both bad. Any other
 * self-hosted deployment authorised the maintainer's key for root on its
 * customers' firewalls, and every firewall enrolled by a single installation
 * trusted a key minted for a different firewall.
 *
 * The key is now this installation's own, generated on first use and kept
 * outside the document root. If it cannot be produced, enrollment refuses
 * rather than shipping a key the operator does not control.
 *
 * @since 3.30.0
 */

if (!function_exists('opnmgr_enrollment_key_dir')) {
    /** Directory holding the enrollment keypair. Outside the document root. */
    function opnmgr_enrollment_key_dir(): string
    {
        $dir = (string) (getenv('OPNMGR_KEY_DIR') ?: '');
        if ($dir === '') {
            $dir = is_dir('/etc/opnmgr/keys') ? '/etc/opnmgr/keys' : dirname(__DIR__) . '/keys';
        }
        return rtrim($dir, '/');
    }
}

if (!function_exists('opnmgr_enrollment_public_key')) {
    /**
     * This installation's enrollment public key, as a single authorized_keys line.
     *
     * Generates the pair on first call. Returns '' if it is neither present nor
     * creatable, which callers must treat as "cannot enroll".
     */
    function opnmgr_enrollment_public_key(): string
    {
        $dir     = opnmgr_enrollment_key_dir();
        $private = $dir . '/opnmgr_enrollment';
        $public  = $private . '.pub';

        if (is_readable($public)) {
            $key = trim((string) file_get_contents($public));
            if ($key !== '') {
                return $key;
            }
        }

        if (!is_dir($dir) || !is_writable($dir)) {
            error_log('OPNMGR: enrollment key directory is not writable: ' . $dir);
            return '';
        }

        // ssh-keygen refuses to overwrite, so clear a half-written pair first.
        foreach ([$private, $public] as $stale) {
            if (file_exists($stale)) {
                @unlink($stale);
            }
        }

        $cmd = sprintf(
            'ssh-keygen -t ed25519 -N %s -C %s -f %s 2>&1',
            escapeshellarg(''),
            escapeshellarg('opnmgr-enrollment'),
            escapeshellarg($private)
        );
        exec($cmd, $output, $status);

        if ($status !== 0 || !is_readable($public)) {
            error_log('OPNMGR: could not generate an enrollment key: ' . implode(' ', $output));
            return '';
        }

        @chmod($private, 0600);
        @chmod($public, 0644);

        return trim((string) file_get_contents($public));
    }
}
