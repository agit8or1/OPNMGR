<?php
// OPNManager Agent Version Configuration
//
// LATEST_AGENT_VERSION is a backwards-compatible alias for AGENT_VERSION, which
// lives in inc/version.php and is the single source of truth for the newest
// installable agent. These were two hand-maintained literals that drifted apart
// (1.6.0 vs 1.5.6), so update inc/version.php - never redefine the value here.

require_once __DIR__ . '/version.php';
require_once __DIR__ . '/server_identity.php';

if (!defined('LATEST_AGENT_VERSION')) { define('LATEST_AGENT_VERSION', AGENT_VERSION); }

if (!function_exists('opnmgr_agent_download_url')) {
    /**
     * Where a firewall fetches the agent installer from.
     *
     * This was the constant AGENT_DOWNLOAD_URL, hardcoded to the maintainer's
     * own host, so every self-hosted install would have told its firewalls to
     * download the agent from a third party. It is a function now because the
     * answer depends on configuration that is not loaded yet at include time.
     *
     * Returns '' when this installation's URL is not configured; callers must
     * report that rather than emitting a broken command.
     */
    function opnmgr_agent_download_url(): string
    {
        $base = opnmgr_server_url();
        return $base === '' ? '' : $base . '/downloads/plugins/install_opnmanager_agent.sh';
    }
}

/**
 * Compare two semantic version strings
 * @param string $version1
 * @param string $version2
 * @return int Returns -1 if version1 < version2, 0 if equal, 1 if version1 > version2
 */
function compareVersions($version1, $version2) {
    $v1 = explode('.', $version1);
    $v2 = explode('.', $version2);

    for ($i = 0; $i < max(count($v1), count($v2)); $i++) {
        $num1 = isset($v1[$i]) ? (int)$v1[$i] : 0;
        $num2 = isset($v2[$i]) ? (int)$v2[$i] : 0;

        if ($num1 < $num2) return -1;
        if ($num1 > $num2) return 1;
    }

    return 0;
}

/**
 * Check if an update is available for a firewall
 * @param string $current_version
 * @return bool
 */
function isUpdateAvailable($current_version) {
    if (empty($current_version)) return false;
    return compareVersions($current_version, LATEST_AGENT_VERSION) < 0;
}
