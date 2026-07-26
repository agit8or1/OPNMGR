<?php
/**
 * Shared health score calculation for firewalls.
 * Used by dashboard.php and firewalls.php.
 */

if (!function_exists('calculateHealthScore')) {
function calculateHealthScore($firewall) {
    $health_score = 0;

    // Connectivity Score (30 points)
    $status = 'unknown';
    if (!empty($firewall['agent_last_checkin'])) {
        $checkin_time = strtotime($firewall['agent_last_checkin']);
        $minutes_ago = (time() - $checkin_time) / 60;
        $status = ($minutes_ago <= 10) ? 'online' : 'offline';
    }

    if ($status === 'online') {
        $checkin_time = strtotime($firewall['agent_last_checkin']);
        $minutes_ago = (time() - $checkin_time) / 60;
        if ($minutes_ago <= 5) {
            $health_score += 30;
        } elseif ($minutes_ago <= 15) {
            $health_score += 27;
        } elseif ($minutes_ago <= 60) {
            $health_score += 20;
        } else {
            $health_score += 12;
        }
    }

    // Agent Version Score (20 points)
    if (!empty($firewall['agent_version'])) {
        $agent_version = $firewall['agent_version'];
        if (version_compare($agent_version, AGENT_VERSION, '>=')) {
            $health_score += 20;
        } elseif (version_compare($agent_version, AGENT_MIN_VERSION, '>=')) {
            $health_score += 18;
        } elseif (version_compare($agent_version, '1.0.0', '>=')) {
            $health_score += 14;
        } else {
            $health_score += 8;
        }
    }

    // System Updates Score (20 points)
    if (isset($firewall['updates_available'])) {
        if ($firewall['updates_available'] == 0) {
            $health_score += 20;
        } elseif ($firewall['updates_available'] == 1) {
            $health_score += 10;
        } else {
            $health_score += 8;
        }
    } else {
        $health_score += 8;
    }

    // Uptime Score (15 points)
    if (!empty($firewall['uptime'])) {
        $uptime = $firewall['uptime'];
        if (preg_match('/(\d+)\s*days?/', $uptime, $matches) || preg_match('/up\s+(\d+)/', $uptime, $matches)) {
            $days = (int)$matches[1];
            if ($days >= 7) {
                $health_score += 15;
            } elseif ($days >= 3) {
                $health_score += 12;
            } else {
                $health_score += 8;
            }
        } else {
            $health_score += 5;
        }
    }

    // Configuration Score (15 points)
    $config_score = 0;
    if (!empty($firewall['version'])) $config_score += 5;
    if (!empty($firewall['wan_ip'])) $config_score += 5;
    if (!empty($firewall['lan_ip'])) $config_score += 5;
    $health_score += $config_score;

    return min($health_score, 100);
}
}

/**
 * Human-readable relative time (e.g., "2m ago", "3h ago", "1d ago")
 */
if (!function_exists('timeAgo')) {
function timeAgo($datetime) {
    if (empty($datetime)) return 'Never';
    $time = strtotime($datetime);
    if ($time === false) return 'Unknown';
    $diff = time() - $time;
    if ($diff < 0) return 'Just now';
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}
}
