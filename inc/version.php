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
if (!defined('APP_VERSION_DATE')) { define('APP_VERSION_DATE', '2026-02-09'); }
if (!defined('APP_VERSION_NAME')) { define('APP_VERSION_NAME', 'Security hardening release'); }

if (!defined('AGENT_VERSION')) { define('AGENT_VERSION', '1.4.0'); }
if (!defined('AGENT_VERSION_DATE')) { define('AGENT_VERSION_DATE', '2025-10-20'); }
if (!defined('AGENT_MIN_VERSION')) { define('AGENT_MIN_VERSION', '1.3.0'); } // Minimum supported agent version

if (!defined('DATABASE_VERSION')) { define('DATABASE_VERSION', '1.4.0'); }
if (!defined('API_VERSION')) { define('API_VERSION', '1.1.0'); }
if (!defined('TUNNEL_PROXY_VERSION')) { define('TUNNEL_PROXY_VERSION', '2.0.2'); }

// System information
define('PHP_MIN_VERSION', '8.0');
define('BOOTSTRAP_VERSION', '5.3.3');
define('JQUERY_VERSION', '3.7.0');

// Changelog entries (most recent first)
function getChangelogEntries($limit = 10) {
    return [
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

// Get system health summary
function getSystemHealth() {
    global $DB;
    
    $health = [
        'status' => 'healthy',
        'checks' => []
    ];
    
    try {
        // Check database
        $stmt = $DB->query('SELECT COUNT(*) as count FROM firewalls');
        $result = $stmt->fetch();
        $health['checks']['database'] = [
            'status' => 'ok',
            'message' => $result['count'] . ' firewalls registered'
        ];
        
        // Check active agents
        $stmt = $DB->query('SELECT COUNT(*) as count FROM firewalls WHERE status = "online" AND last_checkin > DATE_SUB(NOW(), INTERVAL 5 MINUTE)');
        $result = $stmt->fetch();
        $health['checks']['active_agents'] = [
            'status' => $result['count'] > 0 ? 'ok' : 'warning',
            'message' => $result['count'] . ' active agent(s)'
        ];
        
        // Check for old agent versions
        $stmt = $DB->query("SELECT COUNT(DISTINCT fa.firewall_id) as count FROM firewall_agents fa WHERE fa.agent_version < '" . AGENT_MIN_VERSION . "' AND fa.status = 'online'");
        $result = $stmt->fetch();
        $health['checks']['agent_versions'] = [
            'status' => $result['count'] == 0 ? 'ok' : 'warning',
            'message' => $result['count'] == 0 ? 'All agents up to date' : $result['count'] . ' firewall(s) with outdated agents'
        ];
        
    } catch (Exception $e) {
        $health['status'] = 'error';
        $health['checks']['database'] = [
            'status' => 'error',
            'message' => 'Database connection failed'
        ];
    }
    
    return $health;
}
?>
