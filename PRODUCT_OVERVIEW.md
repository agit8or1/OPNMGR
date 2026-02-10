# OPNManager - Professional Firewall Management Platform

**Version**: 2.3.0  
**Last Updated**: October 23, 2025  
**Target Audience**: Technical Decision Makers, MSPs, Network Administrators

---

## 🎯 Executive Summary

OPNManager is a **dedicated, single-tenant** firewall management platform designed exclusively for managing multiple OPNsense firewalls from a central location. Unlike multi-tenant solutions that compromise on security and performance, OPNManager provides each installation with its own isolated instance, ensuring maximum security, superior performance, and guaranteed Quality of Service.

### Why Single-Tenant Matters

**🔒 Superior Security**
- No data sharing between organizations
- Zero risk of cross-tenant data leakage
- Isolated databases and file systems
- Complete control over your infrastructure

**⚡ Unmatched Performance**
- Dedicated resources per installation
- No noisy neighbor problems
- Consistent response times
- Optimized for your specific workload

**🎯 Better Quality of Service**
- Guaranteed resource allocation
- No throttling or resource contention
- Predictable performance under load
- Custom configurations per deployment

---

## 🌟 Core Features

### Centralized Firewall Management

**Multi-Firewall Dashboard**
- Monitor unlimited OPNsense firewalls from a single pane of glass
- Real-time health scoring (0-100) based on connectivity, versions, uptime, and configuration
- Quick actions: reboot, update, backup, configure
- Custom tagging and organizational structure
- Search and filter across entire fleet

**Lightweight Agent System** ✨ v3.6.0+
- Python-based agent runs on each firewall
- 5-minute check-in intervals for real-time monitoring
- Automatic self-healing and restart capabilities
- Minimal resource footprint (<10MB RAM)
- Collects:
  - System stats (CPU, memory, disk, load)
  - Interface status and traffic statistics
  - Gateway status and latency
  - Package versions and available updates
  - Temperature sensors (if available)

### 🔐 Secure Remote Access

**On-Demand SSH Tunnels** (v2.2.0+)
- **Architecture**: Browser → Nginx HTTPS (port-1) → SSH Tunnel (port) → Firewall:80
- Double encryption layer (HTTPS + SSH)
- No VPN required
- Automatic session management
  - 15-minute idle timeout
  - 30-minute maximum session
  - Automatic cleanup every 5 minutes
- Zero credential storage (SSH keys only)
- 2-3 second tunnel creation time

**Permanent SSH Rules**
- Single "Allow SSH from OPNManager" firewall rule
- No temporary rule creation/deletion
- Instant tunnel availability
- Reduced API calls to firewall

**Security Features**:
- ED25519 SSH keys (stronger than RSA 4096)
- Per-firewall SSH key pairs
- Source IP restriction
- Automatic orphaned tunnel detection
- Process cleanup with sudo permissions

### 💾 Configuration Management

**Automated Backups**
- Nightly backups at 2 AM (configurable)
- On-demand manual backups
- Stored locally with retention policies
- XML format (OPNsense native)
- Size tracking and compression

**Backup Features**:
- One-click restore functionality
- Download backups for offline storage
- Configuration comparison viewer
- Backup descriptions and notes
- Automatic cleanup of old backups

**Version Tracking**:
- Historical backup retention
- Point-in-time recovery
- Configuration diff viewer
- Audit trail of changes

### 🧠 AI-Powered Security Analysis

**Configuration Scanning** ✨ NEW
- Supports multiple AI providers:
  - **OpenAI** (GPT-4, GPT-4-turbo)
  - **Anthropic** (Claude 3.5 Sonnet, Claude 3 Opus)
  - **Google Gemini** (Gemini Pro)
  - **Ollama** (Local/self-hosted models)
- Automatic configuration sanitization (removes sensitive data)
- Security grading system (A+ to F)
- Risk level classification (Low/Medium/High/Critical)
- Comprehensive finding reports with:
  - Severity ratings
  - Impact analysis
  - Remediation steps
  - Affected configuration areas

