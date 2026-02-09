# OPNManager - Installation Guide

## Server Requirements

- **OS**: Ubuntu 22.04 LTS or newer
- **PHP**: 8.0 or higher
- **MySQL/MariaDB**: 8.0+ / 10.6+
- **Web Server**: Apache 2.4+ or Nginx 1.18+
- **Disk Space**: Minimum 10GB (20GB+ recommended for backups)
- **Memory**: Minimum 2GB RAM (4GB+ recommended)

## Quick Setup

### 1. Clone the Repository

```bash
cd /var/www
git clone https://github.com/agit8or1/OPNMGR.git opnsense
chown -R www-data:www-data /var/www/opnsense
chmod 755 /var/www/opnsense
```

### 2. Import Database Schema

```bash
mysql -u root -p < /var/www/opnsense/database/schema.sql
```

### 3. Configure Database Connection

```bash
cp /var/www/opnsense/inc/db.php.example /var/www/opnsense/inc/db.php
```

Edit `inc/db.php` and set your database credentials:
- `DB_HOST` - Database hostname (usually `localhost`)
- `DB_NAME` - Database name (default: `opnsense_fw`)
- `DB_USER` - Database username
- `DB_PASS` - Database password

### 4. Configure Web Server

#### Apache

Create a virtual host configuration pointing to `/var/www/opnsense/` and enable it:

```bash
a2ensite opnmanager
systemctl reload apache2
```

### 5. Access the Web Interface

1. Navigate to `https://mspreboot.com`
2. Login with default credentials: `admin` / `admin123`
3. **Change the default password immediately**

## Enrolling Firewalls

### Option A: Quick Enrollment (Recommended)

1. Log into the OPNManager web interface
2. Navigate to **Firewalls > Add Firewall**
3. Generate an enrollment key
4. On the OPNsense firewall, run the one-liner install command shown on the page

### Option B: Manual Plugin Installation

On the OPNsense firewall:

```bash
fetch -o - https://<your-opnmgr-server>/downloads/plugins/install_opnmanager_agent.sh | sh
```

Then configure the agent via the OPNsense web GUI under **Services > OPNManager Agent**.

## Agent Plugin Details

The OPNManager agent installs as a native OPNsense plugin:

- **Plugin location**: `/usr/local/opnsense/scripts/OPNsense/OPNManagerAgent/agent.sh`
- **Configuration**: Via OPNsense GUI (Services > OPNManager Agent)
- **Service management**: `service opnmanager_agent start|stop|restart`
- **Logs**: `/var/log/opnmanager_agent.log`
- **Auto-update**: Agent checks for updates on each check-in and self-updates

## Managed Firewall Requirements

- **OPNsense**: 20.7+ (tested up to 25.7.x)
- **FreeBSD**: 13.x or 14.x
- **Connectivity**: Outbound HTTPS (443) access to the manager server

## Troubleshooting

### Agent Not Checking In

```bash
# On the OPNsense firewall:
service opnmanager_agent status
tail -20 /var/log/opnmanager_agent.log
```

### Web Interface Not Loading

- Check web server error log: `/var/log/apache2/error.log`
- Check PHP error log: `/var/log/php-fpm.log`
- Verify database connectivity: `mysql -u root opnsense_fw -e "SELECT 1"`

### Database Connection Errors

- Verify credentials in `inc/db.php`
- Ensure MySQL/MariaDB service is running: `systemctl status mysql`

---

For full documentation, see the [README](README.md).
