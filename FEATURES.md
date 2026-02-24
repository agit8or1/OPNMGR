# OPNManager Feature Catalog
**Last Updated**: February 24, 2026
**Version**: 3.8.6

This document provides a comprehensive catalog of all features in OPNManager, organized by category with implementation status and technical details.

---

## 📊 Core Monitoring & Management

### Firewall Dashboard
**Status**: ✅ Production | **Version**: 2.0.0+

- **Real-time Status Monitoring**
  - Live agent check-ins every 2-5 minutes
  - System health indicators (online/offline/warning)
  - Last seen timestamps with timezone support
  - Connection quality indicators

- **Health Metrics Display**
  - CPU usage percentage
  - Memory usage with total/used display
  - System uptime (accurate calculation from boot time)
  - Disk usage monitoring
  - Network interface status

- **Firewall Organization**
  - Multi-tenant customer grouping
  - Color-coded tag system
  - Quick search and filtering
  - Bulk operations support
  - Custom notes and descriptions

### Network Configuration Display
**Status**: ✅ Production | **Version**: 2.1.0+

- **WAN Interface Data**
  - Public IP address
  - Subnet mask
  - Default gateway
  - Primary DNS server
  - Secondary DNS server
  - IPv6 address (when available)

- **LAN Interface Data**
  - LAN IP address
  - LAN subnet mask
  - LAN network range calculation
  - LAN gateway tracking

- **Data Quality Indicators**
  - Real vs estimated data badges
  - Configuration persistence between check-ins
  - IP class-based network estimation fallback

### Unified Agent Architecture
**Status**: ✅ Production | **Version**: 3.8.0+

**OPNManager Agent (Plugin-based, v1.5.6)**
- Check-in interval: 2 minutes (configurable per-firewall)
- Native OPNsense plugin with auto-update support
- Functions:
  - System metrics collection (CPU, Memory, Disk, Uptime)
  - Network configuration reporting (WAN/LAN with multi-WAN)
  - Traffic statistics (interface bytes in/out, pf counter fallback)
  - OPNsense version and update availability detection
  - Reboot-required status reporting
  - Command execution and response (base64 encoded)
  - SSH tunnel management
  - Firewall backup coordination
  - Latency monitoring (ping measurements)
  - Bandwidth testing (iperf3 speed tests)
- **Technical**: Shell script, runs via cron, PID-based duplicate prevention

---

## 🔐 Secure Remote Access

### SSH Tunnel System
**Status**: ✅ Production | **Version**: 2.1.0+

- **On-Demand Tunnel Creation**
  - No permanently open ports required
  - Dynamic port allocation (8100-8199)
  - Session-based access control
  - Automatic tunnel cleanup

- **Tunnel Proxy System**
  - Full web UI access through encrypted tunnel
  - Content rewriting for proper URL handling
  - Cookie management for session persistence
  - JavaScript, CSS, and HTML rewriting
  - Font and static resource proxying
  - EventSource/WebSocket support

- **Session Management**
  - Configurable session duration (default 30 minutes)
  - Idle timeout detection
  - Automatic session expiration
  - Session activity tracking
  - Manual session termination

- **Security Features**
  - ED25519 SSH key authentication
  - Per-firewall SSH keys
  - Source IP validation
  - Session token authentication
  - Encrypted communication

**Technical Implementation**:
- `/scripts/manage_ssh_tunnel.php` - Tunnel creation/management
- `/tunnel_proxy.php` - HTTP reverse proxy (678 lines)
- `/check_tunnel_status.php` - Connection readiness polling
- Cleanup cron: `/scripts/manage_ssh_access.php cleanup` (every 5 minutes)

---

## 🤖 AI-Powered Analysis

### AI Configuration Scanning
**Status**: ✅ Production | **Version**: 2.2.0+

- **Multi-Provider Support**
  - OpenAI (GPT-4, GPT-3.5)
  - Anthropic (Claude 3.5 Sonnet, Claude 3 Opus)
  - Google Gemini (gemini-pro)
  - Ollama (local deployment support)

