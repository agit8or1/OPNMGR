# Security Policy

## Supported Versions

We release security updates for the following versions:

| Version | Supported          |
| ------- | ------------------ |
| 3.0.x   | :white_check_mark: |
| 2.x.x   | :x:                |
| 1.x.x   | :x:                |

## Reporting a Vulnerability

**Please do NOT create public GitHub issues for security vulnerabilities.**

### How to Report

1. **Email**: Send details to **security@yourdomain.com**
2. **Subject**: "Security Vulnerability Report - [Brief Description]"
3. **Include**:
   - Detailed description of the vulnerability
   - Steps to reproduce
   - Potential impact
   - Suggested fix (if available)
   - Your contact information

### What to Expect

- **Initial Response**: Within 48 hours
- **Status Update**: Within 7 days
- **Resolution Timeline**: 90 days for critical issues

### Disclosure Policy

- We follow **responsible disclosure** principles
- We request a **90-day embargo** before public disclosure
- We will credit researchers (unless anonymity is requested)
- We may offer rewards for significant vulnerabilities

---

## Security Features

### Authentication & Access Control

- **Brute Force Protection**: Automatic account lockout after 5 failed attempts
- **Session Management**: Secure session handling with regeneration after login
- **Two-Factor Authentication**: TOTP-based 2FA support
- **Role-Based Access Control**: Admin and user roles with different privileges

### Data Protection

- **Environment-Based Configuration**: No hardcoded credentials
- **Encrypted Passwords**: Password hashing using PHP's `password_hash()`
- **Secure Session Cookies**: HttpOnly, Secure, and SameSite flags
- **CSRF Protection**: CSRF tokens on all state-changing operations

### Input Validation

- **SQL Injection Protection**: Prepared statements throughout
- **XSS Protection**: Input sanitization and output encoding
- **Command Injection Protection**: Proper escaping of shell commands
- **Path Traversal Protection**: Validated file paths

### Network Security

- **HTTPS Required**: SSL/TLS encryption for all communication
- **Secure Headers**: X-Frame-Options, CSP, X-Content-Type-Options
- **API Authentication**: Required authentication for API endpoints
- **Rate Limiting**: Protection against brute force and DoS attacks

### Monitoring & Logging

- **Audit Logging**: All administrative actions logged
- **Failed Login Tracking**: Security event logging
- **Snyk Integration**: Continuous vulnerability scanning
- **Health Monitoring**: System health and security status dashboard

---

## Security Best Practices

### For Administrators

1. **Keep Software Updated**
   ```bash
   # Check for updates regularly
   sudo ./update.sh --check
   ```

2. **Use Strong Passwords**
   - Minimum 12 characters
   - Mix of uppercase, lowercase, numbers, and symbols
   - Use a password manager

3. **Enable Two-Factor Authentication**
   - Navigate to User Settings → 2FA Setup
   - Use an authenticator app (Google Authenticator, Authy, etc.)

4. **Review Logs Regularly**
   - Check Administration → System Logs
   - Monitor failed login attempts
   - Review API access logs

5. **Limit Network Access**
   - Use firewall rules to restrict access
   - Consider VPN for remote access
   - Implement IP whitelisting if possible

6. **Regular Backups**
   - Configure automated backups
   - Test backup restoration regularly
   - Store backups securely off-site

7. **Security Scanning**
   - Run regular Snyk scans (Administration → Security Scanner)
   - Review and address vulnerabilities
   - Keep dependencies updated

### For Developers

1. **Follow Secure Coding Practices**
   - Always use prepared statements for SQL
   - Escape output with `htmlspecialchars()`
   - Use `escapeshellarg()` for shell commands
   - Validate all user input

2. **Authentication Required**
   ```php
   // Always require authentication on sensitive endpoints
   require_once 'inc/api_auth.php';
   requireApiAuth();  // Or requireApiAdmin()
   ```

3. **CSRF Protection**
   ```php
   // Add CSRF tokens to forms
   <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">

   // Verify on submission
   if (!csrf_verify($_POST['csrf'])) {
       die('CSRF validation failed');
   }
   ```

4. **Environment Variables**
   ```php
   // Never hardcode credentials
   $password = env('DB_PASS');  // Good
   $password = 'hardcoded123';  // Bad
   ```

5. **Security Testing**
   ```bash
   # Run before committing
   snyk test
   snyk code test
   ```

---

## Known Security Considerations

### Current Status

This software has undergone security review and implements industry-standard security practices. However, no software is completely secure.

### Areas of Focus

1. **Agent System**: The agent system executes commands on managed firewalls
   - Commands are queued in database
   - Consider implementing command whitelisting for additional security

2. **SSH Key Management**: SSH keys are used for firewall communication
   - Keys should be properly secured
   - Implement key rotation policies

3. **Database Access**: Database contains sensitive information
   - Restrict database network access
   - Use strong database passwords
   - Consider database encryption at rest

### Ongoing Improvements

We continuously improve security through:
- Regular dependency updates
- Security audits
- Penetration testing
- Community feedback
- Automated scanning (Snyk)

---

## Security Compliance

### Standards

OPNsense Manager follows security best practices from:
- **OWASP Top 10**: Protection against common web vulnerabilities
- **CWE Top 25**: Mitigation of dangerous software weaknesses
- **NIST Guidelines**: Security configuration recommendations

### Certifications

- Security scanned with Snyk
- Code analysis (SAST) performed
- Dependency vulnerability checking enabled

---

## Security Contact

- **Email**: security@yourdomain.com
- **PGP Key**: [Download PGP Key](https://yourdomain.com/pgp.asc)
- **Response Time**: 48 hours

---

## Hall of Fame

We recognize security researchers who responsibly disclose vulnerabilities:

- [Researcher Name] - [Vulnerability Description] - [Date]
- [Researcher Name] - [Vulnerability Description] - [Date]

---

## Additional Resources

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [CWE Top 25](https://cwe.mitre.org/top25/)
- [PHP Security Guide](https://www.php.net/manual/en/security.php)
- [Snyk Security](https://snyk.io/learn/)

---

## Updates

This security policy is reviewed and updated regularly. Last updated: February 2026

For the latest version, visit: [https://github.com/YOUR_USERNAME/opnsense-manager/blob/main/SECURITY.md](https://github.com/YOUR_USERNAME/opnsense-manager/blob/main/SECURITY.md)
