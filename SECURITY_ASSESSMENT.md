# Security & Vulnerability Assessment
**OPNManager Version 2.2.3**  
**Assessment Date: 2025-10-21**  
**Last Updated: 2025-10-21 14:18:57**

---

## Executive Summary

OPNManager v2.2.3 implements a secure remote management system for OPNsense firewalls with end-to-end encryption, key-based authentication, and automatic session management. This document assesses current security posture and identifies potential vulnerabilities.

---

## 1. Authentication & Authorization

### ✅ Strengths
- **SSH Key Authentication**: All firewall connections use ED25519 keys (no passwords)
- **Session Isolation**: Custom session names (OPNMGR_SESSION) prevent cross-site cookie conflicts
- **24-Hour Session Lifetime**: Balances security with usability for tunnel-based workflows
- **Admin-Only Tunnel Access**: Tunnel creation restricted to admin users

### ⚠️ Concerns
- **No MFA**: Multi-factor authentication not implemented
- **Session Fixation**: Session regeneration only on initial login, not periodic
- **Shared Admin Access**: No granular RBAC (all admins have full access)

### 🔧 Mitigations in Place
- HTTPOnly and Secure cookie flags prevent XSS cookie theft
- SameSite=Lax reduces CSRF risk
- Session validation on every page load

---

## 2. Tunnel Security

### ✅ Strengths
- **Double Encryption**: HTTPS (nginx) → HTTP (SSH tunnel) provides layered encryption
- **Automatic Expiry**: Tunnels auto-expire after 30 minutes, cleaned up every 2 minutes
- **Process Isolation**: Each tunnel runs as separate SSH process with isolated nginx config
- **No Credential Storage**: Firewall credentials never stored, only SSH keys
- **Port Randomization**: Tunnels use random ports 8100-8200 to reduce enumeration

### ⚠️ Concerns
- **Cookie Isolation Disabled**: proxy_cookie_path/domain rewriting disabled due to auth issues
  - Risk: Firewall and OPNManager cookies share domain
  - Mitigation: Different session names (OPNMGR_SESSION vs PHPSESSID)
- **Zombie Tunnels**: Orphaned SSH processes can accumulate if cleanup fails
  - Mitigation: Automatic cleanup every 2 minutes, manual "Master Reset" available
- **No Rate Limiting**: Tunnel creation not rate-limited per user/IP
  - Risk: Resource exhaustion via rapid tunnel creation
  - Mitigation: Admin-only access, 30-minute expiry

### 🔧 Recommendations
1. Re-implement cookie isolation using subdomain approach (fw-*.opn.agit8or.net)
2. Add rate limiting: max 5 tunnels per user per 5 minutes
3. Implement tunnel usage logging/auditing
4. Add alert when zombie count exceeds threshold (e.g., >10)

---

## 3. Database Security

### ✅ Strengths
- **Prepared Statements**: All queries use PDO prepared statements (no SQL injection)
- **Password Hashing**: User passwords hashed with password_hash() (bcrypt)
- **Separate DB User**: OPNManager uses dedicated MySQL user with limited privileges

### ⚠️ Concerns
- **SSH Keys in Filesystem**: Private keys stored in /var/www/opnsense/keys/
  - Risk: Web server compromise could expose all firewall keys
  - Mitigation: Directory permissions 700, keys 600, owned by www-data
- **No Encryption at Rest**: Database and keys not encrypted on disk
- **API Keys in Database**: Pushover keys stored in plaintext

### 🔧 Recommendations
1. Move SSH keys to dedicated secure storage (e.g., /opt/opnmgr/secure/)
2. Implement secrets management (HashiCorp Vault, or encrypted config)
3. Enable MySQL encryption at rest
4. Rotate SSH keys periodically (implement key rotation script)

---

## 4. Web Application Security

### ✅ Strengths
- **HTTPS Enforced**: All traffic over TLS 1.2+
- **HSTS Headers**: Strict-Transport-Security implemented
- **Output Escaping**: HTML output properly escaped with htmlspecialchars()
- **CSRF Protection**: Implemented on all POST forms

### ⚠️ Concerns
- **No Content-Security-Policy**: CSP headers not implemented
  - Risk: XSS attacks possible if output escaping missed