- **Configuration Analysis**
  - Security vulnerability detection
  - Rule optimization recommendations
  - Compliance checking
  - Best practices validation
  - Risk level assessment

- **Scan Types**
  - Config-only scan: Fast analysis of firewall configuration
  - Config + Logs scan: Deep analysis including system logs

- **Report Generation**
  - Overall security grade (A+ to F)
  - Security score (0-100)
  - Risk level classification (low/medium/high/critical)
  - Executive summary
  - Detailed findings with severity ratings
  - Actionable recommendations
  - Improvement suggestions

- **Findings Categories**
  - Security vulnerabilities
  - Configuration weaknesses
  - Rule conflicts or redundancies
  - Performance concerns
  - Compliance gaps

**Technical Implementation**:
- Tables: `ai_scan_reports`, `ai_scan_findings`, `ai_settings`, `firewall_ai_settings`
- API: `/api/scan_config.php` - Initiates AI scan
- UI: `/ai_reports.php` - Report viewing and management
- Settings: `/ai_settings.php` - Provider configuration

**Database Schema**:
```sql
ai_scan_reports:
  - id, firewall_id, scan_type, provider, model
  - overall_grade, security_score, risk_level
  - summary, recommendations, concerns, improvements
  - full_report (JSON), scan_duration, created_at

ai_scan_findings:
  - id, report_id, category, severity
  - title, description, impact
  - recommendation, affected_area, created_at
```

### AI Log Analysis
**Status**: ✅ Production | **Version**: 2.2.0+

- **Log Collection**
  - SSH-based log retrieval from firewalls
  - Support for multiple log types:
    - Firewall logs (blocked/allowed traffic)
    - System logs
    - VPN logs
    - IDS/IPS logs (when enabled)

- **AI-Powered Analysis**
  - Threat detection and classification
  - Suspicious activity identification
  - Attack pattern recognition
  - False positive reduction
  - Incident correlation

- **Analysis Features**
  - Automated threat categorization
  - Risk scoring for log events
  - Time-based pattern analysis
  - Geographic threat mapping (with GeoIP)
  - Anomaly detection

- **Report Generation**
  - Threat summary dashboard
  - Detailed incident reports
  - Timeline visualization
  - Recommended actions
  - Threat intelligence integration

**Technical Implementation**:
- Log fetching via SSH commands
- Integration with AI providers for analysis
- GeoIP database for location tracking
- Report storage in ai_scan_reports (scan_type='config_with_logs')

---

## 📦 Configuration Backup & Restore

### Automated Backup System
**Status**: ✅ Production | **Version**: 2.0.0+

- **Backup Scheduling**
  - Nightly automated backups (2:00 AM)
  - Manual on-demand backups
  - Per-firewall backup history
  - Backup metadata tracking

- **Backup Management**
  - Download backups locally
  - Restore configuration to firewall
  - Delete old backups
  - Backup size and date tracking
  - Backup descriptions/notes

- **Storage & Retention**
  - Centralized backup storage on manager
  - Configurable retention policies
  - Automatic cleanup of expired backups
  - Compression support (XML files)

- **Backup Process**
  - Agent creates backup on firewall (/conf/config.xml)
  - Upload via HTTPS POST to manager
  - SHA-256 integrity verification
  - Database record creation
  - Success/failure notifications

**Technical Implementation**:
- Cron: `/cron/nightly_backups.php` - Runs daily at 2 AM
- API endpoints:
  - `/api/create_backup.php` - Queue backup command
  - `/api/upload_backup.php` - Receive backup file
  - `/api/download_backup.php` - Send backup to admin
  - `/api/restore_backup.php` - Restore to firewall
  - `/api/delete_backup.php` - Remove backup
  - `/api/get_backups.php` - List backups
- Storage: `/backups/` directory
- Naming: `config-YYYYMMDD_HHMMSS.xml` or `manual-backup-{fw_id}-{timestamp}.xml`

---

## 🎯 Command Execution System

### Remote Command Queue
**Status**: ✅ Production | **Version**: 2.0.0+

- **Command Features**
  - Queue multiple commands per firewall
  - Base64 encoding for complex commands
  - Multi-line command support
  - Command output capture
  - Execution status tracking

