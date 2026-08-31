<?php
/**
 * Shared health score calculation for firewalls.
 * Single source of truth used by dashboard.php and firewalls.php.
 *
 * Scoring model (100 points):
 *   Connectivity ....... 25   how recently the agent checked in
 *   Patch level ........ 30   pending OPNsense updates / major upgrades
 *   Agent version ...... 15   OPNManager agent currency
 *   Stability .......... 15   uptime + pending-reboot state
 *   Configuration ...... 15   inventory completeness
 *
 * Patch level is deliberately the heaviest component: a firewall with
 * pending updates must never grade the same as a fully patched one.
 * Uptime is a small stability signal only - it can no longer offset a
 * missing patch, and an extremely long uptime is itself a mild penalty
 * (reboot/patch overdue) rather than a bonus.
 */

require_once __DIR__ . '/version.php';
if (file_exists(__DIR__ . '/agent_version.php')) {
    require_once __DIR__ . '/agent_version.php';
}

/**
 * Version the agent is expected to be running.
 * AGENT_VERSION (inc/version.php) is the single source of truth; LATEST_AGENT_VERSION
 * is an alias of it. Enforced against the released tarball by scripts/check_versions.php.
 */
if (!function_exists('healthTargetAgentVersion')) {
function healthTargetAgentVersion() {
    return defined('AGENT_VERSION') ? AGENT_VERSION : '0.0.0';
}
}

/**
 * Parse an uptime string into days (float). Returns null if unparseable.
 * Handles: "13 days", "6 mins", "0d 0h 4m", "up 5 days, 3:14", "1 day, 2 hours"
 */
if (!function_exists('healthParseUptimeDays')) {
function healthParseUptimeDays($uptime) {
    if ($uptime === null || trim((string)$uptime) === '') return null;
    $u = strtolower(trim((string)$uptime));

    $days = null;

    // Compact form: 0d 0h 4m
    if (preg_match('/(\d+)\s*d(?:ays?)?\b/', $u, $m)) {
        $days = (float)$m[1];
    }
    if ($days === null && preg_match('/(\d+)\s*day/', $u, $m)) {
        $days = (float)$m[1];
    }

    $hours = 0.0;
    if (preg_match('/(\d+)\s*h(?:ours?|rs?)?\b/', $u, $m)) {
        $hours = (float)$m[1];
    }
    $mins = 0.0;
    if (preg_match('/(\d+)\s*m(?:ins?|inutes?)?\b/', $u, $m)) {
        $mins = (float)$m[1];
    }

    // "up 5 days, 03:14" / "0d 3:14"
    if (preg_match('/(\d+):(\d+)/', $u, $m)) {
        $hours = (float)$m[1];
        $mins  = (float)$m[2];
    }

    if ($days === null && $hours == 0.0 && $mins == 0.0) {
        return null;
    }

    return ($days === null ? 0.0 : $days) + ($hours / 24) + ($mins / 1440);
}
}

/**
 * Format a day count back into something readable.
 */
if (!function_exists('healthFormatUptime')) {
function healthFormatUptime($days) {
    if ($days === null) return 'unknown';
    if ($days < 1) {
        $h = (int)floor($days * 24);
        if ($h >= 1) return $h . 'h';
        return max(1, (int)round($days * 1440)) . 'm';
    }
    return round($days, 1) . ' days';
}
}

/**
 * Full health report for a firewall row.
 *
 * @param array  $firewall              row from firewalls (optionally joined with firewall_agents)
 * @param string $latest_major_version  newest OPNsense version seen in the fleet (optional)
 * @return array {score, grade, color, icon, status_label, breakdown, details, issues}
 */