- **No X-Frame-Options**: Clickjacking possible
- **Inline JavaScript**: Significant inline JS in pages (CSP bypass)
- **No Input Validation Framework**: Validation done ad-hoc per endpoint

### 🔧 Recommendations
1. Implement Content-Security-Policy headers
2. Add X-Frame-Options: DENY
3. Move inline JavaScript to external files
4. Create centralized input validation class
5. Add automated security scanning (OWASP ZAP)

---

## 5. Agent Security

### ✅ Strengths
- **Key-Based Checkin**: Agents use pre-shared keys for authentication
- **Rate Limiting**: Agent checkins limited to every 2 minutes
- **Read-Only Data**: Agents only send data, never receive commands directly

### ⚠️ Concerns
- **No Agent Certificate Validation**: Agents don't verify server TLS certificate
  - Risk: MITM attacks possible
  - Mitigation: Agents run on firewall (trusted network)
- **API Key in Plaintext**: Agent config.ini contains API key in cleartext
  - Risk: Firewall compromise exposes API key
  - Mitigation: Key only grants checkin permission, not admin access

### 🔧 Recommendations
1. Implement certificate pinning in agent
2. Encrypt agent config.ini with key derived from firewall serial
3. Add agent-side rate limiting (prevent abuse if compromised)
4. Implement agent key rotation

---

## 6. Command Execution Security

### ✅ Strengths
- **No Direct Shell**: Commands use prepared statement + PHP execution, not shell
- **SSH-Based**: All firewall commands execute via SSH (key-based, not shell injection)
- **Input Sanitization**: escapeshellarg() used on all shell parameters
- **Sudoers Whitelist**: www-data can only run specific commands via sudo

### ⚠️ Concerns
- **Broad Sudo Access**: Some sudo rules allow pattern-based commands
  - Risk: Command injection if validation fails
  - Example: `sudo kill -9 *` could be exploited
- **No Command Audit Log**: Executed commands not logged separately
- **Backup Restore**: Restore function executes SQL dump (potential injection)

### 🔧 Recommendations
1. Restrict sudo rules to exact commands (no wildcards)
2. Implement separate command audit log table
3. Add backup integrity checks (signature verification)
4. Limit SQL restore to specific safe operations

---

## 7. Network Security

### ✅ Strengths
- **Firewall Isolation**: Tunnels bind to 0.0.0.0 but nginx proxies only from localhost
- **No External Ports**: SSH tunnel ports not directly accessible from WAN
- **Agent IP Validation**: Checkins verify source IP matches registered firewall

### ⚠️ Concerns
- **No Geographic Restrictions**: No IP geofencing or country blocking
- **Unencrypted Internal Traffic**: SSH tunnel → firewall uses HTTP internally
  - Risk: LAN sniffing could intercept traffic
  - Mitigation: SSH tunnel itself is encrypted end-to-end

### 🔧 Recommendations
1. Add IP geofencing option (restrict to specific countries)
2. Implement fail2ban for brute force protection
3. Add IP whitelist feature for high-security deployments

---

## 8. Dependency & Supply Chain

### ✅ Strengths
- **Minimal Dependencies**: Core system uses PHP + MySQL only (no npm, composer)
- **Standard Libraries**: Uses built-in PHP functions where possible
- **No External APIs**: No third-party API dependencies for core functionality

### ⚠️ Concerns
- **Manual Dependency Management**: No automated vulnerability scanning
- **Ubuntu Package Updates**: Security depends on apt updates
- **No Integrity Checks**: Downloaded packages not verified

### 🔧 Recommendations
1. Implement automated security scanning (Dependabot, Snyk)
2. Add package integrity verification
3. Document minimum PHP/MySQL versions
4. Create update testing procedure

---

## 9. Deployment & Infrastructure

### ✅ Strengths
- **Single Server Architecture**: Simplified security boundary
- **No Cloud Dependencies**: Self-hosted, no AWS/GCP requirements
- **Automated Cleanup**: Cron jobs prevent resource exhaustion

### ⚠️ Concerns
- **No High Availability**: Single point of failure
- **No Backup Automation**: Manual backup required
- **Limited Monitoring**: No intrusion detection system

### 🔧 Recommendations
1. Implement automated daily backups
2. Add IDS/IPS (fail2ban, OSSEC)
3. Implement log aggregation (syslog → SIEM)
4. Create disaster recovery documentation

