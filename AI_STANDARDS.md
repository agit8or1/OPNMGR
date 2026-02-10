# AI Feature Standards & Best Practices
**Version**: 1.0  
**Last Updated**: October 23, 2025  
**Applies To**: OPNManager v2.2.0+

---

## 📋 Table of Contents
1. [Overview](#overview)
2. [AI Provider Integration Standards](#ai-provider-integration-standards)
3. [Configuration Scan Standards](#configuration-scan-standards)
4. [Log Analysis Standards](#log-analysis-standards)
5. [Report Generation Standards](#report-generation-standards)
6. [Security & Privacy](#security--privacy)
7. [Performance Guidelines](#performance-guidelines)
8. [Error Handling](#error-handling)
9. [Testing Requirements](#testing-requirements)

---

## Overview

This document defines the standards and best practices for implementing and using AI-powered features in OPNManager. All AI features must adhere to these guidelines to ensure consistent quality, security, and user experience.

### Core Principles
1. **Privacy First**: Never send customer PII or sensitive credentials to AI providers
2. **Provider Agnostic**: Support multiple AI providers with consistent APIs
3. **Graceful Degradation**: System must function if AI features are unavailable
4. **Transparent Reporting**: Users must understand what AI analyzed and why
5. **Actionable Insights**: Every AI finding must include clear remediation steps

---

## AI Provider Integration Standards

### Supported Providers

#### OpenAI (GPT Models)
- **Models**: gpt-4, gpt-4-turbo, gpt-3.5-turbo
- **Recommended**: gpt-4-turbo for production
- **API Endpoint**: https://api.openai.com/v1/chat/completions
- **Rate Limits**: Monitor usage, implement exponential backoff
- **Cost Considerations**: GPT-4 is more expensive, use GPT-3.5 for basic scans

```php
// OpenAI Request Structure
$data = [
    'model' => 'gpt-4-turbo',
    'messages' => [
        ['role' => 'system', 'content' => $system_prompt],
        ['role' => 'user', 'content' => $user_prompt]
    ],
    'temperature' => 0.3,  // Lower for more consistent technical analysis
    'max_tokens' => 4096
];
```

#### Anthropic (Claude Models)
- **Models**: claude-3-5-sonnet-20241022, claude-3-opus, claude-3-sonnet
- **Recommended**: claude-3-5-sonnet for best balance
- **API Endpoint**: https://api.anthropic.com/v1/messages
- **Rate Limits**: 50 requests/minute (tier dependent)
- **Advantages**: Longer context windows, better at following structured output

```php
// Anthropic Request Structure
$data = [
    'model' => 'claude-3-5-sonnet-20241022',
    'max_tokens' => 4096,
    'temperature' => 0.3,
    'system' => $system_prompt,
    'messages' => [
        ['role' => 'user', 'content' => $user_prompt]
    ]
];
```

#### Google Gemini
- **Models**: gemini-pro, gemini-ultra
- **Recommended**: gemini-pro for cost-effectiveness
- **API Endpoint**: https://generativelanguage.googleapis.com/v1/models/
- **Rate Limits**: 60 queries/minute
- **Advantages**: Good multilingual support, fast responses

```php
// Gemini Request Structure
$data = [
    'contents' => [
        ['role' => 'user', 'parts' => [['text' => $combined_prompt]]]
    ],
    'generationConfig' => [
        'temperature' => 0.3,
        'maxOutputTokens' => 4096
    ]
];
```

#### Ollama (Local/Self-Hosted)
- **Models**: llama2, mistral, codellama, custom models
- **Recommended**: mistral for firewall analysis
- **API Endpoint**: http://localhost:11434/api/generate (configurable)
- **Advantages**: Privacy, no API costs, full control
- **Requirements**: GPU recommended, 16GB+ RAM

```php
// Ollama Request Structure
$data = [
    'model' => 'mistral',
    'prompt' => $combined_prompt,
    'stream' => false,
    'options' => [
        'temperature' => 0.3,
        'num_predict' => 4096
    ]
];
```

### Provider Configuration Storage

API keys **MUST** be encrypted at rest:

```sql
CREATE TABLE ai_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(50) NOT NULL,
    api_key VARCHAR(255) NOT NULL,  -- ENCRYPTED
    model VARCHAR(100),
    is_active TINYINT(1) DEFAULT 1,
    endpoint_url VARCHAR(255),       -- For custom Ollama instances
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY (provider)
);
```

**Encryption Method**: AES-256-CBC with application-level encryption key

```php
// Encryption example
function encryptApiKey($key) {
    $encryption_key = getenv('AI_ENCRYPTION_KEY'); // Store in .env
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($key, 'aes-256-cbc', $encryption_key, 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}
```

---

## Configuration Scan Standards

### Scan Input Preparation

**MUST sanitize configuration before sending to AI:**

1. **Remove Sensitive Data**:
   - API keys and tokens
   - Passwords (even if hashed)
   - SSH keys
   - SNMP community strings
   - VPN pre-shared keys
   - Certificate private keys

2. **Redact Personal Information**:
   - Email addresses (replace with `<EMAIL_REDACTED>`)
   - Phone numbers
   - Physical addresses
   - Employee names

3. **Preserve Security-Relevant Data**:
   - Firewall rules (source/dest IPs OK, but mask internal server names)
   - NAT configurations
   - Service configurations
   - System settings

**Example Sanitization**:

```php
function sanitizeConfig($config_xml) {
    $xml = simplexml_load_string($config_xml);
    
    // Remove sensitive elements
    unset($xml->system->user->password);
    unset($xml->cert->prv);
    unset($xml->openvpn->shared_key);
    
    // Redact emails
    $config_str = $xml->asXML();
    $config_str = preg_replace('/[\w\-\.]+@[\w\-\.]+\.\w+/', '<EMAIL_REDACTED>', $config_str);
    
    return $config_str;
}
```

### System Prompt Structure

**Standard System Prompt Template**:

```
You are a cybersecurity expert specializing in OPNsense firewall configurations.
Analyze the provided firewall configuration and identify:

1. Security vulnerabilities
2. Misconfigurations
3. Rule optimization opportunities
4. Compliance gaps
5. Best practice violations

For each finding:
- Severity: critical/high/medium/low
- Category: security/performance/compliance/best-practice
- Specific impact
- Remediation steps

Provide your analysis in JSON format:
{
  "overall_grade": "A|B|C|D|F",
  "security_score": 0-100,
  "risk_level": "low|medium|high|critical",
  "summary": "Brief executive summary (2-3 sentences)",
  "findings": [
    {
      "severity": "high",
      "category": "security",
      "title": "Finding title",
      "description": "Detailed description",
      "impact": "What could go wrong",
      "recommendation": "How to fix",
      "affected_area": "Specific config section"
    }
  ],
  "strengths": ["What's done well"],
  "critical_concerns": ["Most urgent issues"],
  "recommendations": ["Top 3-5 actions to take"]
}
```

### Scan Types

#### 1. Config-Only Scan (Fast)
- **Duration**: 15-45 seconds
- **Input**: Sanitized firewall configuration XML
- **Focus**: Rules, NAT, services, system settings
- **Use Case**: Quick security audit

#### 2. Config + Logs Scan (Deep)
- **Duration**: 60-180 seconds
- **Input**: Configuration + last 1000-5000 log lines
- **Focus**: Active threats, attack patterns, suspicious activity
- **Use Case**: Incident investigation, comprehensive audit

**Log Preparation**:

```bash
# On firewall - get filtered logs
tail -n 5000 /var/log/filter.log | \
  grep -v "192.168.1." | \      # Remove internal noise
  grep -v "broadcast" | \
  tail -n 1000 > /tmp/logs_for_ai.txt
```

### Grading Scale

| Grade | Score Range | Description |
|-------|-------------|-------------|
| A+ | 95-100 | Exceptional security posture |
| A | 90-94 | Excellent, minor improvements needed |
| B | 80-89 | Good, some vulnerabilities present |
| C | 70-79 | Adequate, notable security gaps |
| D | 60-69 | Poor, significant risks |
| F | 0-59 | Critical security failures |

### Risk Level Classification

- **Critical**: Actively exploitable vulnerabilities, data at immediate risk
- **High**: Significant weaknesses, potential for breach
- **Medium**: Misconfigurations that reduce security effectiveness
- **Low**: Best practice violations with minimal immediate risk

---

## Log Analysis Standards

### Log Collection

**Requirements**:
1. Collect logs via SSH (never store permanently on manager)
2. Limit collection to relevant time windows (last 24h, last week)
3. Filter out noise (internal broadcasts, routine traffic)
4. Anonymize private IPs before AI analysis

**Collection Script** (on firewall):

```bash
#!/bin/sh
# collect_logs_for_ai.sh

LOG_LINES=5000
OUTPUT_FILE="/tmp/ai_analysis_logs.txt"

# Get firewall logs
tail -n $LOG_LINES /var/log/filter.log | \
  grep -E '(block|DROP|DENY)' | \     # Blocked traffic only
  grep -v 'broadcast' | \
  grep -v 'ff02::' | \                # IPv6 multicast
  awk '{$1=$2=$3=""; print $0}' \     # Remove timestamps (adds noise)
  > "$OUTPUT_FILE"

# Add system logs if needed
tail -n 1000 /var/log/system.log | \
  grep -iE '(error|critical|failed|denied)' \
  >> "$OUTPUT_FILE"

echo "$OUTPUT_FILE"
```

### Log Analysis Prompt

```
Analyze these firewall logs for security threats and suspicious activity:

[LOGS]

Identify:
1. Attack patterns (port scans, brute force, DDoS)
2. Suspicious source IPs
3. Unusual traffic patterns
4. Potential compromises
5. Policy violations

For each threat found:
- Threat type
- Severity (critical/high/medium/low)
- Source IP/port
- Target IP/port  
- Attack pattern description
- Recommended action (block, investigate, monitor)
- Confidence level (high/medium/low)

Output JSON format:
{
  "summary": "Executive summary",
  "threats_detected": 5,
  "severity_breakdown": {"critical": 1, "high": 2, "medium": 2},
  "findings": [...]
}
```

### GeoIP Integration

For IP addresses in findings, include:

```php
// GeoIP lookup
$geoip = geoip_record_by_name($ip);
$finding['source_location'] = [
    'country' => $geoip['country_name'],
    'country_code' => $geoip['country_code'],
    'city' => $geoip['city'],
    'latitude' => $geoip['latitude'],
    'longitude' => $geoip['longitude']
];
```

---

## Report Generation Standards

### Report Structure

All AI reports **MUST** include:

1. **Metadata**
   - Scan timestamp
   - Firewall name/ID
   - Scan type (config/logs/both)
   - AI provider and model used
   - Scan duration

2. **Executive Summary**
   - 2-3 sentence overview
   - Overall security posture
   - Most critical finding

3. **Scoring & Grading**
   - Overall grade (A-F)
   - Security score (0-100)
   - Risk level classification

4. **Findings**
   - Severity-sorted list
   - Each with: title, description, impact, recommendation, affected area
   - Minimum 3, maximum 50 findings

5. **Recommendations**
   - Top 3-5 action items
   - Prioritized by impact
   - Clear, actionable steps

6. **Strengths** (optional)
   - What's configured well
   - Security best practices followed

### Database Schema

```sql
CREATE TABLE ai_scan_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    firewall_id INT NOT NULL,
    config_snapshot_id INT,
    scan_type ENUM('config_only', 'config_with_logs') DEFAULT 'config_only',
    provider VARCHAR(50) NOT NULL,
    model VARCHAR(100),
    overall_grade VARCHAR(5),
    security_score INT,
    risk_level ENUM('low', 'medium', 'high', 'critical'),
    summary TEXT,
    recommendations TEXT,
    concerns TEXT,
    improvements TEXT,
    full_report LONGTEXT,  -- JSON
    scan_duration INT,     -- seconds
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_firewall (firewall_id),
    INDEX idx_created (created_at),
    FOREIGN KEY (firewall_id) REFERENCES firewalls(id) ON DELETE CASCADE
);

CREATE TABLE ai_scan_findings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    category VARCHAR(100),
    severity ENUM('low', 'medium', 'high', 'critical'),
    title VARCHAR(255),
    description TEXT,
    impact TEXT,
    recommendation TEXT,
    affected_area VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (report_id) REFERENCES ai_scan_reports(id) ON DELETE CASCADE,
    INDEX idx_report (report_id),
    INDEX idx_severity (severity)
);
```

### Report Retention

- Keep last 50 reports per firewall
- Purge reports older than 90 days (configurable)
- Store full report JSON for re-analysis capability

---

## Security & Privacy

### Data Handling Rules

1. **NEVER send to AI**:
   - Passwords or password hashes
   - API keys or tokens
   - Private keys
   - Customer names or contact info
   - Credit card or payment data
   - Social security numbers

2. **Sanitize before sending**:
   - Internal hostnames → `<HOST_REDACTED>`
   - Email addresses → `<EMAIL_REDACTED>`
   - Phone numbers → `<PHONE_REDACTED>`

3. **Anonymize IPs**:
   - Internal IPs (RFC1918) → Keep for context
   - Public IPs → Keep for threat analysis
   - Customer management IPs → Redact

### API Key Security

**Storage**:
- Encrypted at rest (AES-256)
- Never logged
- Never displayed in UI (show only last 4 chars)
- Rotate regularly

**Access Control**:
- Only admin users can configure AI settings
- Audit log all AI setting changes
- Rate limit AI scan requests

### Compliance

- **GDPR**: Ensure no PII sent to AI providers
- **HIPAA**: Document data sanitization for healthcare clients
- **SOC 2**: Audit trail of all AI operations
- **ISO 27001**: Secure key management practices

---

## Performance Guidelines

### Rate Limiting

```php
// Per-firewall scan limits
const MAX_SCANS_PER_HOUR = 5;
const MAX_SCANS_PER_DAY = 20;

function canScanFirewall($firewall_id) {
    $stmt = $DB->prepare("
        SELECT COUNT(*) FROM ai_scan_reports 
        WHERE firewall_id = ? 
        AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute([$firewall_id]);
    $recent_scans = $stmt->fetchColumn();
    
    return $recent_scans < MAX_SCANS_PER_HOUR;
}
```

### Response Timeouts

- **Config-only scan**: 60 second timeout
- **Config + logs scan**: 180 second timeout
- Exponential backoff on API errors
- Queue scans if provider unavailable

### Caching

- Cache provider availability checks (5 minutes)
- Cache model lists (1 hour)
- Don't cache scan results (always fresh analysis)

---

## Error Handling

### Standard Error Responses

```json
{
  "success": false,
  "error": {
    "code": "PROVIDER_UNAVAILABLE",
    "message": "OpenAI API is currently unavailable",
    "user_message": "AI scanning is temporarily unavailable. Please try again in a few minutes.",
    "retry_after": 300
  }
}
```

### Error Codes

- `PROVIDER_UNAVAILABLE`: AI service down
- `INVALID_API_KEY`: Authentication failed
- `RATE_LIMIT_EXCEEDED`: Too many requests
- `TIMEOUT`: Scan took too long
- `SANITIZATION_FAILED`: Couldn't clean config safely
- `INVALID_RESPONSE`: AI returned malformed data
- `NO_ACTIVE_PROVIDER`: No AI provider configured

### Fallback Behavior

If AI scan fails:
1. Log detailed error for debugging
2. Show user-friendly error message
3. Offer manual analysis option
4. Don't fail silently

---

## Testing Requirements

### Unit Tests

Each AI feature must have:

```php
// Test sanitization
testConfigSanitization() {
    $config = loadTestConfig('with_secrets.xml');
    $clean = sanitizeConfig($config);
    
    assertNotContains($clean, 'password');
    assertNotContains($clean, 'api_key');
    assertNotContains($clean, '@example.com');
}

// Test provider failover
testProviderFailover() {
    disableProvider('openai');
    $result = scanFirewallConfig($firewall_id);
    
    assertEquals('anthropic', $result['provider_used']);
    assertTrue($result['success']);
}

// Test rate limiting
testRateLimiting() {
    for ($i = 0; $i < 6; $i++) {
        $result = scanFirewallConfig($firewall_id);
    }
    
    assertFalse($result['success']);
    assertEquals('RATE_LIMIT_EXCEEDED', $result['error']['code']);
}
```

### Integration Tests

- Test actual API calls to each provider
- Verify report structure matches schema
- Test error handling with invalid configs
- Test timeout behavior

### Security Tests

- Verify no secrets leak to logs
- Test encryption/decryption of API keys
- Verify access controls
- Test input sanitization bypasses

---

## Monitoring & Metrics

Track these metrics:

```sql
-- AI usage metrics
SELECT 
    DATE(created_at) as date,
    provider,
    COUNT(*) as scans,
    AVG(scan_duration) as avg_duration,
    SUM(CASE WHEN risk_level = 'critical' THEN 1 ELSE 0 END) as critical_findings
FROM ai_scan_reports
WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(created_at), provider;
```

---

## Changelog

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | Oct 23, 2025 | Initial standards document |

---

**Document Owner**: OPNManager Development Team  
**Review Cycle**: Quarterly  
**Next Review**: January 2026