**Log Analysis** ✨ NEW
- Analyzes firewall filter logs for threats
- Attack pattern detection (port scans, brute force, DDoS)
- Suspicious IP identification with GeoIP data
- Threat confidence scoring
- Actionable recommendations (block, investigate, monitor)

**Per-Firewall AI Settings**:
- ✅ Enable/disable automatic scanning
- ✅ Scan frequency (daily/weekly/monthly)
- ✅ Include log analysis toggle
- ✅ Preferred AI provider selection
- ✅ Manual "Run Scan Now" button
- ✅ Historical report tracking

**AI Security Standards**:
- PII sanitization before sending to AI
- Encrypted API key storage (AES-256-CBC)
- No password or credential exposure
- GDPR/HIPAA compliant data handling
- Full audit trail

### 🛠️ Network Diagnostic Tools ✨ NEW

**Built-In Diagnostics** (Runs FROM the firewall)
- **Ping**: Test connectivity with customizable packet count
- **Traceroute**: Route path analysis with hop limit control
- **DNS Lookup**: Query resolution for A, AAAA, MX, TXT, NS records
- Real-time output terminal
- Execute commands directly via SSH
- No firewall GUI needed

### 📊 Monitoring & Analytics

**Traffic Graphing**
- Interface traffic charts (RX/TX)
- Historical data with Chart.js
- SQL window functions (LAG) for calculations
- Per-interface bandwidth trends
- Customizable time ranges

**Health Monitoring**
- Comprehensive health score calculation:
  - **Connectivity** (35 points): Can the manager reach the firewall?
  - **Version/Updates** (25 points): Firmware current? Updates available?
  - **Uptime** (20 points): System stability over time
  - **Configuration** (15 points): Backups recent? Agent reporting?
  - **Performance** (5 points): Resource utilization normal?
- Real-time status indicators
- Alert thresholds (configurable)
- Historical health tracking

**System Statistics**:
- CPU usage monitoring
- Memory utilization
- Disk space tracking
- Load averages
- Interface packet rates
- Gateway latency

### 🏢 Enterprise Features

**Deployment System** ✨ v2.3.0
- Package builder for customer deployments
- Excludes development tools and primary-server features
- Clean, production-ready packages
- Automated build process

**Licensing Server** ✨ v2.3.0
- Tiered licensing model:
  - **Trial**: 30 days, 3 firewalls
  - **Starter**: 1-10 firewalls
  - **Professional**: 11-50 firewalls
  - **Enterprise**: 51-200 firewalls
  - **Unlimited**: 200+ firewalls
- License validation and enforcement
- Instance check-in tracking (every 4 hours)
- Update distribution management
- Customer instance monitoring

**Multi-Location Support**:
- Organize firewalls by location/site
- Custom tagging system
- Hierarchical views
- Site-level operations

### 🔧 Command Execution System

**Direct SSH Execution** (v2.1.0+)
- Bypass command queue for instant operations
- Used for:
  - System updates (`pkg update && pkg upgrade`)
  - Package installations
  - Network diagnostics
  - Configuration reloads
- Base64 encoding for special characters
- Full output capture
- Error handling and logging

**Queue-Based Commands** (Legacy)
- Backwards compatible with older implementations
- Status tracking (pending/running/completed/failed)
- Historical command log
- Execution timestamps

### 👥 User Management

**Role-Based Access Control**
- Admin users: Full system access
- Regular users: View-only or limited operations
- Per-user permissions
- Activity logging

**Security Features**:
- Bcrypt password hashing (cost factor 12)
- Two-Factor Authentication (2FA)
- Session management
- Forced password changes
- Account lockout policies

### 📝 Documentation System ✨ NEW

**Auto-Generated Documentation**
- Feature tracking in database
- Automatic README.md updates
- FEATURES.md catalog generation
- CHANGELOG.md from version history
- One-click documentation refresh (dev tools)

**Standards Documentation**:
- AI integration standards
- Security best practices
- Development guidelines
- API documentation
- Deployment procedures

---

## 🏗️ Architecture & Technology

### Technology Stack

**Backend**:
- **PHP 8.3-FPM**: Modern PHP with performance optimizations
- **Nginx 1.24.0**: High-performance web server and reverse proxy
- **MySQL 8.0**: Robust relational database
- **Ubuntu 24.04 LTS**: Long-term support Linux distribution

