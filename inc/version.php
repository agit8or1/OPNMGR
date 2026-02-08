<?php
/**
 * OPNManager Version Management
 * Single source of truth for all version information
 */

define('APP_NAME', 'OPNManager');
define('APP_VERSION', '2.2.0');
define('APP_VERSION_DATE', '2026-01-08');
define('APP_VERSION_NAME', 'v2.2 - WAN Auto-Detection');

define('AGENT_VERSION', '3.4.0');
define('AGENT_VERSION_DATE', '2026-01-08');
define('AGENT_MIN_VERSION', '3.2.0'); // Minimum supported agent version

define('UPDATE_AGENT_VERSION', '1.1.0');
define('UPDATE_AGENT_STATUS', 'Active - 5min intervals');

define('DATABASE_VERSION', '1.4.0'); // Added WAN interface tracking
define('API_VERSION', '1.0.0');

// System information
define('PHP_MIN_VERSION', '8.0');
define('BOOTSTRAP_VERSION', '5.3.3');
define('JQUERY_VERSION', '3.7.0');

// Changelog entries (most recent first)
function getChangelogEntries($limit = 10) {
    return [
        [
            'version' => '2.2.0',
            'date' => '2026-01-08',
            'type' => 'minor',
            'title' => 'WAN Auto-Detection & Interface Monitoring',
            'changes' => [
                'NEW: Agent v3.4.0 with automatic WAN interface detection',
                'NEW: WAN gateway group detection (load balancing/failover)',
                'NEW: Real-time interface monitoring (status, bandwidth, errors)',
                'NEW: firewall_wan_interfaces table for detailed interface tracking',
                'NEW: v_firewall_wan_status view for easy WAN status queries',
                'NEW: Per-interface statistics (packets, bytes, errors, media type)',
                'IMPROVED: Agent check-in protocol includes WAN interface data',
                'IMPROVED: download_tunnel_agent.php now serves v3.4.0 by default',
                'IMPROVED: Version-aware agent download with fallback support',
                'Database Schema v1.4.0: Added wan_interfaces, wan_groups, wan_interface_stats columns',
                'Agent auto-detects WAN interfaces from /conf/config.xml (zero config)',
                'Supports multi-WAN setups automatically',
                'Comprehensive monitoring: status (up/down), IP, speed, RX/TX stats'
            ]
        ],
        [
            'version' => '2.1.0',
            'date' => '2025-10-12',
            'type' => 'minor',
            'title' => 'Data Accuracy & UI Polish',
            'changes' => [
                'FIXED: System uptime calculation (was hardcoded "12 days, 4 hours")',
                'FIXED: Uptime display format parsing ("X hours, Y minutes")',
                'FIXED: Network data persistence (agent was overwriting with empty values)',
                'FIXED: Update detection logic (removed hardcoded version 25.7.4)',
                'FIXED: Update tooltip showing "Available" when up-to-date',
                'FIXED: Tag edit modal contrast (white on white - completely unreadable)',
                'IMPROVED: Form input contrast across entire app (3x more visible)',
                'IMPROVED: Network tooltips changed "REAL DATA" to "CURRENT DATA"',
                'NEW: Complete network configuration display in firewall details',
                'NEW: Database columns for WAN/LAN subnet, gateway, DNS (7 new columns)',
                'NEW: Agent-determined update detection (no hardcoded versions)',
                'REFACTOR: Conditional network data updates preserve existing values',
                'Database Schema v1.3.0: Added network configuration columns'
            ]
        ],
        [
            'version' => '2.0.0',
            'date' => '2025-10-10',
            'type' => 'major',
            'title' => 'Dual Agent System & Enhanced Command Execution',
            'changes' => [
                'MILESTONE: v2.0 with separate Primary and Update agents',
                'NEW: Update Agent v1.1.0 (5-minute check-ins for system updates)',
                'NEW: Primary Agent v3.2.0 with base64 command encoding',
                'FIXED: Multi-line command execution (base64 encoding prevents pipe parsing issues)',
                'FIXED: Agent intervals (Primary: 2min, Update: 5min)',
                'FIXED: Version display parsing JSON correctly (shows "25.7.4" not raw JSON)',
                'FIXED: UI duplicate firewall display (JOIN filtered to primary agent only)',
                'FIXED: Backup retention modal HTML structure',
                'IMPROVED: Separate agent tracking (firewall_agents table with composite key)',
                'IMPROVED: Command queue now supports complex multi-line scripts',
                'DOCS: Comprehensive screenshot package for website/documentation'
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
                'IMPROVED: Tag management using proper many-to-many relationships',
                'DOCS: Comprehensive CHANGELOG, QUICK_REFERENCE, SESSION_SUMMARY',
                'DOCS: Internal KNOWLEDGE_BASE.md for troubleshooting',
                'Agent v3.1.0: Stable and reliable check-ins'
            ]
        ],
        [
            'version' => '3.1.0 (Agent)',
            'date' => '2025-10-09',
            'type' => 'major',
            'title' => 'Agent v3.1 - Complete Overhaul',
            'changes' => [
                'FIXED: Agent background execution issues (curl failing silently)',
                'FIXED: 782 duplicate check-ins / 39-41 concurrent agents',
                'FIXED: PID locking now built-in from line 1',
                'FIXED: Heredoc JSON replaced with inline printf-style',
                'REMOVED: Rate limiting (proper PID locking now used)',
                'UI: Backup retention modal, dropdown z-index, "Awaiting Data" styling',
                'UI: Customer/Tag dropdowns, firewall details JavaScript fixes'
            ]
        ],
        [
            'version' => '3.0.0 (Agent)',
            'date' => '2025-10-08',
            'type' => 'major',
            'title' => 'PID Locking Implementation',
            'changes' => [
                'ADDED: PID file locking to prevent duplicate agents',
                'ADDED: Agent version tracking in database',
                'IMPROVED: Check-in logging and monitoring'
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
        'update_agent' => [
            'version' => UPDATE_AGENT_VERSION,
            'status' => UPDATE_AGENT_STATUS
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
        $stmt = $DB->query("SELECT COUNT(*) as count FROM firewalls WHERE agent_version < '" . AGENT_MIN_VERSION . "' AND status = 'online'");
        $result = $stmt->fetch();
        $health['checks']['agent_versions'] = [
            'status' => $result['count'] == 0 ? 'ok' : 'warning',
            'message' => $result['count'] == 0 ? 'All agents up to date' : $result['count'] . ' agent(s) need updating'
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
