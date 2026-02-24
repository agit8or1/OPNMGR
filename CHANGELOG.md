# OPNManager Changelog

All notable changes to OPNManager are documented here.

**Last Updated**: February 24, 2026

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

