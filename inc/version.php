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
if (!defined('APP_VERSION_DATE')) { define('APP_VERSION_DATE', '2026-08-31'); }
if (!defined('APP_VERSION_NAME')) { define('APP_VERSION_NAME', 'Checks That Run Where CI Runs'); }

// AGENT_VERSION is THE single constant for "newest agent available to install".
// Its value must match the newest released tarball in downloads/plugins/, because
// that is the only version a firewall can actually be upgraded to - an unreleased
// source bump here tells every agent to fetch a package that does not exist.
// inc/agent_version.php aliases LATEST_AGENT_VERSION to it; do not redefine it there.
// scripts/check_versions.php enforces this against the released artifact.
if (!defined('AGENT_VERSION')) { define('AGENT_VERSION', '1.5.6'); }
if (!defined('AGENT_VERSION_DATE')) { define('AGENT_VERSION_DATE', '2026-02-09'); }
if (!defined('AGENT_MIN_VERSION')) { define('AGENT_MIN_VERSION', '1.3.0'); } // Minimum supported agent version

if (!defined('DATABASE_VERSION')) { define('DATABASE_VERSION', '1.4.0'); }
if (!defined('API_VERSION')) { define('API_VERSION', '1.1.0'); }
if (!defined('TUNNEL_PROXY_VERSION')) { define('TUNNEL_PROXY_VERSION', '2.1.0'); }

// System information
define('PHP_MIN_VERSION', '8.0');
define('BOOTSTRAP_VERSION', '5.3.8');
define('JQUERY_VERSION', '3.7.1');