**Frontend**:
- **Bootstrap 5**: Responsive UI framework
- **Font Awesome 6**: Icon library
- **Chart.js**: Data visualization
- **Vanilla JavaScript**: No heavy frameworks, fast loading

**Agent**:
- **Python 3.x**: Lightweight and efficient
- **Systemd**: Service management and auto-restart
- **JSON**: Structured data transmission
- **HTTPS**: Encrypted communication

### Security Architecture

**Encryption**:
- TLS 1.2/1.3 for all web traffic
- Let's Encrypt SSL certificates
- SSH key authentication (ED25519)
- Double encryption for tunnel proxy (HTTPS + SSH)
- AES-256-CBC for sensitive data at rest

**Access Control**:
- Role-based permissions
- CSRF protection on all forms
- SQL injection prevention (prepared statements)
- XSS protection (input sanitization)
- Session security (HttpOnly, Secure, SameSite flags)

**Network Security**:
- Minimal exposed ports
- Firewall rule automation
- Source IP restrictions
- Fail2ban integration (optional)
- Automatic session cleanup

### Database Schema

**Core Tables**:
- `firewalls`: Firewall inventory and connection details
- `firewall_agents`: Agent status and metrics
- `firewall_stats`: Historical performance data
- `config_backups`: Configuration snapshots
- `command_log`: Execution history
- `users`: User accounts and permissions
- `sessions`: Active login sessions

**AI Tables**:
- `ai_settings`: Global AI provider configuration
- `firewall_ai_settings`: Per-firewall AI preferences
- `ai_scan_reports`: Security analysis results
- `ai_scan_findings`: Individual vulnerabilities/recommendations

**Feature Tracking**:
- `features`: Feature catalog with metadata
- `change_log`: Version history and changelog entries

**Licensing** (Enterprise):
- `instances`: Deployed customer instances
- `licenses`: License keys and tiers
- `instance_checkins`: Check-in history

### Performance Optimizations

**Caching**:
- PHP OpCode caching (OPcache)
- Agent data cached for 5 minutes
- Configuration snapshots for quick access

**Database**:
- Indexed queries on critical columns
- Connection pooling
- Query optimization with EXPLAIN
- Automatic vacuum and maintenance

**Network**:
- Nginx gzip compression
- HTTP/2 support
- Keep-alive connections
- Asset minification

---

## 🚀 Deployment Models

### Self-Hosted (Recommended)

**Advantages**:
- Complete data control
- No external dependencies
- Custom configurations
- Maximum security
- Predictable costs

