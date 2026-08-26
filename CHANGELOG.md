# OPNManager Changelog

All notable changes to OPNManager are documented here.

**Last Updated**: August 26, 2026

---

## Version 3.12.0
**Released**: August 26, 2026 | **Agent**: v1.5.6

Security release. Full architecture in [SECURITY.md](SECURITY.md).

### Fixed - security

- **Remote code execution.** `api/upload_backup.php` wrote the agent-supplied filename
  into `/var/www/opnsense/backups`, which is inside the document root and matched by the
  nginx `location ~ \.php$` block, so an authenticated agent could upload a `.php` file
  and execute code as `www-data`. Backups now store outside the web root under
  server-generated names and must parse as an `<opnsense>` configuration.
- **Cross-firewall IDOR.** `api/command_result.php` authenticated the agent then updated
  `firewall_commands` with no firewall scoping, letting any agent finalise and overwrite
  another firewall's command result.
- **Unauthenticated tunnel access.** `tunnel_proxy.php` authenticated on
  `ssh_access_sessions.id`, an `AUTO_INCREMENT` integer, while describing it as
  "unguessable". Sessions now record an owner and a bearer token.
- **Unsigned update chain.** Agent updates ran `fetch -o - <url> | sh`. Now Ed25519-signed
  manifest plus SHA-256 per artifact, atomic install and rollback.
- **Weak agent credential.** `hardware_id` (md5 of hostid or WAN MAC) was the only
  credential, and `api_key` was unset so its check was a no-op. Per-firewall API keys and
  HMAC signing secrets, provisioned over the authenticated check-in and pinned on use.
- Unauthenticated root-executing endpoints removed or authenticated, including 13 stale
  incident scripts, three of which carried auth keys hardcoded in a public repository.
- `agent_token` was read but never verified on two telemetry endpoints.
- Path traversal in `api/repair_status.php` and `api/ssh_install_status.php`.
- `api/get_client_ip.php` preferred caller-controlled headers over `REMOTE_ADDR`.
- Maintenance scripts and data directories were web-reachable; `/scripts/*.php` returned
  its own source and `/backups/*.php` executed.
- `chmod -R a+r` in the update wrapper made `.env` world-readable on every update, and
  `chmod 777` made the backups directory world-writable.
- The screenshot tool in the web root contained the administrator password in plaintext
  and was publicly served.
- No CSRF on the login form; `session.cookie_secure` forced on while a plain-HTTP vhost
  was served; session user agent recorded but never checked.

### Fixed - bugs

- Configuration backup uploads had been failing since 2026-02-09: the queued command
  carried no credentials, so rows were created nightly but no file reached disk.
- `api/trigger_agent_update.php` called `verify_session()`, which is defined nowhere, so
  every agent-update request returned a 500.
- `api/get_commands.php` bound `LIMIT` as a parameter, which fails under native prepares.
- `agent_checkin.php` read `$firewall_status` about a hundred lines before it was
  assigned, clobbering in-progress `updating` state on every check-in.
- The System Update page compared commit hashes for inequality, so it reported "update
  available" whenever the checkout was *ahead* of the remote; it also trusted a
  hand-maintained `COMMIT` file that had drifted from the real HEAD. It now uses git
  ahead/behind counts and distinguishes up to date, behind, ahead and diverged.
- `git pull origin main` ran regardless of the checked-out branch, and a failed
  `git stash` was ignored. Pulls are now fast-forward only on the current branch's
  upstream and abort on a dirty tree.
- Database migrations were never applied after an update pull.
- Removed a hardcoded `firewall_id == 21` branch that falsified LAN IP, IPv6 and uptime.

### Added

- Central agent authentication (`inc/agent_auth.php`), used by 22 endpoints.
- Optional HMAC-SHA256 signed agent requests with nonce replay protection and a
  compatibility mode so installed agents are not disconnected.
- Secret encryption at rest (XChaCha20-Poly1305) keyed from `OPNMGR_MASTER_KEY`.
- Signed release manifests and a verifying agent installer with rollback.
- Structured remote operation catalogue with validated parameters
  (`api/queue_action.php`), alongside an explicitly privileged, audited raw shell path.