if (!function_exists('calculateHealthReport')) {
function calculateHealthReport($firewall, $latest_major_version = '') {
    $score     = 0;
    $details   = [];
    $issues    = [];
    $breakdown = [];

    // ── Connectivity (25) ───────────────────────────────────────────────
    $checkin = '';
    if (!empty($firewall['agent_last_checkin'])) {
        $checkin = $firewall['agent_last_checkin'];
    } elseif (!empty($firewall['last_checkin'])) {
        $checkin = $firewall['last_checkin'];
    }

    $pts = 0;
    if ($checkin !== '' && strtotime($checkin) !== false) {
        $minutes_ago = (time() - strtotime($checkin)) / 60;
        if ($minutes_ago < 0) $minutes_ago = 0;
        if ($minutes_ago <= 5) {
            $pts = 25;
            $details[] = "✓ Excellent connectivity (checkin " . round($minutes_ago, 1) . "m ago)";
        } elseif ($minutes_ago <= 15) {
            $pts = 22;
            $details[] = "✓ Good connectivity (checkin " . round($minutes_ago, 1) . "m ago)";
        } elseif ($minutes_ago <= 60) {
            $pts = 16;
            $issues[] = "⚠ Slow checkin (" . round($minutes_ago) . "m ago)";
        } elseif ($minutes_ago <= 180) {
            $pts = 8;
            $issues[] = "⚠ Delayed checkin (" . round($minutes_ago / 60, 1) . "h ago)";
        } else {
            $pts = 0;
            $issues[] = "✗ Firewall offline (last checkin " . round($minutes_ago / 60, 1) . "h ago)";
        }
    } else {
        $issues[] = "✗ Never checked in";
    }
    $score += $pts;
    $breakdown['Connectivity'] = [$pts, 25];

    // ── Patch level (30) ────────────────────────────────────────────────
    $major_upgrade = false;
    $cur = isset($firewall['current_version']) ? $firewall['current_version'] : '';
    if (!empty($cur) && !empty($latest_major_version) && $cur !== 'Unknown') {
        $cp = explode('.', $cur);
        $lp = explode('.', $latest_major_version);
        $cur_major = (isset($cp[0]) ? (int)$cp[0] : 0) * 100 + (isset($cp[1]) ? (int)explode('_', $cp[1])[0] : 0);
        $lat_major = (isset($lp[0]) ? (int)$lp[0] : 0) * 100 + (isset($lp[1]) ? (int)explode('_', $lp[1])[0] : 0);
        $major_upgrade = $lat_major > $cur_major;
    }

    if ($major_upgrade) {
        $pts = 6;
        $issues[] = "⚠ Major upgrade available (v" . $cur . " → v" . $latest_major_version . ")";
    } elseif (!isset($firewall['updates_available']) || $firewall['updates_available'] === null) {
        $pts = 12;
        $issues[] = "⚠ Update status unknown - update check needed";
    } elseif ((int)$firewall['updates_available'] === 0) {
        $pts = 30;
        $details[] = "✓ Fully patched";
    } else {
        $pts = 14;
        $avail = !empty($firewall['available_version']) ? " (v" . $firewall['available_version'] . " available)" : "";
        $issues[] = "⚠ Pending system updates" . $avail;
    }
    $score += $pts;
    $breakdown['Patch level'] = [$pts, 30];

    // ── Agent version (15) ──────────────────────────────────────────────
    $target = healthTargetAgentVersion();
    $min    = defined('AGENT_MIN_VERSION') ? AGENT_MIN_VERSION : '1.0.0';
    $pts = 0;
    if (!empty($firewall['agent_version'])) {
        $av = $firewall['agent_version'];
        if (version_compare($av, $target, '>=')) {
            $pts = 15;
            $details[] = "✓ Agent up to date (v" . $av . ")";
        } elseif (version_compare($av, $min, '>=')) {
            $pts = 11;
            $issues[] = "⚠ Agent behind latest (v" . $av . " → v" . $target . ")";
        } elseif (version_compare($av, '1.0.0', '>=')) {
            $pts = 6;
            $issues[] = "⚠ Agent needs update (v" . $av . ", minimum supported v" . $min . ")";
        } else {
            $pts = 2;
            $issues[] = "⚠ Agent severely outdated (v" . $av . ")";
        }
    } else {
        $issues[] = "✗ No agent version reported";
    }
    $score += $pts;
    $breakdown['Agent version'] = [$pts, 15];

    // ── Stability (15) ──────────────────────────────────────────────────
    $updays = healthParseUptimeDays(isset($firewall['uptime']) ? $firewall['uptime'] : null);
    if (!empty($firewall['reboot_required'])) {
        $pts = 5;
        $issues[] = "⚠ Reboot pending (uptime " . healthFormatUptime($updays) . ")";
    } elseif ($updays === null) {
        $pts = 11;
        $issues[] = "⚠ No uptime data reported";
    } elseif ($updays >= 365) {
        $pts = 10;
        $issues[] = "⚠ Uptime " . healthFormatUptime($updays) . " - kernel patches likely unapplied";
    } elseif ($updays >= 1) {
        $pts = 15;
        $details[] = "✓ Stable (uptime " . healthFormatUptime($updays) . ")";
    } else {
        $pts = 13;
        $details[] = "✓ Recently restarted (uptime " . healthFormatUptime($updays) . ")";
    }
    $score += $pts;
    $breakdown['Stability'] = [$pts, 15];

    // ── Configuration completeness (15) ─────────────────────────────────
    $pts = 0;
    $missing = [];
    if (!empty($firewall['version'])) { $pts += 5; } else { $missing[] = 'OPNsense version'; }
    if (!empty($firewall['wan_ip'])) { $pts += 5; } else { $missing[] = 'WAN IP'; }
    if (!empty($firewall['lan_ip'])) { $pts += 5; } else { $missing[] = 'LAN IP'; }
    if ($pts === 15) {
        $details[] = "✓ Complete inventory data";
    } else {
        $issues[] = "⚠ Missing inventory data: " . implode(', ', $missing);
    }
    $score += $pts;
    $breakdown['Configuration'] = [$pts, 15];

    $score = max(0, min($score, 100));

    // ── Grade ───────────────────────────────────────────────────────────
    // Calibrated so a firewall with pending updates cannot reach an A grade.
    if ($score >= 95)      { $grade = 'A+'; $color = 'bg-success text-white'; $icon = 'fas fa-heart'; }
    elseif ($score >= 88)  { $grade = 'A';  $color = 'bg-success text-white'; $icon = 'fas fa-thumbs-up'; }
    elseif ($score >= 80)  { $grade = 'B+'; $color = 'bg-success text-white'; $icon = 'fas fa-check-circle'; }
    elseif ($score >= 72)  { $grade = 'B';  $color = 'bg-info text-white';    $icon = 'fas fa-check-circle'; }
    elseif ($score >= 62)  { $grade = 'C+'; $color = 'bg-warning text-white'; $icon = 'fas fa-exclamation-circle'; }
    elseif ($score >= 50)  { $grade = 'C';  $color = 'bg-warning text-white'; $icon = 'fas fa-exclamation-circle'; }
    elseif ($score >= 40)  { $grade = 'D';  $color = 'bg-warning text-white'; $icon = 'fas fa-exclamation-triangle'; }
    else                   { $grade = 'F';  $color = 'bg-danger text-white';  $icon = 'fas fa-exclamation-triangle'; }

    if ($score >= 95)      { $status_label = "🟢 Status: EXCELLENT - fully patched and reachable"; }
    elseif ($score >= 88)  { $status_label = "🟢 Status: GOOD - minor issues detected"; }
    elseif ($score >= 72)  { $status_label = "🟡 Status: FAIR - attention recommended"; }
    elseif ($score >= 50)  { $status_label = "🟠 Status: POOR - issues need addressing"; }
    else                   { $status_label = "🔴 Status: CRITICAL - immediate action required"; }

    return [
        'score'        => $score,
        'grade'        => $grade,
        'color'        => $color,
        'icon'         => $icon,
        'status_label' => $status_label,
        'breakdown'    => $breakdown,
        'details'      => $details,
        'issues'       => $issues,
    ];
}
}

