# OPNsense Manager - One-Click Installation

## Quick Setup (Copy & Paste)

```bash
# Download and run the complete installer
curl -fsSL https://raw.githubusercontent.com/yourusername/opnsense-manager/main/install_opnsense_manager.sh -o install_opnsense_manager.sh
chmod +x install_opnsense_manager.sh
sudo ./install_opnsense_manager.sh
```

Or if you have the script locally:

```bash
# Make executable and run
chmod +x install_opnsense_manager.sh
sudo ./install_opnsense_manager.sh
```

## What the installer does:

✅ **Complete System Setup**
- Updates FreeBSD/OPNsense packages automatically
- Installs nginx, PHP 8.3, MariaDB with optimal configuration
- Configures all services for immediate use

✅ **Automated Database Setup**
- Creates secure MySQL installation with random passwords
- Sets up opnsense_fw database with full schema
- Creates all required tables and relationships
- Adds default admin user and sample data

✅ **Complete Web Application Deployment**
- Deploys full OPNsense Manager with all features
- Configures nginx with PHP-FPM for optimal performance
- Sets proper file permissions and security
- Creates all API endpoints and interfaces

✅ **Automatic OPNsense Integration**
- **Auto-detects if running on OPNsense system**
- **Generates API credentials automatically**
- **Creates localhost firewall entry pre-configured**
- **Sets up update management ready-to-use**
- No manual API credential entry required!

✅ **Security & Authentication**
- Generates cryptographically secure random passwords
- Implements CSRF protection throughout
- Sets up secure authentication system
- Configures proper file permissions automatically

✅ **Production-Ready Features**
- Complete firewall management dashboard
- Real-time update detection and management
- Comprehensive logging with automatic cleanup
- Agent checkin system with enrollment tokens
- Customer/group organization system
- Tag management for firewall categorization

✅ **Automated Maintenance**
- Daily log cleanup cron job (configurable retention)
- Automated system monitoring
- Self-healing maintenance tasks
- Performance optimization

## Default Credentials

- **Web Interface**: `admin` / `admin123`
- **Database**: Auto-generated secure passwords
- **Installation details**: Saved to `/root/opnsense_manager_install.txt`

## Post-Installation

1. Access web interface at `http://YOUR_SERVER_IP`
2. Login with default credentials: `admin` / `admin123`
3. **Change default password immediately**
4. **Localhost firewall already configured and ready**
5. Add additional remote firewalls as needed

**🎉 Zero Configuration Required!**
- If installed on OPNsense: Localhost firewall auto-configured with API
- Update management works immediately
- No manual API credential setup needed

## Requirements

- FreeBSD 13+ or OPNsense 25.7+
- Root access
- Internet connection
- At least 1GB RAM
- 10GB disk space

## Features Ready After Install

🔥 **Firewall Management**
- Automatic enrollment with tokens
- Real-time status monitoring
- Hardware ID tracking
- Customer/group organization

🔄 **Update Management**
- Accurate update detection
- Remote update execution
- API integration ready
- Update history tracking

📊 **Monitoring & Logging**
- Comprehensive system logs
- Agent checkin tracking
- Performance monitoring
- Automated cleanup

🛡️ **Security**
- CSRF protection
- Secure authentication
- Input validation
- SQL injection protection

## Troubleshooting

If installation fails:
1. Check system requirements
2. Ensure internet connectivity
3. Verify root permissions
4. Check system logs: `/var/log/nginx/error.log`

## Support

Installation creates:
- `/root/opnsense_manager_install.txt` - Complete installation details
- `/var/log/nginx/error.log` - Web server errors
- `/var/log/php-fpm.log` - PHP errors
- Database logs via web interface

---

**Ready to manage your OPNsense firewalls with a single copy-paste command!**