- MSP staff roles (Administrator, Technician, Read Only) defined in one capability matrix.
- Application audit log with central credential redaction.
- Database migration runner (`scripts/migrate.php`), idempotent and checksummed.
- Security regression suite (`tests/security_test.php`, 29 assertions) and GitHub Actions
  CI covering lint, dependency audit, ShellCheck, the suite, and an upgrade-path job.
- nginx hardening snippet and a CLI guard for maintenance scripts.

### Changed

- Backups are stored outside the document root with SHA-256 checksums and XML validation.
- Agent credentials moved to `agent_api_key` / `agent_api_secret`; `api_key` and
  `api_secret` return to meaning the OPNsense box's own REST API credentials.
- Documentation describes OPNManager as self-hosted MSP software; customers are
  organisational containers for grouping firewalls, not accounts.

---

## Version 3.11.8
_Released: August 6, 2026_

### Changes

- **Migration Now Drops the Obsolete `alert_recipients` Table** `database/alert_system_schema.sql`
  v3.11.7 stopped *creating* the table; this release also removes it from databases that still
  carry it, via `DROP TABLE IF EXISTS alert_recipients;`.

  **This makes the file destructive** — back up before running it. Any rows still in
  `alert_recipients` are discarded. The file header carries a prominent warning and the cleanup
  section documents exporting them (`SELECT * FROM alert_recipients;`) and recreating them under
  Alerts > Notifications first. `DROP TABLE IF EXISTS` makes it a no-op on databases that never
  had the table, so re-running remains safe.

  Verified across all three paths: an old database holding the table with data (dropped, exit 0),
  a re-run against the same database (no-op, exit 0), and a fresh install that never had it
  (exit 0). The five real `alert*` tables are untouched in every case.

---

## Version 3.11.7
_Released: August 6, 2026_

### Changes

- **Removed Obsolete `alert_recipients` Table** `database/alert_system_schema.sql`
  The table definition and its sample-recipient `INSERT` are gone. Nothing in the codebase
  referenced the table, and it existed in no database on the reference host — recipient
  configuration lives in `alert_notifications`. Running this file no longer creates a dead table.

  `database/schema.sql` was unaffected (it never contained the table, having been generated from
  the reference installation). Existing databases that still carry the orphan table are left
  untouched; the file header documents the manual `DROP TABLE IF EXISTS alert_recipients;` for
  anyone who wants to clean it up.

---

## Version 3.11.6
_Released: August 6, 2026_

### Changes

- **Published Remaining Upgrade Migrations** `database/alert_system_schema.sql`, `database/migrate_v3.4.0.sql`
  Both were still excluded by the `*.sql` ignore rule. They are only needed by installations that
  predate the alert system and agent v3.4.0 respectively — `database/schema.sql` already covers both
  for fresh installs — but they are now tracked so existing deployments can upgrade from a clone.
  Each carries a header stating that fresh installs do not need it. The `*.sql` negation was widened
  to `database/*.sql`, with `database/credentials*.sql` explicitly re-ignored.

  Noted while publishing: the `alert_recipients` table in `alert_system_schema.sql` is obsolete —
  no PHP file references it and it does not exist in the reference installation (recipient
  configuration now lives in `alert_notifications`). It is retained so the file still applies
  cleanly to an old database, and flagged in the file header.

---

## Version 3.11.5
_Released: August 6, 2026_

### Bug Fixes

