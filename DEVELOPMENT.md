# OPNManager - Development Documentation
**Version**: 3.9.0
**Last Updated**: November 12, 2025

---

## Table of Contents
1. [Product Overview](#product-overview)
2. [Core Features](#core-features)
3. [System Architecture](#system-architecture)
4. [Development Standards](#development-standards)
5. [Agent System](#agent-system)
6. [Database Schema](#database-schema)
7. [AI Integration Standards](#ai-integration-standards)
8. [API Endpoints](#api-endpoints)
9. [Common Development Tasks](#common-development-tasks)
10. [Deployment System](#deployment-system)

---

## Product Overview

### What is OPNManager?

OPNManager is a centralized management platform for OPNsense firewalls, providing:
- Real-time monitoring and statistics
- Remote configuration management
- Automated backup and restore
- AI-powered security scanning
- Firewall deployment automation
- License management for deployments

### Target Users
- MSPs managing multiple client firewalls
- Enterprise IT teams with distributed firewalls
- Security professionals needing centralized visibility
- System administrators managing OPNsense deployments

---

## Core Features

### 1. Firewall Management
- **Real-time Monitoring**: CPU, memory, disk, network stats updated every 2 minutes
- **Remote Command Execution**: Execute commands via web interface
- **Configuration Backup**: Automatic and manual backup capabilities
- **Bulk Operations**: Manage multiple firewalls simultaneously

### 2. Agent System (Dual-Agent Architecture)
- **Primary Agent** (v3.9.0): Main management, 2-minute check-in
- **Update Agent**: Failsafe recovery, 5-minute check-in
- **Independent Operation**: Agents work independently for reliability
- **Auto-Recovery**: Update agent can repair/restart primary agent

### 3. AI Security Scanning
- **Config Analysis**: Scan firewall configurations for security issues
- **Log Analysis**: Detect threats and attack patterns
- **Multiple AI Providers**: OpenAI, Anthropic, Google Gemini, Ollama
- **Grading System**: A-F security grades with actionable recommendations

### 4. SSH Tunnel Support
- **Reverse Tunnels**: Connect to firewalls behind NAT/firewalls
- **Automatic Setup**: One-click tunnel establishment
- **Rule Management**: Temporary and permanent firewall rules

### 5. Deployment System
- **Server Deployment**: Deploy OPNManager to remote Ubuntu servers
- **License Management**: Track licenses, trials (30 days + 7 day grace)
- **Push Configuration**: Deploy license changes to deployed servers
- **SSH/Password Auth**: Support both authentication methods

### 6. Monitoring & Statistics
- **Traffic Stats**: Real-time bandwidth monitoring with charts
- **System Health**: CPU, memory, disk, uptime tracking
- **Ping/Latency**: Network latency monitoring
- **Speed Tests**: Bandwidth testing capabilities

---

## System Architecture

### Technology Stack
- **Backend**: PHP 8.3+ with PDO MySQL
- **Frontend**: Bootstrap 5, Chart.js, Font Awesome
- **Database**: MySQL 8.0+
- **Web Server**: Nginx with PHP-FPM
- **Agent**: Shell scripts (POSIX compliant)

### Directory Structure
```
/var/www/opnsense/
├── api/                    # API endpoints (no auth required)
├── inc/                    # Shared includes (auth, db, functions)
├── deployment/             # Deployment system (dev server only)
├── scripts/                # Background scripts
├── keys/                   # SSH keys for firewall access
├── downloads/              # Agent downloads, installation scripts
├── *.php                   # Main application pages
└── *.md                    # Documentation files
```

### Data Flow
```
Firewall Agent (2min) → agent_checkin.php → Database → Web Interface
                              ↓
                    Command Queue Processing
                              ↓
                    Execute on Firewall → Results
```

---

## Development Standards

### Code Style

#### PHP Standards
```php
// PSR-12 coding standard
// Use type hints where possible
function getUserById(int $userId): ?array {
    $stmt = $DB->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Always use prepared statements
$stmt = $DB->prepare("INSERT INTO table (col1, col2) VALUES (?, ?)");
$stmt->execute([$val1, $val2]);

// Error handling
try {
    // Database operations
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    // User-friendly error message
}
```

#### Security Best Practices
1. **SQL Injection Prevention**: Always use prepared statements
2. **XSS Prevention**: Use `htmlspecialchars()` for output
3. **CSRF Protection**: Include CSRF tokens in forms
4. **Authentication**: Use session-based auth, check `isLoggedIn()`
5. **Password Storage**: Use `password_hash()` with bcrypt
6. **Input Validation**: Validate and sanitize all user input

#### File Organization
- **Pages**: Main PHP files in root directory
- **APIs**: JSON endpoints in `/api/` directory
- **Includes**: Shared code in `/inc/` directory
- **Scripts**: Background jobs in `/scripts/` directory

### Database Standards

#### Naming Conventions
- Tables: lowercase with underscores (`firewall_agents`)
- Columns: lowercase with underscores (`last_checkin`)
- Foreign keys: `{table}_id` (e.g., `firewall_id`)
- Indexes: `idx_{column}` or `idx_{table}_{column}`

#### Schema Patterns
```sql
-- Always include timestamps
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP

-- Use ENUMs for fixed values
status ENUM('pending', 'active', 'completed') DEFAULT 'pending'

-- Foreign keys with cascading
FOREIGN KEY (firewall_id) REFERENCES firewalls(id) ON DELETE CASCADE
```

### Frontend Standards

#### JavaScript
- Use vanilla JS or jQuery (already loaded)
- Chart.js for visualizations
- AJAX for dynamic updates
- Bootstrap 5 components

#### CSS
- Bootstrap 5 utility classes preferred
- Custom CSS in `<style>` blocks or external files
- Dark theme colors: `#1a1d29` (background), `#2c3142` (cards)

---

## Agent System

### Dual-Agent Architecture

#### Primary Agent (opnsense_agent_v2.sh)
- **Check-in Interval**: 120 seconds (2 minutes)
- **Endpoint**: `/agent_checkin.php`
- **Location**: `/usr/local/bin/opnsense_agent_v2.sh`
- **Cron**: `*/2 * * * *`
- **Version**: 3.9.0

**Responsibilities**:
- System stats collection (CPU, memory, disk)
- Traffic stats collection
- Command execution from queue
- SSH tunnel management
- Backup operations

#### Update Agent (opnsense_update_agent.sh)
- **Check-in Interval**: 300 seconds (5 minutes)
- **Endpoint**: `/updater_checkin.php`
- **Service**: `opnsense_update_agent` (rc.d)
- **Version**: 1.1.0

**Responsibilities**:
- Monitor primary agent health
- Update/repair primary agent if needed
- Failsafe recovery mechanism

### Critical Rules
1. **NEVER** set primary agent crontab to `*/5` - must be `*/2`
2. **NEVER** make agents dependent on each other
3. **NEVER** use same checkin endpoint for both agents
4. **ALWAYS** maintain separate command queues
5. **NEVER** update update agent remotely (SSH only)

### Agent Installation
```bash
# Deploy primary agent via command queue
firewall_commands table:
  command: "fetch -o /usr/local/bin/opnsense_agent_v2.sh https://..."

# Deploy update agent via primary agent
# Then set up as rc.d service
```

---

## Database Schema

### Core Tables

#### firewalls
```sql
CREATE TABLE firewalls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hostname VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45),
    port INT DEFAULT 443,
    api_key VARCHAR(255),
    api_secret VARCHAR(255),
    ssh_username VARCHAR(50),
    ssh_key_path VARCHAR(255),
    status ENUM('online', 'offline', 'error') DEFAULT 'offline',
    last_checkin DATETIME,
    last_backup DATETIME,
    agent_version VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### firewall_commands
```sql
CREATE TABLE firewall_commands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    firewall_id INT NOT NULL,
    command TEXT NOT NULL,
    description VARCHAR(255),
    status ENUM('pending', 'sent', 'completed', 'failed') DEFAULT 'pending',
    result TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (firewall_id) REFERENCES firewalls(id) ON DELETE CASCADE
);
```

#### firewall_traffic_stats
```sql
CREATE TABLE firewall_traffic_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    firewall_id INT NOT NULL,
    interface VARCHAR(50),
    bytes_in BIGINT,
    bytes_out BIGINT,
    packets_in BIGINT,
    packets_out BIGINT,
    measured_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (firewall_id) REFERENCES firewalls(id) ON DELETE CASCADE
);
```

### Deployment Tables (Development Server Only)

#### deployment_servers
```sql
CREATE TABLE deployment_servers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(255) NOT NULL,
    server_ip VARCHAR(45) NOT NULL,
    ssh_username VARCHAR(50),
    ssh_password VARCHAR(255),
    ssh_key_path VARCHAR(255),
    deployment_status ENUM('pending', 'deploying', 'deployed', 'failed'),
    license_key VARCHAR(255),
    license_status ENUM('trial', 'licensed', 'expired'),
    trial_started_at DATETIME,
    last_license_checkin DATETIME,
    license_checkin_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

#### licenses
```sql
CREATE TABLE licenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_key VARCHAR(255) UNIQUE NOT NULL,
    license_type ENUM('standard', 'professional', 'enterprise'),
    max_firewalls INT DEFAULT 100,
    status ENUM('available', 'assigned', 'suspended'),
    assigned_to INT,  -- deployment_servers.id
    suspended TINYINT(1) DEFAULT 0,
    suspended_reason TEXT,
    expires_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assigned_to) REFERENCES deployment_servers(id)
);
```

---

## AI Integration Standards

### Supported Providers
1. **OpenAI**: gpt-4-turbo, gpt-3.5-turbo
2. **Anthropic**: claude-3-5-sonnet-20241022
3. **Google**: gemini-pro
4. **Ollama**: Self-hosted (mistral, llama2)

### Data Sanitization
**MUST remove before sending to AI**:
- Passwords/password hashes
- API keys and tokens
- Private keys
- Email addresses → `<EMAIL_REDACTED>`
- Internal hostnames → `<HOST_REDACTED>`

### Scan Types
1. **Config-Only Scan**: 15-45 seconds
2. **Config + Logs Scan**: 60-180 seconds

### Report Structure
```json
{
  "overall_grade": "A|B|C|D|F",
  "security_score": 0-100,
  "risk_level": "low|medium|high|critical",
  "summary": "Brief overview",
  "findings": [
    {
      "severity": "high",
      "category": "security",
      "title": "Finding title",
      "description": "Details",
      "impact": "What could go wrong",
      "recommendation": "How to fix"
    }
  ]
}
```

---

## API Endpoints

### Public APIs (No Auth Required)
Located in `/api/` directory:

```
GET /api/get_traffic_stats.php?firewall_id=X&hours=24
GET /api/get_system_stats.php?firewall_id=X
GET /api/get_latency_stats.php?firewall_id=X
POST /api/license_checkin.php
```

### Agent Endpoints
```
POST /agent_checkin.php - Primary agent check-in
POST /updater_checkin.php - Update agent check-in
```

### Response Format
```json
{
  "success": true,
  "data": { ... },
  "message": "Optional message"
}

// Error response
{
  "success": false,
  "error": "Error message"
}
```

---

## Common Development Tasks

### Adding a New Page
1. Create `new_page.php` in root directory
2. Add authentication check:
```php
<?php
require_once 'inc/auth.php';
requireLogin();
include 'inc/header.php';
?>
<!-- Page content -->
<?php include 'inc/footer.php'; ?>
```

3. Add to navigation in `inc/navigation.php`

### Adding a Database Table
1. Create migration SQL file
2. Test locally
3. Update schema documentation
4. Add to deployment scripts if needed

### Adding Agent Functionality
1. Update agent script
2. Increment version number
3. Test on FreeBSD/OPNsense
4. Upload to `/downloads/`
5. Create update command for existing firewalls

### Creating an API Endpoint
1. Create file in `/api/` directory
2. Return JSON response
3. Handle errors gracefully
4. Test with curl:
```bash
curl "http://localhost/api/endpoint.php?param=value"
```

---

## Deployment System

### License Management

#### Trial Period
- **Duration**: 30 days from deployment
- **Grace Period**: 7 days after trial ends
- **Check-in**: Every 4 hours via `/api/license_checkin.php`

#### License States
- `trial`: Active trial period
- `grace_period`: Trial expired, grace period active
- `active`: Valid license assigned
- `expired`: Grace period ended
- `suspended`: License administratively suspended

### Deployment Process
1. Create deployment server record
2. Generate/assign license key
3. Deploy via SSH (automated)
4. Configure database and users
5. Install application files
6. Start trial period
7. Verify check-in

### Pushing License Changes
Use "Push to Server" button in edit_license.php:
- Creates license JSON file
- Deploys to `/usr/local/etc/opnsense_manager/license.json`
- Server reads on next check-in

---

## Testing & Debugging

### Common Issues

**Agent Not Checking In**
```bash
# On firewall
ps aux | grep opnsense_agent
tail -f /var/log/opnsense_agent.log
crontab -l
```

**Database Connection Issues**
```php
// Test database connection
php -r "require 'inc/db.php'; echo 'Connected';"
```

**Charts Not Loading**
- Check browser console for errors
- Verify API endpoints return JSON
- Check database for recent stats

### Logging
```php
// Application logging
error_log("Debug message: " . print_r($data, true));

// Database queries
error_log("SQL: " . $stmt->queryString);
```

---

## Contributing Guidelines

### Before Submitting Changes
1. Test locally on development server
2. Verify database schema changes
3. Update documentation if needed
4. Test on production-like environment
5. Check for security vulnerabilities

### Code Review Checklist
- [ ] No SQL injection vulnerabilities
- [ ] All user input sanitized
- [ ] Error handling implemented
- [ ] Logging added for debugging
- [ ] Performance considerations addressed
- [ ] Documentation updated

---

## Version History

| Version | Date | Major Changes |
|---------|------|---------------|
| 3.9.0 | Nov 2025 | License system, deployment automation, HTTP 500 fixes |
| 3.6.1 | Oct 2025 | Dual-agent system, tunnel support |
| 2.2.3 | Oct 2025 | AI scanning, security enhancements |
| 2.1.0 | Oct 2025 | Backup improvements, breadcrumbs |
| 1.0.0 | Sep 2025 | Initial release |

---

## Quick Reference

### Important Files
- `/inc/auth.php` - Authentication functions
- `/inc/db.php` - Database connection
- `/inc/version.php` - Version information
- `/agent_checkin.php` - Primary agent endpoint
- `/deployment/deploy_exec.php` - Deployment engine

### Important Constants
```php
IS_DEVELOPMENT_SERVER  // true on dev, false on deployments
DB_HOST, DB_NAME, DB_USER, DB_PASS  // Database credentials
APP_NAME, APP_VERSION  // Application info
```

### Useful Commands
```bash
# View agent logs
ssh root@firewall tail -f /var/log/opnsense_agent.log

# Force agent update
# Via web interface: Firewalls → Commands → Add Command

# Check database
sudo mysql opnsense_fw -e "SELECT * FROM firewalls"

# View nginx logs
tail -f /var/log/nginx/access.log | grep agent_checkin
```

---

**Document Maintainer**: Development Team
**Review Schedule**: Monthly
**Next Review**: December 2025