- **Command Types**
  - Shell commands
  - Configuration updates
  - System operations
  - Backup commands
  - Update commands

- **Execution Flow**
  1. Command queued in database (status: pending)
  2. Agent retrieves pending commands on check-in
  3. Agent executes command on firewall
  4. Agent sends output back to manager
  5. Status updated (completed/failed)

- **Command History**
  - Full execution log
  - Timestamp tracking
  - Output retention
  - Success/failure rates
  - Command replay capability

**Technical Implementation**:
- Table: `firewall_commands`
- Fields: id, firewall_id, command, description, status, created_at, sent_at, completed_at, result
- Status enum: 'pending', 'sent', 'completed', 'failed', 'cancelled'
- Agent endpoint: `/agent_checkin.php` (returns pending commands)
- UI: Embedded in firewall details page

---

## 📈 Traffic & Statistics Monitoring

### Real-Time Traffic Graphs
**Status**: ✅ Production | **Version**: 2.1.0+

- **Traffic Visualization**
  - Chart.js v4.4.0 powered graphs
  - Separate IN/OUT traffic graphs
  - Time-series data display
  - Interactive tooltips
  - Zoom and pan support

- **Data Collection**
  - Interface byte counters (WAN/LAN)
  - 30-second collection intervals
  - Delta-based rate calculation
  - Database storage for historical data

- **Time Range Selection**
  - 1 hour (per-minute granularity)
  - 4 hours (per-minute granularity)
  - 12 hours (10-minute intervals)
  - 24 hours (10-minute intervals)
  - 1 week (hourly intervals)
  - 30 days (hourly/2-hour intervals)

- **Metrics Displayed**
  - Traffic IN (Mb/s)
  - Traffic OUT (Mb/s)
  - Peak rates
  - Average rates
  - Total bytes transferred

**Technical Implementation**:
- Collection: Agent reports `bytes_in` and `bytes_out` per interface
- Storage: `firewall_traffic_stats` table
- Calculation: LAG() window function for delta/rate
- API: `/api/get_traffic_stats.php` with timeframe parameter
- Rendering: Chart.js with custom darkBackgroundPlugin

### System Resource Graphs
**Status**: ✅ Production | **Version**: 2.1.0+

- **CPU Usage Monitoring**
  - Real-time CPU percentage
  - Historical trending
  - Multi-core awareness

- **Memory Usage Tracking**
  - RAM usage percentage
  - Used vs total memory display
  - Swap usage (when available)

- **Disk Usage Monitoring**
  - Filesystem utilization percentage
  - Capacity tracking
  - Free space alerts

**Technical Implementation**:
- Collection: Agent parses system output (top, df, etc.)
- Storage: `firewall_system_stats` table
- Fields: cpu_usage, memory_total, memory_used, disk_usage, swap_usage
- API: `/api/get_system_stats.php`
- Same time range options as traffic graphs

---

## 🏢 Deployment & Licensing

### Deployment Package System
**Status**: ✅ Production | **Version**: 2.2.0+

- **Package Generation**
  - Automated package builder
  - Exclusion of development tools
  - Clean installation scripts included
  - Database schema export
  - Configuration templates

- **Package Contents**
  - Core application files
  - Agent scripts
  - Database schema.sql
  - Apache/Nginx configs
  - Installation README
  - .env.example template

- **Deployment Features**
  - One-click package download
  - Tarball compression (.tar.gz)
  - SHA-256 checksum generation
  - Installation script automation
  - Post-install verification

**Technical Implementation**:
- Builder: `/deployment/generate_package.php`
- Exclusions: deployment menu, primary-server features, dev tools
- Config flag: `IS_PRIMARY_SERVER` (default: false)
- Generated location: `/deployment/packages/`

### Licensing System
**Status**: ✅ Production | **Version**: 2.2.0+

- **License Tiers**
  - **Trial**: 2 firewalls, 30 days, no support
  - **Starter**: 5 firewalls, $49/month
  - **Professional**: 25 firewalls, $149/month, email support
  - **Enterprise**: 100 firewalls, $399/month, priority support
  - **Unlimited**: Unlimited firewalls, custom pricing, dedicated support