// Changelog entries (most recent first)
function getChangelogEntries($limit = 10) {
    return [
        [
            'version' => '3.20.0',
            'date' => '2026-08-31',
            'type' => 'minor',
            'title' => 'No Reboot Loops, Real Retention',
            'changes' => [
                'ADDED: Backup retention is now enforced. It has been configurable since 3.12.0 and applied by nothing - backup_retention_days was seeded at 90 and never read, and the settings UI wrote a separate months/count scheme that was also never read, so no backup was ever deleted. Retention is now a window in days enforced by cron/prune_backups.php',
                'ADDED: backup_retention_min_keep (default 3) never prunes a firewall\'s newest backups whatever their age, so a firewall that has stopped checking in cannot lose every copy of its configuration to an age-only sweep',
                'ADDED: cron/prune_backups.php reports by default and deletes only with --apply, with --days= and --floor= overrides',
                'CHANGED: The backup retention dialog now asks for a window in days and a minimum to keep, replacing the two-mode months/count form whose values were never applied',
                'CHANGED: Migration 0014 removes the superseded months/count settings, carrying an existing time-based policy across as months x 30',
                'FIXED: A reboot was redelivered on every check-in, turning one reboot request into a reboot loop. checkQueuedCommands() resets any command left in \'sent\' for ten minutes back to \'pending\', assuming no result means the agent never received it. A reboot can never report a result - the firewall stops executing partway through the command - so it was reset and handed back to the box the moment it finished booting. Observed on home.agit8or.net: command 8017 (/sbin/reboot) was queued at 12:28:01 and had already been redelivered at 12:39:25',
                'FIXED: Commands that take the firewall down (/sbin/reboot, /sbin/halt, /sbin/poweroff, shutdown -r/-h/-p) are now settled as completed when they time out, and excluded from the stuck-command reset in both the general and update-agent paths. A missing result is recorded as the expected outcome rather than read as a delivery failure',
                'ADDED: settle_unacknowledgeable_commands() in inc/agent_commands.php with tests/agent_command_retry_test.php',
            ],
        ],
        [
            'version' => '3.19.4',
            'date' => '2026-08-31',
            'type' => 'patch',
            'title' => 'Map Rollback',
            'changes' => [
                'Reverted the 3.19.3 dashboard map changes — the new tiles and layout were worse than what they replaced',
                'Network map is back to the OpenStreetMap basemap and prior sizing',
            ],
        ],
        [
            'version' => '3.19.2',
            'date' => '2026-08-31',
            'type' => 'patch',
            'title' => 'Reboot State Measured, Not Guessed',
            'changes' => [
                'FIXED: reboot_required was never measured. The agent has never reported a reboot flag, so agent_checkin.php preserves the stored value on every check-in and the column was writable only by code that inferred it. fw.agit8or.net asserted "reboot required" continuously from 2026-03-04 across many actual reboots, while home.agit8or.net reported no reboot needed immediately after installing a base and kernel it had not booted into. It is now derived by comparing estimated boot time against the completion of the last update known to have installed successfully',
                'FIXED: An unreadable uptime no longer clears a real pending reboot. The parser returns null rather than zero for Unknown/empty/unrecognised values, and an indeterminate state leaves the stored value untouched',
                'FIXED: A failed update is no longer counted as installed. The agent reports every command as completed regardless of outcome, so the derivation requires the OPNMGR_UPDATE_EXIT=0 marker rather than trusting command status',
                'ADDED: inc/reboot_state.php with tests/reboot_state_test.php (22 assertions), wired into CI',
            ],
        ],
        [
            'version' => '3.19.1',
            'date' => '2026-08-31',
            'type' => 'minor',
            'title' => 'Trackable Firewall OS Updates',
            'changes' => [
                'FIXED: "Upgrade Firewall OS/Dependencies" left no evidence that anything had happened, so a successful update was indistinguishable from a failed one. The button set a firewalls.update_requested flag and returned success; agent_checkin.php cleared that flag the moment it read it, before the agent had run anything; the agent executed the upgrade with nohup and never reported a result; and no row was written to the command history. Operators reasonably concluded the feature was broken and clicked again, running the upgrade repeatedly. The update is now dispatched as a normal tracked command, so it appears in the command history and its real output and exit status are recorded',
                'FIXED: A full upgrade could be killed partway through. The agent runs queued commands as eval \"$cmd\" 2>&1 | head -1000; a real upgrade emits far more than 1000 lines and once head exits the writer receives SIGPIPE. install_updates now redirects the updater output to /var/log/opnmanager_update.log and returns only a bounded tail',
                'FIXED: The agent hardcodes \"status\":\"completed\" for every command and never transmits an exit code, so a failed upgrade looked identical to a successful one. install_updates now echoes its own exit status as a marker that the server reads to decide the real outcome. An upgrade reporting back without a recognisable marker is recorded as unconfirmed rather than assumed successful',
                'FIXED: agent_checkin.php optimistically set updates_available = 0 and reboot_required = 1 at the moment an update request was handed to the agent, asserting an outcome before any work had been done. A request that never executed still left the server reporting "no updates available, reboot required". Both values are now only ever set from what the agent actually reports',
                'ADDED: Requesting an update while one is still pending or in flight no longer queues a second one. The endpoint returns the existing command id instead, so repeat clicks are harmless',
                'CHANGED: The firewall list now shows the queued command number after a successful request, and no longer reloads the page two seconds later - that reload erased the only feedback the operator had been given',
                'CHANGED: api/update_firewall.php is gated on the update.install capability rather than a blanket admin check, matching the rest of the update paths',
                'REMOVED: triggerOPNsenseUpdate() and triggerAgentUpdate() in api/update_firewall.php. Both were curl-based helpers that nothing ever called',
            ],
        ],
        [
            'version' => '3.18.0',
            'date' => '2026-08-31',
            'type' => 'minor',
            'title' => 'Real Security Posture and No Silent Config Staleness',
            'changes' => [
                'FIXED: The Security Status panel on the firewall detail page was hardcoded HTML. Every firewall displayed "SSH Access - Enabled - Port 22" and "API Authentication - Enabled" with green ticks regardless of its actual configuration. It is now computed from that firewall\'s own configuration and recorded agent state',
                'ADDED: SSH exposure distinguishes four states - service disabled, running but no WAN rule permits it, WAN with source restrictions, and open to any source - because those carry very different risk and the old panel showed all four identically',
                'ADDED: The panel reports root login and password authentication, the WAN rules permitting SSH with their sources, and real agent authentication state including whether the API key is pinned and requests are signed',
                'FIXED: resolve_backup_path() treated an unreadable backup the same as a missing one, so callers silently fell back to an older configuration. Backups live in a www-data-only directory, so anything running as another user quietly answered from stale data - a six-month-old config was used to answer whether SSH was exposed',
                'ADDED: drift_config_freshness() reports which configuration an answer came from, whether newer ones were skipped and why, and surfaces it in the UI',
                'CHANGED: The ssh_on_wan configuration check is split into ssh_open_to_world (a WAN rule from ANY source - the finding that matters) and ssh_on_wan (informational, includes source-restricted management access)',
            ]
        ],
        [
            'version' => '3.17.1',
            'date' => '2026-08-27',
            'type' => 'patch',
            'title' => 'Remove the superseded offline-check cron',
            'changes' => [
                'REMOVED: cron/check_offline_firewalls.php, superseded by cron/evaluate_alerts.php. It emailed on a timer with no notion of an ongoing problem - it could not say "still down", never said "back online", and gave nobody anything to acknowledge',
                'REMOVED: its cron entries, which were scheduled in BOTH the root and administrator crontabs at one-minute intervals, so it had been running twice a minute',
                'REMOVED: its 8.2 MB log file, which it wrote into the cron directory inside the document root',
            ]
        ],
        [
            'version' => '3.17.0',
            'date' => '2026-08-27',
            'type' => 'minor',
            'title' => 'AI Redaction, Dashboard Roll-ups and Final Hardening',
            'changes' => [
                'SECURITY: api/ai_scan.php sent the entire raw config.xml to an external AI provider with no redaction whatsoever. An OPNsense configuration carries password hashes, X.509 and WireGuard private keys, IPsec pre-shared keys and SNMP communities - all of it was being transmitted to a third party',
                'ADDED: inc/ai_redaction.php strips credential material before anything leaves the server. Redaction cannot be disabled; a configuration that will not parse is refused rather than sent raw',
                'ADDED: AI is opt-in and off by default. An administrator must read a disclosure of exactly what is and is not transmitted before enabling it, and can disable it entirely',
                'SECURITY: api/tunnel_management.php allowed any signed-in user, including read-only, to kill tunnels and run privileged commands. It now requires the tunnel.close capability',
                'SECURITY: ai_reports.php called unserialize() on values originating in AI provider responses without restricting classes',
                'SECURITY: Shell interpolation escaped across system_backup.php, security_scan.php, update_docs_trigger.php and the tunnel scripts',
                'FIXED: ai_settings.php included the page header before checking authorisation, so the shell rendered before a redirect could be sent',
                'ADDED: Dashboard KPI tiles for reboots pending, gateways down, VPN down, drift, backup failures, certificate expiry, critical incidents and maintenance. Tiles only appear when non-zero, so a row of numbers means something needs attention',
                'ADDED: scripts/check_versions.php enforces a single authoritative version source and runs in CI, so README, inc/version.php and the CHANGELOG cannot drift apart again',
            ]
        ],
        [
            'version' => '3.16.0',
            'date' => '2026-08-27',
            'type' => 'minor',
            'title' => 'Fleet Update Management, Bulk Operations and Configuration Search',
            'changes' => [
                'ADDED: Fleet update view showing customer, site, current and available version, agent version, update and reboot state per firewall',
                'ADDED: Update rings (canary, pilot, production) as a rollout mechanism, not customer tiers. Progression is manual unless auto-progress is explicitly enabled, and a ring containing any failure never counts as clean',
                'ADDED: HA-safe updates. Members of a CARP pair are never dispatched simultaneously, the BACKUP is updated before the MASTER, and the second member is held until the first is back online with CARP settled',
                'ADDED: Bulk operations across selected firewalls, with typed confirmation phrases that include the target count for high-risk actions. Raw shell is deliberately not a bulk operation',
                'ADDED: Deterministic fleet configuration search over stored backups, with named checks (SSH on WAN, web GUI on WAN, any-any pass rules, UPnP) plus literal and CIDR matching. No AI required',
                'ADDED: Agent health view covering version currency, check-in punctuality, authentication state, signing support and clock skew',
                'SECURITY: The restore path built its download URL from the client-supplied Host header with no scheme, and pointed at a session-protected endpoint a firewall cannot authenticate to. Restores now use an agent-authenticated, single-use token endpoint',
                'ADDED: Restore safety - the backup is validated and checksum-verified, a pre-restore snapshot is taken first, the firewall hostname must be typed to confirm, and success is only recorded once the agent checks in again after the restore',
            ]
        ],
        [
            'version' => '3.15.0',
            'date' => '2026-08-26',
            'type' => 'minor',
            'title' => 'Incident-Based Alerting and Maintenance Windows',
            'changes' => [
                'ADDED: Alerting is now incident-based. One incident per ongoing problem, opened when a condition becomes true, updated while it persists and resolved when it clears - replacing "send an email whenever the condition is true and a 60-minute timer elapsed"',
                'ADDED: Incident lifecycle OPEN / ACKNOWLEDGED / RESOLVED. Acknowledging stops notification without closing the incident; incidents auto-resolve when the condition actually clears',
                'ADDED: Notification backoff (60m, 120m, 240m) with a repeat limit, so an offline firewall no longer notifies every couple of minutes indefinitely',
                'ADDED: 17 alert types covering offline, gateways (down/degraded/flapping), VPN tunnels, CARP faults, services, certificate expiry, sustained CPU/memory/disk, config drift, backup and update failure, outdated agents and repeated agent authentication failures',
                'ADDED: Maintenance windows scoped to a firewall, a site or a whole customer. During a window monitoring, health collection and incident recording all continue; only outbound notification is withheld, and the suppression is recorded as an incident event',
                'ADDED: cron/evaluate_alerts.php, which asserts current truth for every condition and is idempotent - running it more often produces the same incidents, not more of them',
                'FIXED: The maintenance lookup cache was per-process with no way to invalidate it, so a long-running evaluator or a window created mid-request would read stale state',
            ]
        ],
        [
            'version' => '3.14.0',
            'date' => '2026-08-26',
            'type' => 'minor',
            'title' => 'Configuration Drift and Firewall Health',
            'changes' => [
                'ADDED: Configuration drift detection built on the existing backups. Compares a canonical form of the config, so serialisation noise and the <revision> block OPNsense stamps on every save are not reported as changes',
                'ADDED: Baselines, section-level drift attribution, readable diffs, acknowledgement and promote-to-baseline. Drift is never acted on automatically',
                'ADDED: OPNsense health telemetry - gateways (status, latency, loss, default, transition history), VPN tunnels (WireGuard/OpenVPN/IPsec with handshake and byte counters), CARP/HA state, services and certificate expiry',
                'ADDED: Certificate expiry warnings at configurable 30/14/7 day thresholds, and gateway flapping detection',
                'ADDED: Agent health collector (health_collect.py) in the plugin source, pending an agent release. Certificate metadata only; private key material is never read',
                'FIXED: Gateway latency and loss reported with units ("12.4 ms", "0.0 %") were stored as NULL',
                'FIXED: The drift differ compared a single repeated element against a list of them field by field, so adding one firewall rule looked like every field of the first rule had been edited',
                'FIXED: Drift could not find a current configuration on installations with long runs of backup rows whose upload never arrived',
            ]
        ],
        [
            'version' => '3.13.0',
            'date' => '2026-08-26',
            'type' => 'minor',
            'title' => 'MSP Staff Roles, Customer/Site Model and Fleet Search',
            'changes' => [
                'ADDED: MSP staff roles - Administrator, Technician and Read Only - defined in one capability matrix (inc/permissions.php) rather than role strings scattered through the codebase',
                'ADDED: Customer and site model. Customer -> Site -> Firewall(s), with customer_id/site_id foreign keys replacing two parallel free-text columns',
                'ADDED: Global fleet search with field qualifiers (customer:, site:, tag:, version:, agent:, ip:, interface:, vpn:, status:) and CIDR range matching, plus a header typeahead',
                'ADDED: Searchable audit log UI with filters for action, user, firewall, result and date range',
                'FIXED: The Customers page counted firewalls by matching customer_name, which was empty for firewalls linked via customer_group, so customers with firewalls showed a count of zero',
                'FIXED: customers.php included the page header before checking authorisation, so the shell rendered before the login redirect could be sent',
                'FIXED: The delete guard on customers counted by the same empty column, so a customer with firewalls could be deleted and orphan them',
                'FIXED: users.php wrote $_POST[\'role\'] into the database with no allow-list',
                'FIXED: System Update reported an update whenever the local commit differed from GitHub, including when the checkout was ahead; it now uses git ahead/behind counts',
                'FIXED: Update pulls ran against main regardless of the checked-out branch, ignored git stash failures, and never applied database migrations',
                'SECURITY: The update wrapper made .env world-readable and the backups directory world-writable on every update',
                'SECURITY: The screenshot tool in the document root contained the administrator password in plaintext and was publicly served',
            ]
        ],
        [
            'version' => '3.12.0',
            'date' => '2026-08-26',
            'type' => 'minor',
            'title' => 'Agent Authentication Hardening & Secret Encryption',
            'changes' => [
                'SECURITY: Fixed arbitrary file write in api/upload_backup.php - agent-supplied filenames were written into the web root, where nginx executes .php (remote code execution as www-data)',
                'SECURITY: Fixed IDOR in api/command_result.php - any authenticated agent could finalise and overwrite the result of another firewall\'s command',
                'SECURITY: Agent API keys and signing secrets are now issued per firewall and encrypted at rest with XChaCha20-Poly1305 (OPNMGR_MASTER_KEY in .env)',
                'SECURITY: Optional HMAC-SHA256 request signing for agents with timestamp, nonce replay protection and constant-time comparison',
                'SECURITY: Centralised all agent authentication in inc/agent_auth.php; 22 endpoints refactored off their hand-rolled hardware_id checks',
                'SECURITY: Agent authentication failures now return a generic error and no longer log expected hardware IDs',
                'ADDED: Application audit log (audit_log table) with automatic redaction of credential-bearing metadata',
                'ADDED: Database migration runner (scripts/migrate.php) with idempotent, checksummed migrations',
                'ADDED: Security regression test suite (tests/security_test.php)',
                'FIXED: Configuration backup uploads had been failing since hardware_id authentication was introduced - queued commands sent no credentials, so no backup reached disk after 2026-02-09',
                'FIXED: api/get_commands.php used a bound parameter for LIMIT, which fails under native prepared statements',
                'CHANGED: Backups are stored outside the document root with server-generated names, SHA-256 checksums and XML validation',
                'REMOVED: Hardcoded firewall_id == 21 override that falsified LAN IP, IPv6 and uptime in agent check-ins',
            ]
        ],
        [
            'version' => '3.10.0',
            'date' => '2026-07-25',
            'type' => 'minor',
            'title' => 'Security Hardening & Dependency Updates',
            'changes' => [
                'SECURITY: Enabled GitHub Private Vulnerability Reporting (closes #5)',
                'SECURITY: Added authentication to 25+ unauthenticated API endpoints (critical: download_tunnel_key, generate_enrollment_token, admin_queue, tunnel_close, etc.)',
                'SECURITY: Added CSRF validation to 19+ state-changing endpoints (users, profile, AI settings, license server, fail2ban, tunnel management, etc.)',
                'SECURITY: Fixed command injection in tcpdump filter (run_diagnostic.php) — switched from blocklist to allowlist + escapeshellarg()',
                'SECURITY: Removed hardcoded auth key from emergency_agent_update.php — now uses hardware_id validation',
                'SECURITY: Fixed password hash exposure in get_user.php API response',
                'SECURITY: Fixed path traversal in snyk_scan_progress.php — added scan_id allowlist regex',
                'SECURITY: Added CLI-only guard to snyk_scan_runner.php (blocks web access)',
                'SECURITY: Added auth to monitor.php, tunnel_auto_login.php, tunnel_health_check.php, create_backup_test.php, restore_backup.php',
                'SECURITY: Agent-facing endpoints now validate hardware_id via hash_equals() (check_update_flag, clear_update_flag, update_complete, update_status, etc.)',
                'UPDATED: Bootstrap 5.3.3→5.3.8, Font Awesome 6.4.0→6.7.2, Chart.js 4.4.0→4.5.1 (all pages unified)',
                'UPDATED: Parsedown 1.7.4→1.8.0, Puppeteer 24.37.3→24.43.1',
                'FIXED: npm audit vulnerabilities — basic-ftp (critical), ws (high), js-yaml (high), ip-address (moderate)',
                'FIXED: Pinned Chart.js version in dashboard.php (was loading unversioned from CDN)',
                'IMPROVED: SECURITY.md updated with email contact for vulnerability reports',
            ]
        ],
        [
            'version' => '3.9.2',
            'date' => '2026-03-09',
            'type' => 'patch',
            'title' => 'DST Timezone Fix & Tunnel Improvements',
            'changes' => [
                'FIXED: Checkin times showing "1 hour ago" on DST transition — MySQL timezone changed from hardcoded offset to America/New_York',
                'FIXED: Tunnel health monitor SSH key lookup now checks multiple key locations (/var/www/opnsense/keys/ and /etc/opnmgr/keys/)',
                'FIXED: Tunnel health monitor SSH key permissions auto-corrected if not 0600',
                'FIXED: Tunnel heal exec() replaced redundant shell_exec() call for proper exit code capture',
                'FIXED: Expired tunnel sessions now properly remove nginx proxy config on cleanup',
            ]
        ],
        [
            'version' => '3.9.1',
            'date' => '2026-03-02',
            'type' => 'patch',
            'title' => 'Update Status Fix',
            'changes' => [
                'FIXED: OPNsense update stuck in "Updating..." state - completion detection was gated behind 5-hour timer',
                'FIXED: Check-in no longer overwrites updating/update_pending status with online prematurely',
                'FIXED: Reduced stuck-update timeout from 15 to 5 minutes for faster recovery',
                'NEW: Auto-refresh every 10 seconds on firewalls page when any firewall is updating',
            ]
        ],
        [
            'version' => '3.9.0',
            'date' => '2026-02-24',
            'type' => 'minor',
            'title' => 'Auto-Healing SSH Tunnels',
            'changes' => [
                'NEW: tunnel_health_monitor.php cron — auto-detects and restarts dead SSH tunnels every 2 min',
                'NEW: SSH key auto-repair — re-deploys keys via agent when authentication fails',
                'NEW: Missing/broken nginx proxy configs auto-recreated during heal cycle',
                'NEW: Expired session cleanup integrated into health monitor',
                'NEW: File-lock prevents overlapping monitor runs',
                'FIXED: Nginx proxy protocol mismatch — firewalls with web_port=443 now get proxy_pass https://',
                'FIXED: Removed dead cron entries (cleanup_proxy_sessions.php, cleanup_tunnels.sh)',
            ]
        ],
        [
            'version' => '3.8.6',
            'date' => '2026-02-24',
            'type' => 'minor',
            'title' => 'Update System Overhaul & Chart Timeframes',
            'changes' => [
                'FIXED: Critical bug - reboot_required never clearing (read $_POST instead of JSON $input)',
                'FIXED: updates_available stuck when current and available versions match',
                'FIXED: Update button click handler (event.target hit icon, not button)',
                'FIXED: check_updates.php was demo code with hardcoded versions and rand()',
                'FIXED: Health score penalty for missing API credentials (not used by agent system)',
                'NEW: Animated "Updating..." state with progress bar in Updates column',
                'NEW: Updating/Update Queued status badges in Status column',
                'NEW: Clickable "Reboot Required" badge triggers firewall reboot',
                'NEW: Toast notifications replace alert() dialogs for update/reboot actions',
                'NEW: 1 Hour, 4 Hours, 12 Hours chart timeframes in firewall details',
                'NEW: Auto-recovery for firewalls stuck in updating status (>15 min timeout)',
                'NEW: Force update check when firewall reboots (reboot_required 1→0)',
                'NEW: checkUpdates() JS function (was referenced but never defined)',
                'IMPROVED: All chart APIs now accept hours parameter with smart aggregation',
                'IMPROVED: Reboot API rewritten with JSON support, CSRF, duplicate prevention'
            ]
        ],
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
