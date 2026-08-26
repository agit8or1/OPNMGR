# OPNManager - OPNsense Firewall Management Platform

[![GitHub Stars](https://img.shields.io/github/stars/agit8or1/OPNMGR?style=social)](https://github.com/agit8or1/OPNMGR/stargazers)

**Status**: Production Stable | **License**: MIT | **Version**: [![v3.12.0](https://img.shields.io/badge/version-3.12.0-blue)](https://github.com/agit8or1/OPNMGR/releases) | **Agent**: v1.5.6

Self-hosted centralized OPNsense management for MSPs and IT teams.

OPNManager runs on your own server and manages the OPNsense firewalls belonging to the
customers you support. Customers are organisational containers used to group firewalls
and sites &mdash; they are not accounts and they do not log in. Your own staff sign in and
work across the whole managed fleet, subject to their role.

If you find OPNManager useful, please consider giving it a star on GitHub — it helps others discover the project!

### New in v3.12 — Agent Authentication & Secret Encryption

v3.12.0 is a security release. See [SECURITY.md](SECURITY.md) for the full architecture.

- **Per-firewall agent credentials** — each firewall gets a 256-bit API key and an HMAC
  signing secret, provisioned over the authenticated check-in and pinned on first use.
  `hardware_id` alone (an md5 of the hostid or WAN MAC) is no longer a credential.
- **Optional signed agent requests** — HMAC-SHA256 over method, path, timestamp, nonce and
  body hash, with a replay store and constant-time comparison. Deployed in a
  compatibility mode so an installed fleet upgrades without a flag day.
- **Secrets encrypted at rest** — XChaCha20-Poly1305 for agent credentials, SSH private
  keys, SMTP and AI credentials, and MFA recovery codes, keyed from `.env`.
- **Verified agent updates** — Ed25519-signed release manifest plus SHA-256 per artifact,
  atomic install and automatic rollback. Verification failure is always fatal.
- **Structured remote operations** — a validated action catalogue replaces hand-built
  shell for common tasks. Raw shell remains, as an explicitly privileged, audited path.
- **MSP staff roles** — Administrator, Technician and Read Only, defined in one
  capability matrix rather than role strings scattered through the code.
- **Audit log** — who did what, to which firewall, from where, with credential material
  stripped centrally.
- **Database migrations** — `scripts/migrate.php`, idempotent and checksummed.

Previous release, v3.11 — complete UI redesign: collapsible icon sidebar, firewall health
grid dashboard, dark and light themes, KPI metric strip.

---

## Key Features

### Firewall Management
- **Centralized Dashboard**: Monitor all firewalls from a single interface
- **Real-time Status**: Live agent check-ins every 2 minutes
- **Plugin Agent**: Native OPNsense plugin with auto-update support
- **Health Monitoring**: CPU, memory, disk, uptime, network status
- **Tag System**: Organize firewalls with color-coded tags
- **Customer & Site Grouping**: Organise managed firewalls by customer organisation and site. Customers do not log in.

### Network & Traffic Monitoring
- **WAN Traffic Charts**: Real-time throughput graphs with auto-scaling (Mb/s / Gb/s)
- **Interface Status**: Per-interface RX/TX byte counters with error tracking
- **Latency Monitoring**: Continuous ping measurement to multiple targets
- **Bandwidth Testing**: On-demand iperf3 speed tests with multi-server fallback
- **Smart Counter Detection**: Automatic fallback to pf counters when driver-level counters are broken (virtio_net)

### System Monitoring
- **Accurate Uptime Tracking**: Real system uptime from agent
- **Version Tracking**: OPNsense version, agent version, available updates
- **One-Click Updates**: Trigger OPNsense updates with animated progress status
- **Reboot Control**: Clickable "Reboot Required" badge triggers remote reboot with confirmation
- **Stuck Update Recovery**: Auto-recovery for updates stuck >15 minutes
- **System Stats**: CPU, memory, disk usage charts (1h, 4h, 12h, 24h, 1w, 30d timeframes)

### Command Execution
- **Remote Command Queue**: Execute commands on firewalls remotely
- **Base64 Encoding**: Support for complex multi-line commands
- **Command History**: Track all executed commands with timestamps
- **Output Capture**: View command results in real-time

### Configuration Backup
- **Automated Backups**: Scheduled configuration backups
- **Manual Backups**: On-demand backup creation
- **Backup Management**: Download, restore, and delete backups
- **Retention Policies**: Automatic cleanup of old backups

### AI-Powered Security Analysis
- **Intelligent Configuration Review**: AI-driven analysis of firewall configurations
- **Security Recommendations**: Automated suggestions for improving security posture
- **Risk Assessment**: Identify potential vulnerabilities and misconfigurations

### Secure Connectivity
- **On-Demand SSH Tunnels**: Dynamic reverse tunnels with no exposed ports
- **Web Proxy**: Access firewall web UI through the manager
- **Automatic Cleanup**: Tunnel sessions timeout and clean up automatically

---

## Screenshots

Captured from a live installation with `scripts/take_screenshots.js`. Hostnames, IP
addresses and email addresses are redacted in the rendered page before capture.

### Login
![Login](screenshots/01-login.png)

### Dashboard
KPI strip, per-firewall health cards, status chart and network map.

![Dashboard](screenshots/02-dashboard.png)

### Firewalls
Sortable, filterable fleet list with health scores and status indicators.

![Firewalls](screenshots/03-firewalls.png)

### Customers
Customer organisations used to group managed firewalls. Customers do not log in.

![Customers](screenshots/04-customers.png)

### Alerts
![Alerts](screenshots/05-alerts.png)

### Audit Log
Who did what, to which firewall, from where — filterable by action, user, firewall,
result and date range. Credential material is never recorded.

![Audit Log](screenshots/06-audit-log.png)

### Users and Roles
MSP staff accounts with the Administrator / Technician / Read Only roles.

![Users](screenshots/07-users.png)

### Settings
![Settings](screenshots/08-settings.png)

### About
![About](screenshots/09-about.png)

### Light Theme
Full light theme with system-preference detection and a manual toggle.

![Light Theme](screenshots/10-dashboard-light.png)

---

## System Requirements

### Server Requirements
- **OS**: Ubuntu 22.04 LTS or newer
- **PHP**: 8.0 or higher
- **MySQL/MariaDB**: 8.0+ / 10.6+
- **Web Server**: Apache 2.4+ or Nginx 1.18+
- **Disk Space**: Minimum 10GB (20GB+ recommended for backups)
- **Memory**: Minimum 2GB RAM (4GB+ recommended)

### Managed Firewalls
- **OPNsense**: 20.7+ (tested up to 25.7.x)
- **FreeBSD**: 13.x or 14.x
- **Connectivity**: Outbound HTTPS (443) access to manager server

---

## Quick Start

### 1. Server Installation

```bash
# Clone the repository
cd /var/www
git clone https://github.com/agit8or1/OPNMGR.git opnsense
cd /var/www/opnsense

# Install PHP dependencies
composer install --no-dev

# Import the database schema
# This creates the `opnsense_fw` database, all tables, and the reference data.
mysql -u root -p < database/schema.sql

# Create the application database user
mysql -u root -p -e "
  CREATE USER 'opnsense_user'@'localhost' IDENTIFIED BY 'your-secure-password';
  GRANT ALL PRIVILEGES ON opnsense_fw.* TO 'opnsense_user'@'localhost';
  FLUSH PRIVILEGES;"

# Configure the application
cp .env.example .env
# Edit .env and set at minimum DB_HOST, DB_NAME, DB_USER, DB_PASS and APP_URL
chmod 640 .env

# Create the first administrator account
php scripts/create_admin.php

# Set proper permissions
chown -R www-data:www-data /var/www/opnsense
chmod 755 /var/www/opnsense

# Configure Apache virtual host and reload
a2ensite opnmanager
systemctl reload apache2
```

> **Note:** the schema is safe to re-import — every statement is idempotent.
> To regenerate it from an existing installation, run `scripts/generate_schema.sh`.

### 2. Firewall Enrollment

#### Option A: Quick Enrollment (Recommended)

1. Log into OPNManager web interface
2. Navigate to **Firewalls > Add Firewall**
3. Generate an enrollment key
4. On the OPNsense firewall, run the one-liner install command shown on the page

#### Option B: Manual Plugin Installation

```bash
# On the OPNsense firewall, install the agent plugin:
fetch -o - https://<your-opnmgr-server>/downloads/plugins/install_opnmanager_agent.sh | sh
```

Then configure the agent via the OPNsense web GUI under **Services > OPNManager Agent**.

### 3. Agent Plugin

The OPNManager agent installs as a native OPNsense plugin:

- **Plugin location**: `/usr/local/opnsense/scripts/OPNsense/OPNManagerAgent/agent.sh`
- **Configuration**: Via OPNsense GUI (Services > OPNManager Agent)
- **Service management**: `service opnmanager_agent start|stop|restart`
- **Logs**: `/var/log/opnmanager_agent.log`
- **Auto-update**: Agent checks for updates on each check-in and self-updates

---

## Configuration

### Agent Check-in

The agent checks in every 2 minutes by default. On each check-in, it reports:
- System stats (CPU, memory, disk)
- Network interface status and traffic counters
- Latency measurements
- OPNsense version and update availability
- Pending command results

### Traffic Counter Intelligence

The agent uses the best available counter source:
- **Link layer** (default): Captures all traffic including forwarded/NAT
- **pf counters** (fallback): Used when Link-layer counters are frozen (common with virtio_net on VPS)
- **IP layer** (last resort): Per-address traffic only

---

## Security

### Authentication
- Secure password hashing (PHP `password_hash`)
- Session management with CSRF protection
- Login attempt logging

### Agent Communication
- HTTPS-only agent check-ins
- Hardware ID-based firewall identification
- Base64-encoded command payloads
- PID file locking prevents duplicate agents

### Secure Connections
- On-demand SSH reverse tunnels (dynamic port allocation 8100-8200)
- No exposed firewall ports required
- Automatic tunnel session cleanup

---

## Troubleshooting

### Agent Not Checking In

```bash
# On the OPNsense firewall:
service opnmanager_agent status
tail -20 /var/log/opnmanager_agent.log
```

### Network Data Shows Incorrect Values

- Ensure agent is v1.5.6+ (supports pf counter fallback for virtio_net)
- Check agent log for "Link layer counter frozen" messages
- Traffic data accumulates over time; new installations need ~24h for full graphs

---

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

---

## Support

- Star this repo on [GitHub](https://github.com/agit8or1/OPNMGR)
- Visit [mspreboot.com](https://mspreboot.com)