- **License Management**
  - Instance registration
  - License key generation
  - Firewall count enforcement
  - Expiration tracking
  - Usage reporting

- **Check-In System**
  - Deployed instances check-in every 4 hours
  - License validation per check-in
  - Firewall count verification
  - Auto-update checking
  - Version compatibility checking

- **Enforcement**
  - Firewall limit exceeded warnings
  - Grace period support
  - License expiration notifications
  - Feature degradation on expired license

**Technical Implementation**:
- Tables: `licenses`, `licensed_servers`, `deployment_packages`
- Check-in endpoint: `/api/instance_checkin.php`
- Validation: Firewall count vs license max_firewalls
- Cron: Deployed instances run check-in cron every 4 hours
- UI: `/deployment/licenses.php`

### Update Distribution
**Status**: ✅ Production | **Version**: 2.2.0+

- **Version Management**
  - Centralized version tracking
  - Update package hosting
  - Release notes management
  - Rollback capability

- **Update Process**
  1. Primary server publishes update
  2. Deployed instances check-in
  3. Version comparison
  4. Update download (if newer)
  5. Application update
  6. Verification

- **Update Features**
  - Incremental updates
  - Full package updates
  - Database migrations
  - Configuration updates
  - Agent updates

---

## ⚙️ System Administration

### Settings Management
**Status**: ✅ Production | **Version**: 2.0.0+

- **Application Settings**
  - Company name/logo
  - Email server configuration
  - Timezone settings
  - Theme customization
  - Feature toggles

- **AI Provider Configuration**
  - Multiple provider support
  - API key management (encrypted storage)
  - Model selection
  - Provider activation/deactivation
  - Usage tracking

- **Security Settings**
  - Session timeout configuration
  - Password policies
  - Two-factor authentication (planned)
  - IP whitelisting

**Technical Implementation**:
- Table: `settings` (key-value pairs)
- Table: `ai_settings` (provider configs)
- UI: `/settings.php`, `/ai_settings.php`
- Encryption: API keys stored with encryption

### User Management
**Status**: ✅ Production | **Version**: 1.0.0+

- **User Features**
  - Admin user accounts
  - Role-based access (admin only currently)
  - Password hashing (bcrypt)
  - Session management
  - Login tracking

- **Future Enhancements** (Planned)
  - Multi-level roles (admin, operator, viewer)
  - Per-firewall permissions
  - Activity logging
  - Two-factor authentication
  - SSO integration

**Technical Implementation**:
- Table: `users`
- Auth: `/inc/auth.php`
- Functions: `requireLogin()`, `requireAdmin()`
- Session: PHP sessions with database backing

### Logging & Auditing
**Status**: ✅ Production | **Version**: 2.0.0+

- **System Logs**
  - Application events
  - Agent check-ins
  - Command executions
  - Backup operations
  - Tunnel creations

- **Log Types**
  - INFO: Normal operations
  - WARNING: Potential issues
  - ERROR: Failures
  - SECURITY: Auth events
  - AUDIT: Admin actions

- **Log Management**
  - Log rotation
  - Retention policies
  - Search and filter
  - Export capabilities

**Technical Implementation**:
- Table: `system_logs`
- Function: `write_log($type, $message, $user_id, $firewall_id)`
- Location: `/inc/logging.php`
- Retention: 45 days (configurable)

---

## 🔍 Planned Features

### Network Diagnostic Tools
**Status**: 🚧 In Development

- Ping from firewall
- Traceroute from firewall
- DNS lookup testing
- Port connectivity testing
- Bandwidth testing

### WAN Bandwidth Testing
**Status**: ✅ Production | **Version**: 3.6.0+

- Automated iperf3 speed tests with configurable intervals (2h, 4h, 8h, 12h, 24h)
- Historical speed tracking with download/upload graphs
- Multi-server fallback for reliability
- On-demand manual speed tests
- Per-firewall scheduling configuration

### Data Retention Management
**Status**: ✅ Production | **Version**: 3.7.0+