---

## 10. Compliance & Privacy

### ✅ Strengths
- **No PII Collection**: System doesn't collect personal identifying information
- **No External Telemetry**: No data sent to third parties
- **Local Logging**: All logs stored locally, not cloud

### ⚠️ Concerns
- **No Audit Trail**: User actions not comprehensively logged
- **No Data Retention Policy**: Logs/data never purged automatically
- **No Compliance Framework**: Not mapped to any standard (PCI, HIPAA, SOC2)

### 🔧 Recommendations
1. Implement comprehensive audit logging
2. Add configurable data retention (45-day purge implemented)
3. Create compliance mapping document
4. Add GDPR-style data export functionality

---

## Risk Matrix

| Risk Category | Severity | Likelihood | Priority | Mitigation Status |
|--------------|----------|------------|----------|-------------------|
| Cookie Isolation Disabled | **HIGH** | Medium | 🔴 HIGH | ⚠️  Workaround (session names) |
| No MFA | **HIGH** | Low | 🟡 MEDIUM | ❌ Not implemented |
| SSH Keys in Web Directory | **HIGH** | Low | 🟡 MEDIUM | ⚠️  Permissions set |
| No CSP Headers | **MEDIUM** | Medium | 🟡 MEDIUM | ❌ Not implemented |
| Broad Sudo Rules | **MEDIUM** | Low | 🟢 LOW | ⚠️  Restricted to www-data |
| No Rate Limiting (Tunnels) | **MEDIUM** | Low | 🟢 LOW | ⚠️  Admin-only |
| No Backup Automation | **LOW** | High | 🟡 MEDIUM | ❌ Not implemented |
| No IDS/IPS | **LOW** | Low | 🟢 LOW | ❌ Not implemented |

---

## Penetration Testing Recommendations

### High Priority Tests
1. **SQL Injection**: Test all input fields and API endpoints
2. **XSS**: Test output escaping in all user-facing displays
3. **CSRF**: Verify CSRF tokens on all state-changing operations
4. **Session Hijacking**: Test session cookie security
5. **Tunnel Enumeration**: Attempt to discover/access other user's tunnels

### Medium Priority Tests
1. **Command Injection**: Test command execution endpoints
2. **Path Traversal**: Test file upload/download functionality
3. **Privilege Escalation**: Test access controls between users
4. **Brute Force**: Test login rate limiting
5. **API Authentication**: Test API key validation

### Low Priority Tests
1. **Denial of Service**: Test resource exhaustion
2. **Information Disclosure**: Test error messages
3. **Clickjacking**: Test iframe embedding
4. **SSL/TLS**: Verify cipher strength

---

## Incident Response Plan

### Detection
- Monitor failed login attempts (>5/minute)
- Alert on zombie tunnel count >10
- Watch for unusual SSH key generation
- Track database query anomalies

### Response Procedure
1. **Identify**: Classify incident severity
2. **Contain**: Disable affected user/firewall
3. **Eradicate**: Remove malicious code/access
4. **Recover**: Restore from backup if needed
5. **Document**: Record incident details

### Contact Information
- System Administrator: [REDACTED]
- Security Team: [REDACTED]
- Vendor Contact: OPNsense Forums

---

## Version History

| Version | Date | Changes | Security Impact |
|---------|------|---------|-----------------|
| 2.2.3 | 2025-10-21 | Tunnel race condition fix, session isolation | ✅ Improved |
| 2.2.0 | 2024-10-13 | Direct nginx proxy system | ✅ Improved (removed PHP proxy) |
| 2.1.0 | 2024-09-15 | Initial production release | ⚠️  Initial baseline |

---

## Conclusion

OPNManager v2.2.3 provides a **secure baseline** for remote firewall management with strong encryption, key-based authentication, and automated security controls. The primary security concerns are:

1. **Cookie isolation disabled** (workaround in place)
2. **No MFA** (admin-only access provides some protection)
3. **SSH keys in web-accessible directory** (permissions restrict access)

**Overall Security Rating: B+ (Good)**

Recommended for production use in **trusted network environments** with regular security updates and monitoring. For high-security deployments, implement recommended mitigations before deployment.

---

*This assessment should be reviewed and updated with each major version release.*
