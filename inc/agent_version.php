<?php
// OPNManager Agent Version Configuration
// Update this when releasing a new agent version

define('LATEST_AGENT_VERSION', '1.5.5');
define('AGENT_DOWNLOAD_URL', 'https://opn.agit8or.net/downloads/plugins/install_opnmanager_agent.sh');

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
