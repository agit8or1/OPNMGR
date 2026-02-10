# OPNManager Changelog

All notable changes to OPNManager are documented here.

**Last Updated**: December 11, 2025 6:15 PM

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