- **Database Schema Missing From Repository** `.gitignore`, `database/schema.sql` ([#6](https://github.com/agit8or1/OPNMGR/issues/6))
  A blanket `*.sql` ignore rule meant `database/schema.sql` was never committed, so the install step
  documented in the README (`mysql -u root -p < database/schema.sql`) failed on every fresh clone.
  Added negation rules for the shipped schema and migrations, and published a complete, current
  schema: all 59 tables plus the `v_firewall_wan_status` view and the static reference data
  (agent command allowlist, feature catalogue). The stale v2.0.0 schema covered only 9 tables.
  _Fixed by: Claude Code_

- **README Install Steps Referenced Non-Existent Files** `README.md`
  Quick Start told users to copy `inc/db.php.example`, which does not exist — configuration is via
  `.env`. Rewritten with the real sequence: composer install, schema import, database user creation,
  `.env` setup, and admin account creation.
  _Fixed by: Claude Code_

### New Features

- **First-Admin Bootstrap Script** `scripts/create_admin.php`
  A fresh install had no way to create the initial administrator. This CLI script creates an admin
  account interactively (with hidden password entry) or from arguments, using the same
  `password_hash()` scheme `inc/auth.php` verifies against.

- **Schema Generator** `scripts/generate_schema.sh`
  Regenerates `database/schema.sql` from a live installation so the published schema cannot drift
  again. Output is idempotent and carries no user, firewall, credential or customer data.

---

## Version 3.8.6
_Released: February 24, 2026_

### Critical Bug Fixes

- **Reboot Required Never Clearing** `agent_checkin.php`
  `reboot_required` flag could never be cleared by agent check-ins because the code read from `$_POST` (empty for JSON requests) instead of `$input`. Once set to 1, it was permanent.
  _Fixed by: Claude Code_

- **Updates Available Stuck After Manual Update** `agent_checkin.php`
  `updates_available=1` persisted even when `current_version` matched `available_version`. Added sanity check to clear stale flag.
  _Fixed by: Claude Code_

- **Update Button Click Did Nothing** `firewalls.php`
  `event.target` hit the `<i>` icon inside the button, not the button itself. Fixed with `.closest('button')`.
  _Fixed by: Claude Code_

- **check_updates.php Was Demo Code** `api/check_updates.php`
  Endpoint used hardcoded version strings and `rand(0,1)`. Rewritten to trigger real update check on next agent check-in.
  _Fixed by: Claude Code_

### New Features

- **Update Status Animation** `firewalls.php`
  Animated "Updating..." state with progress bar and status text in the Updates column when firewall is updating. Status column shows blue spinning badge.

- **Clickable Reboot Required Badge** `firewalls.php`
  "Reboot Required" badge is now a clickable button that triggers a firewall reboot with confirmation dialog.

- **Toast Notifications** `firewalls.php`
  Replaced browser `alert()` dialogs with styled toast notifications for update, reboot, and check actions.

- **Chart Timeframes: 1h, 4h, 12h** `firewall_details.php`
  Added 1 Hour, 4 Hours, and 12 Hours to the time frame dropdown. All chart APIs updated from days to hours parameter with adaptive aggregation intervals.

- **Stuck Update Auto-Recovery** `agent_checkin.php`
  Firewalls stuck in `updating` status for >15 minutes auto-recover to `online` on next agent check-in.

- **Force Update Check on Reboot** `agent_checkin.php`
  When `reboot_required` transitions from 1→0 (firewall rebooted), forces immediate update check instead of waiting 5 hours.

### Improvements

- Health score no longer penalizes for missing OPNsense API credentials (agent system doesn't use them)
- Reboot API rewritten with JSON support, CSRF validation, admin requirement, and duplicate command prevention
- Added missing `checkUpdates()` JavaScript function
- "Reboot Required" badge hidden during active updates (redundant)

### Files Modified
- `agent_checkin.php` - $_POST→$input fix, sanity checks, stuck recovery, reboot transition
- `firewalls.php` - Update animation, reboot button, toast notifications, health score fix
- `api/update_firewall.php` - No changes (was already correct)
- `api/check_updates.php` - Complete rewrite
- `api/reboot_firewall.php` - Complete rewrite with JSON/CSRF support
- `firewall_details.php` - Added 1h/4h/12h timeframes
- `api/get_traffic_stats.php` - Hours parameter, adaptive aggregation
- `api/get_system_stats.php` - Hours parameter, adaptive aggregation
- `api/get_latency_stats.php` - Hours parameter, adaptive aggregation
- `api/get_speedtest_results.php` - Hours parameter

---

## Version 3.6.0
_Released: February 11, 2026_

### New Features

- **Configurable Speedtest Intervals** `firewall_details.php`, `schedule_speedtest.php`
  Per-firewall speedtest scheduling with configurable intervals: every 2, 4, 8, 12, or 24 hours, or disabled entirely. Default is every 4 hours. Replaces the previous random once-daily scheduling with interval-based logic. Includes deduplication to prevent queuing when a test is already pending.
  _Implemented by: Claude Code_

### Database Changes

- Added `speedtest_interval_hours` column to `firewalls` table (INT, default 4)
  - `0` = disabled, `2/4/8/12/24` = hours between tests

### Files Modified
- `/var/www/opnsense/firewall_details.php` - Added speedtest interval dropdown and POST handler
- `/var/www/opnsense/api/schedule_speedtest.php` - Rewritten with interval-based scheduling logic
- `/var/www/opnsense/inc/version.php` - Version bump to 3.6.0

---

## Version 2.2.3
_Released: December 11, 2025_

### 🐛 Bug Fixes

- **Tunnel Proxy HTTPS Protocol Support** `tunnel_proxy.php v2.0.2`
  Fixed "Empty reply from server" errors in tunnel proxy system. Root cause: tunnel_proxy.php was using HTTP to connect to HTTPS-only SSH tunnels (port 443). Updated both initial requests (line 122) and redirect handlers (line 414) to use correct protocol based on firewall's web_port setting. After-login redirects now work correctly.
  _Fixed by: Claude Code_

- **SSH Tunnel Duplicate Process Prevention** `infrastructure`
  Resolved issue where multiple SSH tunnel processes were being created on the same port, causing connection conflicts. Implemented cleanup of duplicate tunnels before establishing new connections.
  _Fixed by: Claude Code_

- **OPNsense Agent Stability** `agent`
  Resolved agent check-in failures on home.agit8or.net (FW 48). Agent was being killed by reinstall commands without proper restart. Implemented proper service restart procedures.
  _Fixed by: Claude Code_

### 🎨 User Interface

- **About Page Enhancement** `about.php`
  Enhanced version information display to show all version numbers in organized sections. Now displays Application Versions (app, agent, tunnel proxy, database, API) and Dependencies (PHP, Bootstrap, jQuery) with color-coded badges for better visibility.
  _Improved by: Claude Code_

### 📦 Technical Details

**Files Modified:**
- `/var/www/opnsense/tunnel_proxy.php` (v2.0.1 → v2.0.2)
  - Line 122: Initial curl_init now uses `{$protocol}://` instead of hardcoded HTTP
  - Line 414: Redirect handler now uses `{$protocol}://` instead of hardcoded HTTP
  - Line 512: Debug logging updated to show correct protocol
  - Protocol determination: `($web_port == 443) ? 'https' : 'http'`

- `/var/www/opnsense/inc/version.php`
  - APP_VERSION now reads from VERSION file (not hardcoded)
  - Added TUNNEL_PROXY_VERSION constant (v2.0.2)
  - Corrected AGENT_VERSION from 3.7.7 to 1.4.0
  - Added tunnel_proxy to getVersionInfo() array
  - Updated getChangelogEntries() with v2.2.3 release

- `/var/www/opnsense/about.php`
  - Removed deprecated "Update Agent" section
  - Added comprehensive version display
  - Added Dependencies section (PHP, Bootstrap, jQuery)
  - Improved visual organization with badges

- `/var/www/opnsense/doc_viewer.php`
  - Updated "about" page version display to show all version numbers
  - Added Agent release date and min supported version
  - Added Tunnel Proxy, Database Schema, and API versions
  - Added Dependencies section (PHP, Bootstrap, jQuery)
  - Changed title from "Version Information" to "Application Versions"

**Components Affected:**
- Tunnel Proxy System
- SSH Tunnel Management
- OPNsense Agent (v1.4.0)
- Version Management System

**Upgrade Notes:**
- No database migrations required
- PHP opcache cleared automatically on deployment
- Existing active tunnel sessions continue to work
- No action required from users

---

## Version 2.4.0
_Released: September 17, 2025_

### 🚀 Improvements

- **Sidebar Menu Removed** `ui`
  Removed duplicate sidebar navigation menu to simplify interface and reduce clutter. Main navigation now consolidated to header menu only.
  _by system_


---

## Version 1.0.1
_Released: September 16, 2025_

### 📦 Updates Applied

- **Marketing Website Disable Update** `Agent`
  Added marketing website disable functionality to agent. Updated agent script to automatically disable port 88 services. Added disable_marketing_website.sh script for manual execution. Improved security for managed firewall deployments.
  _by OPNmanager System_


---
