<?php
/**
 * Block direct web execution of maintenance scripts.
 *
 * scripts/ and cron/ live inside the document root, and the nginx config
 * matches `location ~ \.php$` for the whole tree, so every maintenance script
 * was directly executable over HTTP - SSH key management, tunnel setup,
 * backups and agent resets among them.
 *
 * The nginx configuration shipped with 3.12.0 denies those paths, but a
 * deployment can have a stale or hand-edited vhost, so the application refuses
 * as well rather than relying on the web server alone.
 *
 * Several of these files are also legitimately require_once'd by web pages
 * (api/ssh_tunnel.php pulls in scripts/manage_ssh_tunnel.php, for example), so
 * this only rejects a request whose *entry point* is the guarded file.
 *
 * @since 3.12.0
 */

if (!function_exists('opnmgr_block_direct_web_access')) {
    /**
     * Refuse the request when $file is the script being executed over HTTP.
     *
     * @param string $file Always __FILE__ from the calling script
     */
    function opnmgr_block_direct_web_access(string $file): void {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $entry = $_SERVER['SCRIPT_FILENAME'] ?? '';
        if ($entry === '') {
            return;
        }

        $entryReal = realpath($entry);
        $fileReal  = realpath($file);

        if ($entryReal !== false && $fileReal !== false && $entryReal === $fileReal) {
            error_log(sprintf(
                'OPNMGR: blocked direct web execution of %s from %s',
                basename($file),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ));
            http_response_code(404);
            exit;
        }
    }
}