- Automatic purge of old command queue records (completed >7d, failed >14d)
- System health checks (database, queue, agents, disk)
- Cron-based cleanup in two phases: stuck recovery + data purge
- Purgeable record count display in Queue Management

### Firmware Update Management
**Status**: ✅ Production | **Version**: 3.8.0+

- One-click OPNsense firmware updates from web interface
- Animated "Updating..." status with progress bar during updates
- Clickable "Reboot Required" badge triggers remote reboot
- Auto-recovery for updates stuck in updating state (>15 minute timeout)
- Toast notifications for update/reboot/check actions
- Force update check when firewall reboots
- Duplicate reboot command prevention

### DNS Traffic Analysis
**Status**: 📋 Planned

- Outbound DNS blocking
- Unbound resolver enforcement
- GeoIP-based analysis
- Internet usage reporting
- Productivity analysis

---

## 📊 Feature Matrix

| Feature | Status | Version | Multi-Tenant | API | Agent Required |
|---------|--------|---------|--------------|-----|----------------|
| AI Configuration Scanning | ✅ Production | 2.2.0 | ✅ | ✅ | ✅ |
| AI Log Analysis | ✅ Production | 2.2.0 | ✅ | ✅ | ✅ |
| Automated Backups | ✅ Production | 2.0.0 | ✅ | ✅ | ✅ |
| Firewall Monitoring | ✅ Production | 2.0.0 | ✅ | ✅ | ✅ |
| Network Configuration Display | ✅ Production | 2.1.0 | ✅ | ✅ | ✅ |
| Deployment Packages | ✅ Production | 2.2.0 | ❌ | ✅ | ❌ |
| Licensing System | ✅ Production | 2.2.0 | ❌ | ✅ | ❌ |
| Update Distribution | ✅ Production | 2.2.0 | ❌ | ✅ | ❌ |
| Command Execution | ✅ Production | 2.0.0 | ✅ | ✅ | ✅ |
| System Resource Graphs | ✅ Production | 2.1.0 | ✅ | ✅ | ✅ |
| Traffic Graphs | ✅ Production | 2.1.0 | ✅ | ✅ | ✅ |
| SSH Tunnel System | ✅ Production | 2.1.0 | ✅ | ✅ | ✅ |
| Tunnel Proxy | ✅ Production | 2.1.0 | ✅ | ❌ | ✅ |
| Network Diagnostic Tools | 🚧 Development | 2.3.0 | ✅ | ✅ | ✅ |
| WAN Bandwidth Testing | ✅ Production | 3.6.0 | ✅ | ✅ | ✅ |
| Data Retention Management | ✅ Production | 3.7.0 | ✅ | ✅ | ❌ |
| Firmware Update Management | ✅ Production | 3.8.0 | ✅ | ✅ | ✅ |
| DNS Traffic Analysis | 📋 Planned | TBD | ✅ | ✅ | ✅ |

## 🔄 Feature Update Process

**Automated Feature Tracking:**
This document is automatically updated when new features are deployed. To trigger an update:

```bash
# Run the feature documentation generator
php /var/www/opnsense/scripts/update_feature_docs.php
```

**Manual Updates:**
When adding new features, update:
1. This FEATURES.md file
2. README.md (key features section)
3. CHANGELOG.md (version history)
4. Database: Insert into `features` table

**Feature Registration:**
```sql
INSERT INTO features (name, category, status, version, description, requires_agent, api_enabled, multi_tenant) 
VALUES ('Feature Name', 'Category', 'production', '2.2.0', 'Description', 1, 1, 1);
```

---

## 📞 Support & Documentation

- **Primary Documentation**: `/var/www/opnsense/README.md`
- **Tunnel Proxy**: `/var/www/opnsense/README_TUNNEL_PROXY.md`
- **SSH Tunnels**: `/var/www/opnsense/SSH_TUNNEL_DOCUMENTATION.md`
- **Architecture**: `/var/www/opnsense/ARCHITECTURE_v2.md`
- **Changelog**: `/var/www/opnsense/CHANGELOG.md`

---

**Document Version**: 2.0
**Updated**: February 24, 2026
**Maintainer**: OPNManager Development Team
