<?php
/**
 * OPNManager Version Management
 * Single source of truth for all version information
 * Version is read from VERSION file to avoid hardcoding
 */

// Read version from VERSION file
$version_file = __DIR__ . '/../VERSION';
$app_version = file_exists($version_file) ? trim(file_get_contents($version_file)) : '2.2.3';

if (!defined('APP_NAME')) { define('APP_NAME', 'OPNManager'); }
if (!defined('APP_VERSION')) { define('APP_VERSION', $app_version); }
if (!defined('APP_VERSION_DATE')) { define('APP_VERSION_DATE', '2026-02-22'); }
if (!defined('APP_VERSION_NAME')) { define('APP_VERSION_NAME', 'Comprehensive Screenshots & Documentation'); }

if (!defined('AGENT_VERSION')) { define('AGENT_VERSION', '1.4.0'); }
if (!defined('AGENT_VERSION_DATE')) { define('AGENT_VERSION_DATE', '2025-10-20'); }
if (!defined('AGENT_MIN_VERSION')) { define('AGENT_MIN_VERSION', '1.3.0'); } // Minimum supported agent version

if (!defined('DATABASE_VERSION')) { define('DATABASE_VERSION', '1.4.0'); }
if (!defined('API_VERSION')) { define('API_VERSION', '1.1.0'); }
if (!defined('TUNNEL_PROXY_VERSION')) { define('TUNNEL_PROXY_VERSION', '2.1.0'); }

// System information
define('PHP_MIN_VERSION', '8.0');
define('BOOTSTRAP_VERSION', '5.3.3');
define('JQUERY_VERSION', '3.7.0');

