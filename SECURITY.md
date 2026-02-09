# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| Latest  | :white_check_mark: |

## Reporting a Vulnerability

**Please do NOT create public GitHub issues for security vulnerabilities.**

### How to Report

1. **GitHub Private Vulnerability Reporting**: Use [GitHub's security advisory feature](https://github.com/agit8or1/OPNMGR/security/advisories/new)
2. **Include**:
   - Detailed description of the vulnerability
   - Steps to reproduce
   - Potential impact
   - Suggested fix (if available)

### What to Expect

- **Initial Response**: Within 48 hours
- **Status Update**: Within 7 days
- **Resolution Timeline**: 90 days for critical issues

### Disclosure Policy

- We follow **responsible disclosure** principles
- We request a **90-day embargo** before public disclosure
- We will credit researchers (unless anonymity is requested)

---

## Security Features

### Authentication & Access Control

- **Session Management**: Secure session handling with regeneration after login
- **Role-Based Access Control**: Admin and viewer roles with different privileges
- **Password Hashing**: Secure hashing using PHP's `password_hash()`
- **CSRF Protection**: CSRF tokens on all state-changing operations

### Input Validation

- **SQL Injection Protection**: Prepared statements throughout
- **XSS Protection**: Input sanitization and output encoding
- **Command Injection Protection**: Proper escaping of shell commands

### Network Security

- **HTTPS Agent Communication**: SSL/TLS encryption for all agent check-ins
- **Hardware ID Authentication**: Firewalls identified by unique hardware ID
- **On-Demand SSH Tunnels**: Dynamic reverse tunnels with no exposed firewall ports
- **Automatic Tunnel Cleanup**: Sessions timeout and clean up automatically

### Monitoring & Logging

- **Audit Logging**: All administrative actions logged
- **Failed Login Tracking**: Security event logging
- **Snyk Integration**: Continuous vulnerability scanning

---

## Security Best Practices

### For Administrators

1. **Change Default Password**: Change the default `admin`/`admin123` credentials immediately after installation
2. **Use Strong Passwords**: Minimum 12 characters with a mix of character types
3. **Enable HTTPS**: Configure SSL/TLS on the manager server
4. **Limit Network Access**: Use firewall rules to restrict access to the management interface
5. **Review Logs Regularly**: Check the Logs page for suspicious activity
6. **Regular Backups**: Configure automated backups and test restoration

### For Developers

1. **Always use prepared statements for SQL queries**
2. **Escape output with `htmlspecialchars()`**
3. **Use `escapeshellarg()` for shell commands**
4. **Add CSRF tokens to all forms**
5. **Require authentication on all sensitive endpoints**

---

## Known Security Considerations

1. **Agent Command Execution**: The agent system executes queued commands on managed firewalls. Commands are stored in the database and picked up on the next agent check-in.

2. **SSH Key Management**: SSH keys are used for on-demand tunnel connections. Keys are stored in the database (base64-encoded) and on disk.

3. **Database Access**: The database contains firewall configurations and SSH keys. Restrict database network access and use strong passwords.

---

## Security Compliance

OPNManager follows security best practices from:
- **OWASP Top 10**: Protection against common web vulnerabilities
- **CWE Top 25**: Mitigation of dangerous software weaknesses

---

## Additional Resources

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [CWE Top 25](https://cwe.mitre.org/top25/)
- [PHP Security Guide](https://www.php.net/manual/en/security.php)

---

For the latest version of this policy, visit: [SECURITY.md on GitHub](https://github.com/agit8or1/OPNMGR/blob/main/SECURITY.md)