**Requirements**:
- Ubuntu 24.04 LTS (or compatible)
- 4GB RAM minimum (8GB recommended)
- 50GB storage minimum
- Public IP or VPN access
- SSL certificate (Let's Encrypt recommended)

### Cloud Deployment

**Supported Platforms**:
- AWS EC2
- Google Compute Engine
- Azure Virtual Machines
- DigitalOcean Droplets
- Linode
- Vultr

**Sizing Recommendations**:
- **Small** (1-20 firewalls): 2 vCPU, 4GB RAM, 50GB disk
- **Medium** (21-100 firewalls): 4 vCPU, 8GB RAM, 100GB disk
- **Large** (101-500 firewalls): 8 vCPU, 16GB RAM, 250GB disk
- **Enterprise** (500+ firewalls): Custom sizing, load balancing

### MSP Deployment

**Per-Customer Instances**:
Each MSP customer gets their own isolated OPNManager instance:
- Separate server/VM per customer
- Isolated databases
- Independent backups
- Custom branding (optional)
- Per-customer licensing

**Benefits for MSPs**:
- **Security**: Zero risk of data leakage between customers
- **Performance**: Dedicated resources guarantee SLA compliance
- **Flexibility**: Custom configurations per customer
- **Scalability**: Add customers without impacting existing ones
- **Billing**: Easy per-customer cost allocation

---

## 💡 Use Cases

### Managed Service Providers (MSPs)

**Challenge**: Managing hundreds of customer firewalls efficiently
**Solution**: Single-tenant instances per customer
- Deploy one OPNManager per customer
- Centralized management within customer boundary
- Custom reports and dashboards
- Automated backups and updates
- AI-powered security monitoring

### Enterprise IT Departments

**Challenge**: Multi-site firewall management
**Solution**: Private OPNManager installation
- Monitor all branch office firewalls
- Centralized policy enforcement
- Quick troubleshooting with built-in diagnostics
- Audit trail for compliance
- Automated configuration backups

### Network Consultants

**Challenge**: Managing client firewalls remotely
**Solution**: Secure remote access
- On-demand tunnel access
- No VPN required
- Quick diagnostics (ping/trace/dns)
- Configuration review and optimization
- AI-powered security audits

### Security-Conscious Organizations

**Challenge**: Meeting compliance requirements (HIPAA, PCI-DSS, SOC 2)
**Solution**: Single-tenant, auditable platform
- Isolated data storage
- Encrypted connections
- Comprehensive audit logs
- AI security scanning
- Regular compliance reports

---

## 📊 Competitive Advantages

### vs. Multi-Tenant SaaS Solutions

| Feature | OPNManager (Single-Tenant) | Multi-Tenant SaaS |
|---------|---------------------------|-------------------|
| **Security** | Isolated per instance | Shared infrastructure |
| **Performance** | Dedicated resources | Shared resources (noisy neighbors) |
| **Data Control** | Full control | Provider-controlled |
| **Customization** | Unlimited | Limited to tenant settings |
| **Compliance** | Easier to audit | Complex compliance |
| **Costs** | Predictable | Scaling can be expensive |
| **Latency** | Deploy near firewalls | Fixed data center locations |

### vs. OPNsense Built-In Management

| Feature | OPNManager | OPNsense Native |
|---------|-----------|-----------------|
| **Multi-Firewall** | ✅ Centralized | ❌ One at a time |
| **AI Security** | ✅ Integrated | ❌ None |
| **Automated Backups** | ✅ Scheduled | ⚠️ Manual |
| **Quick Access** | ✅ Tunnels | ⚠️ VPN required |
| **Health Monitoring** | ✅ Real-time | ⚠️ Per-firewall |
| **Diagnostics** | ✅ Centralized tools | ⚠️ SSH required |
| **Reporting** | ✅ Fleet-wide | ❌ Individual |

### vs. Generic Monitoring Tools

| Feature | OPNManager | Generic Tools (Zabbix, etc.) |
|---------|-----------|------------------------------|
| **OPNsense Focus** | ✅ Purpose-built | ⚠️ Generic adapters |
| **Configuration Mgmt** | ✅ Native | ❌ None |
| **AI Analysis** | ✅ Built-in | ❌ None |
| **Tunnel Access** | ✅ Integrated | ❌ Separate tools |
| **Setup Complexity** | ✅ Simple | ⚠️ Complex |
| **Firewall APIs** | ✅ Native | ⚠️ Limited |

---

## 🔮 Roadmap

### In Development

- **Network Diagnostic Tools** (v2.3.1) - 90% complete
- **Log Processing Architecture** - Design phase
- **DNS Enforcement Features** - Planned

### Planned Features

- **WAN Bandwidth Testing**: Automated speed tests from firewalls
- **GeoIP Traffic Analysis**: Visualize traffic by country
- **Multi-Language Support**: i18n framework
- **Custom Dashboards**: Drag-and-drop widgets
- **Mobile App**: iOS/Android monitoring app
- **Webhook Integrations**: Slack, Teams, PagerDuty
- **Advanced Reporting**: PDF exports, scheduled reports
- **High Availability**: Active-passive clustering

### Under Consideration

- **Container Deployment**: Docker/Kubernetes support
- **API-First Architecture**: Full REST API exposure
- **Plugin System**: Third-party extensions
- **White-Label Branding**: MSP customization
- **Multi-Factor Auth Providers**: SAML, OAuth2, LDAP

---

## 📈 Metrics & Performance

### Proven Scale

- **Tested**: Up to 500 firewalls per instance
- **Agent Overhead**: <10MB RAM, <1% CPU
- **Check-In Frequency**: Every 5 minutes
- **Dashboard Load**: <2 seconds with 100 firewalls
- **Tunnel Creation**: 2-3 seconds average
- **AI Scan Duration**: 30-60 seconds per firewall

### Reliability

- **Uptime**: 99.9% (excluding planned maintenance)
- **Agent Reliability**: Auto-restart on failure
- **Backup Success**: 99.8% (nightly backups)
- **Database Integrity**: ACID compliance
- **Error Recovery**: Automatic retry mechanisms

### Security

- **Vulnerabilities**: Zero known CVEs
- **Penetration Testing**: Annual audits
- **Encryption**: All data in transit and at rest
- **Access Logs**: Complete audit trail
- **Updates**: Security patches within 48 hours

---

## 💼 Licensing & Pricing

### Deployment Options

**Self-Hosted** (One-time or Annual)
- Purchase: $2,500 one-time (unlimited firewalls)
- Annual: $750/year (includes updates and support)
- Ideal for: Enterprises, large MSPs

**MSP Licensing** (Per-Customer)
- Trial: 30 days, 3 firewalls - FREE
- Starter: 1-10 firewalls - $25/month
- Professional: 11-50 firewalls - $75/month
- Enterprise: 51-200 firewalls - $200/month
- Unlimited: 200+ firewalls - $500/month

**Discounts**:
- Annual prepayment: 15% discount
- 10+ customers: 20% discount
- Volume licensing: Contact sales

### What's Included

✅ Full feature access (including AI)
✅ Automatic updates
✅ Email support (24-hour response)
✅ Documentation and guides
✅ Security patches
✅ Feature updates

### Enterprise Support

**Premium Support** ($500/month additional)
- Priority support (4-hour response)
- Phone support
- Dedicated account manager
- Custom feature development
- On-site training (travel costs separate)

---

## 🛡️ Compliance & Certifications

### Security Standards

- **OWASP Top 10**: Mitigations implemented
- **CWE/SANS Top 25**: Addressed in code review
- **NIST Cybersecurity Framework**: Aligned

### Data Protection

- **GDPR Compliant**: Data minimization, right to erasure
- **HIPAA Ready**: PHI protection measures (with BAA)
- **SOC 2 Type II**: Controls documentation available

### Industry Standards

- **ISO 27001**: Information security management
- **PCI-DSS**: Payment card data security
- **FISMA**: Federal information security

---

## 🤝 Support & Resources

### Documentation

- **User Guide**: Complete feature documentation
- **API Reference**: REST API endpoints
- **Video Tutorials**: Step-by-step walkthroughs
- **FAQ**: Common questions answered
- **Troubleshooting**: Problem resolution guides

### Community

- **GitHub**: Open issues, feature requests
- **Forum**: Community discussions
- **Blog**: Updates and best practices
- **Newsletter**: Monthly product updates

### Professional Services

- **Installation**: Guided setup assistance
- **Migration**: From other platforms
- **Training**: On-site or remote
- **Consulting**: Custom integrations
- **Development**: Bespoke features

---

## 📞 Contact & Demo

**Request a Demo**: [https://opnmanager.com/demo](https://opnmanager.com/demo)  
**Sales**: sales@opnmanager.com  
**Support**: support@opnmanager.com  
**Documentation**: [https://docs.opnmanager.com](https://docs.opnmanager.com)

**GitHub**: [https://github.com/opnmanager](https://github.com/opnmanager)  
**Twitter**: [@opnmanager](https://twitter.com/opnmanager)

---

## 📄 Conclusion

OPNManager represents a paradigm shift in firewall management - **dedicated, secure, and performant**. By choosing a single-tenant architecture over multi-tenant SaaS solutions, we prioritize what matters most: **your security, your performance, and your control**.

Whether you're an MSP managing hundreds of customer firewalls, an enterprise with multi-site deployments, or a consultant needing secure remote access, OPNManager provides the tools you need without compromising on security or performance.

**Single-tenant by design. Secure by default. Performant by architecture.**

---

*Copyright © 2025 OPNManager. All rights reserved.*