// Changelog entries (most recent first)
function getChangelogEntries($limit = 10) {
    return [
        [
            'version' => '3.7.0',
            'date' => '2026-02-12',
            'type' => 'minor',
            'title' => 'Queue Auto-Cleanup & Data Retention',
            'changes' => [
                'NEW: Automatic purge of old command queue records (completed >7d, failed/cancelled >14d)',
                'NEW: System health check on About page (database, queue, agents, disk)',
                'NEW: Global queue summary with all status counts across all firewalls',
                'NEW: Purge Old Records button in Queue Management with purgeable count',
                'NEW: Stuck command indicator badge in Queue Management',
                'FIXED: About page 500 error - missing getSystemHealth() function',
                'IMPROVED: Queue Management summary now shows sent/cancelled counts',
                'IMPROVED: Cron cleanup now runs in two phases: stuck recovery + data purge'
            ]
        ],
        [
            'version' => '3.6.0',
            'date' => '2026-02-11',
            'type' => 'minor',
            'title' => 'Configurable Per-Firewall Speedtest Intervals',
            'changes' => [
                'NEW: Per-firewall speedtest interval setting (2h, 4h, 8h, 12h, 24h, or disabled)',
                'NEW: Speedtest interval dropdown in firewall Configuration section',
                'IMPROVED: Scheduler now uses interval-based logic instead of random daily scheduling',
                'IMPROVED: Deduplication prevents queuing speedtests when one is already pending',
                'Database: Added speedtest_interval_hours column (default: 4 hours)'
            ]
        ],
        [
            'version' => '2.2.3',
            'date' => '2025-12-11',
            'type' => 'patch',
            'title' => 'Tunnel Proxy HTTPS Protocol Fixes',
            'changes' => [
                'FIXED: Tunnel proxy "Empty reply from server" errors after login',
                'FIXED: tunnel_proxy.php now uses HTTPS for port 443 connections',
                'FIXED: Redirect handler (line 414) now uses correct protocol',
                'FIXED: Initial curl_init (line 122) protocol detection',
                'FIXED: Duplicate SSH tunnel process prevention',
                'FIXED: Agent stability on home.agit8or.net (FW 48)',
                'UPDATED: tunnel_proxy.php to v2.0.2',
                'UPDATED: Version management - APP_VERSION now reads from VERSION file',
                'IMPROVED: All version numbers now centralized and non-hardcoded'
            ]
        ],
        [
            'version' => '2.3.1',
            'date' => '2025-11-01',
            'type' => 'patch',
            'title' => 'Architecture Simplification & 2FA Improvements',
            'changes' => [
                'REMOVED: Separate update agent - simplified to single unified agent architecture',
                'UPDATED: Agent version to 3.7.3 with improved uptime parsing',
                'FIXED: 2FA QR code generation - now uses proper Base32 encoding (TOTP standard)',
                'FIXED: 2FA issuer name changed from "OPNsense" to "OPNmgr"',
                'FIXED: Content Security Policy to allow QR code API (api.qrserver.com)',
                'FIXED: About page contrast issues - changed text-muted to text-secondary',
                'FIXED: Timezone selector session conflicts - removed duplicate session_start()',
                'FIXED: Uptime display for multi-day uptimes (agent regex bug fixed)',
                'IMPROVED: Simplified version management - removed unused update agent constants',
                'IMPROVED: Documentation updated to reflect single agent architecture'
            ]
        ],
        [
            'version' => '2.3.0',
            'date' => '2025-10-30',
            'type' => 'minor',
            'title' => 'Advanced Monitoring & Graph Infrastructure',
            'changes' => [
                'NEW: Latency monitoring system with database storage',
                'NEW: SpeedTest infrastructure with scheduled/on-demand testing',
                'NEW: Real-time graph endpoints for latency and speedtest data',
                'NEW: System statistics graphs (CPU, Memory, Disk usage)',
                'NEW: Traffic statistics with proper rate calculation',
                'FIXED: API authentication - endpoints now return JSON errors instead of redirects',
                'FIXED: JavaScript fetch() calls now include credentials for session cookies',
                'FIXED: Graph data loading - all endpoints properly authenticated',
                'IMPROVED: Consistent API error handling across all endpoints',
                'Database Schema v1.4.0: Added firewall_latency and firewall_speedtest tables'
            ]
        ],
        [
            'version' => '2.1.0',
            'date' => '2025-10-24',
            'type' => 'minor',
            'title' => 'Security Features & Bug Fixes',
            'changes' => [
                'NEW: Secure Outbound Lockdown feature - restrict all outbound to HTTP/HTTPS only',
                'NEW: Forced DNS through Unbound with logging',
                'NEW: Comprehensive secure lockdown documentation with 6+ use cases',
                'FIXED: Critical tunnel URL bug - missing / in path construction',
                'FIXED: Backup upload field name mismatch (backup vs backup_file)',
                'FIXED: Backup command path (opnsense-backup → /conf/config.xml)',
                'FIXED: Cookie aggressive auto-deletion preventing login',
                'FIXED: Tunnel speed - removed 2s blocking wait, now 2-3 seconds',
                'FIXED: Orphaned SSH tunnel cleanup with kill -9',
                'FIXED: Network Tools HTML formatting and card nesting',
                'FIXED: Deployment package delete button functionality',
                'NEW: Settings page for housekeeping/scheduled tasks management',
                'NEW: AI reports grade explanations and full report display',
                'IMPROVED: All cron tasks visible in Administration settings',
                'Database Schema v1.3.0: Added secure_outbound_lockdown column'
            ]
        ],
        [
            'version' => '2.0.0',
            'date' => '2025-10-10',
            'type' => 'major',
            'title' => 'Enhanced Command Execution & Base64 Encoding',
            'changes' => [
                'MILESTONE: v2.0 production release',
                'NOTE: Dual-agent system (v2.0-2.3.0) was deprecated in v2.3.1',
                'NEW: Agent v3.2.0 with base64 command encoding',
                'FIXED: Multi-line command execution (base64 encoding prevents pipe parsing issues)',
                'FIXED: Version display parsing JSON correctly (shows "25.7.4" not raw JSON)',
                'FIXED: UI firewall display improvements',
                'IMPROVED: Agent tracking with firewall_agents table',
                'IMPROVED: Command queue now supports complex multi-line scripts'
            ]
        ],
        [
            'version' => '1.0.0',
            'date' => '2025-10-09',
            'type' => 'major',
            'title' => 'Production Ready - v1.0 Release',
            'changes' => [
                'MILESTONE: OPNManager reaches v1.0 production stability',
                'FIXED: Edit Firewall - tag_names column error (use firewall_tags junction table)',
                'FIXED: Edit Firewall - variable name bugs ($hostname not $name)',
                'FIXED: Add Firewall page - brightness/contrast issues (dark theme)',
                'IMPROVED: Centralized version management system',
                'IMPROVED: Tag management using proper many-to-many relationships'
            ]
        ]
    ];
}