/**
 * Numeric score only - kept for callers that just need a percentage.
 */
if (!function_exists('calculateHealthScore')) {
function calculateHealthScore($firewall, $latest_major_version = '') {
    $report = calculateHealthReport($firewall, $latest_major_version);
    return $report['score'];
}
}

/**
 * Render the multi-line tooltip shown on the health badge.
 */
if (!function_exists('buildHealthTooltip')) {
function buildHealthTooltip($report) {
    $t  = "🏥 FIREWALL HEALTH REPORT\n";
    $t .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $t .= "📊 Overall Score: " . $report['score'] . "/100 (Grade " . $report['grade'] . ")\n\n";
    $t .= $report['status_label'] . "\n\n";

    $t .= "🧮 SCORE BREAKDOWN:\n";
    foreach ($report['breakdown'] as $label => $pair) {
        $t .= "  • " . str_pad($label, 15) . $pair[0] . "/" . $pair[1] . "\n";
    }
    $t .= "\n";

    if (!empty($report['details'])) {
        $t .= "✅ HEALTHY COMPONENTS:\n";
        foreach ($report['details'] as $d) { $t .= "  • " . $d . "\n"; }
        $t .= "\n";
    }
    if (!empty($report['issues'])) {
        $t .= "⚠️ AREAS FOR IMPROVEMENT:\n";
        foreach ($report['issues'] as $i) { $t .= "  • " . $i . "\n"; }
        $t .= "\n💡 Tip: Address issues above to improve health score";
    }
    return trim($t);
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