// Get system health status for about page (guarded to avoid conflict with api/health_monitor.php)
if (!function_exists('getSystemHealth')) {
function getSystemHealth() {
    $checks = [];
    $overall = 'healthy';

    // Database connectivity
    try {
        db()->query("SELECT 1");
        $checks['database'] = ['status' => 'ok', 'message' => 'Connected'];
    } catch (Exception $e) {
        $checks['database'] = ['status' => 'error', 'message' => 'Connection failed'];
        $overall = 'unhealthy';
    }

    // Command queue health
    try {
        $stmt = db()->prepare("
            SELECT
                SUM(CASE WHEN status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 1 ELSE 0 END) as stuck,
                SUM(CASE WHEN status = 'failed' AND completed_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) as recent_failures
            FROM firewall_commands
        ");
        $stmt->execute();
        $q = $stmt->fetch(PDO::FETCH_ASSOC);
        $stuck = (int)$q['stuck'];
        $failures = (int)$q['recent_failures'];
        if ($stuck > 5) {
            $checks['command_queue'] = ['status' => 'error', 'message' => "$stuck stuck commands"];
            $overall = 'unhealthy';
        } elseif ($stuck > 0 || $failures > 10) {
            $checks['command_queue'] = ['status' => 'warning', 'message' => "$stuck stuck, $failures recent failures"];
        } else {
            $checks['command_queue'] = ['status' => 'ok', 'message' => 'Healthy'];
        }
    } catch (Exception $e) {
        $checks['command_queue'] = ['status' => 'warning', 'message' => 'Unable to check'];
    }

    // Firewall agents
    try {
        $stmt = db()->query("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'online' AND last_checkin > DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 1 ELSE 0 END) as online
            FROM firewalls
        ");
        $f = $stmt->fetch(PDO::FETCH_ASSOC);
        $total = (int)$f['total'];
        $online = (int)$f['online'];
        if ($total > 0 && $online === 0) {
            $checks['firewall_agents'] = ['status' => 'error', 'message' => "0/$total online"];
            $overall = 'unhealthy';
        } elseif ($online < $total) {
            $checks['firewall_agents'] = ['status' => 'warning', 'message' => "$online/$total online"];
        } else {
            $checks['firewall_agents'] = ['status' => 'ok', 'message' => "$online/$total online"];
        }
    } catch (Exception $e) {
        $checks['firewall_agents'] = ['status' => 'warning', 'message' => 'Unable to check'];
    }

    // Disk space
    $free_pct = disk_free_space('/') / disk_total_space('/') * 100;
    if ($free_pct < 5) {
        $checks['disk_space'] = ['status' => 'error', 'message' => sprintf('%.0f%% free', $free_pct)];
        $overall = 'unhealthy';
    } elseif ($free_pct < 15) {
        $checks['disk_space'] = ['status' => 'warning', 'message' => sprintf('%.0f%% free', $free_pct)];
    } else {
        $checks['disk_space'] = ['status' => 'ok', 'message' => sprintf('%.0f%% free', $free_pct)];
    }

    return ['status' => $overall, 'checks' => $checks];
}
} // end function_exists('getSystemHealth')

// Get version info array
function getVersionInfo() {
    return [
        'app' => [
            'name' => APP_NAME,
            'version' => APP_VERSION,
            'date' => APP_VERSION_DATE,
            'codename' => APP_VERSION_NAME
        ],
        'agent' => [
            'version' => AGENT_VERSION,
            'date' => AGENT_VERSION_DATE,
            'min_supported' => AGENT_MIN_VERSION
        ],
        'tunnel_proxy' => [
            'version' => TUNNEL_PROXY_VERSION
        ],
        'database' => [
            'version' => DATABASE_VERSION
        ],
        'api' => [
            'version' => API_VERSION
        ],
        'dependencies' => [
            'php_min' => PHP_MIN_VERSION,
            'php_current' => PHP_VERSION,
            'bootstrap' => BOOTSTRAP_VERSION,
            'jquery' => JQUERY_VERSION
        ]
    ];
}
?>
