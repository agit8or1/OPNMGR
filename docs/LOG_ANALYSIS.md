# AI Log Analysis Feature

## Overview
The AI Log Analysis feature extends the existing AI configuration scanning to include firewall log examination. This provides comprehensive security analysis by correlating configuration settings with actual traffic patterns and security events.

## Components

### 1. Log Fetching (`scripts/fetch_logs.php`)
- Fetches logs from firewalls via SSH
- Supports multiple log types: filter, dhcp, system, auth, vpn, squid, suricata
- Compresses logs using gzip for storage efficiency
- Can be run manually or as part of AI scan

### 2. AI Scan API Enhancement (`api/ai_scan.php`)
- Extended to support `scan_type='config_with_logs'`
- Fetches relevant logs when log analysis is requested
- Builds comprehensive prompts including log data
- Limits log samples to 500 lines per type to prevent token overflow

### 3. Database Schema
New tables for tracking log analysis:
- `log_analysis_results` - Stores analysis results per log type
- `detected_threats` - Tracks specific threats found in logs
- `ip_reputation` - Maintains IP reputation scores
- `log_processing_jobs` - Manages log processing job queue

### 4. Log Analysis Dashboard (`log_analysis.php`)
- View all log analyses with filtering
- Statistics dashboard: total analyses, threats, blocks, failed auth
- Threat level distribution visualization
- Filter by firewall, threat level, date range
- Direct links to full AI reports and threat details

## Usage

### Manual Scan with Logs
1. Navigate to a firewall detail page
2. Click "AI Scan" widget
3. Select scan type: "Config with Logs"
4. Choose AI provider
5. Click "Run Scan"

### Viewing Results
- Main menu → Log Analysis
- Filter by firewall, threat level, or date range
- Click "View Report" for full AI analysis
- Click "Threats" for detailed threat information

## Log Types Analyzed

| Log Type | File Path | Purpose |
|----------|-----------|---------|
| filter | /var/log/filter.log | Firewall rule matches, blocks |
| dhcp | /var/log/dhcpd.log | DHCP assignments, issues |
| system | /var/log/system.log | System events, errors |
| auth | /var/log/auth.log | Authentication attempts |
| vpn | /var/log/openvpn.log | VPN connections, errors |
| squid | /var/log/squid/access.log | Proxy traffic (if enabled) |
| suricata | /var/log/suricata/suricata.log | IDS/IPS alerts (if enabled) |

## AI Analysis Focus

When analyzing logs, AI providers are instructed to identify:

1. **Active Security Threats**
   - Brute force attacks
   - Port scanning attempts
   - DDoS indicators
   - Exploit attempts

2. **Suspicious Activity**
   - Unusual traffic patterns
   - Geographic anomalies
   - Protocol violations
   - Data exfiltration indicators

3. **Authentication Issues**
   - Failed login attempts
   - Account lockouts
   - Privilege escalation attempts

4. **Network Anomalies**
   - Unexpected connections
   - Bandwidth spikes
   - Service outages
   - Configuration changes

## API Response Structure

```json
{
  "grade": "B+",
  "score": 85,
  "risk_level": "medium",
  "threat_level": "medium",
  "summary": "Overall security posture is good...",
  "active_threats": [
    {
      "type": "brute_force",
      "source_ip": "192.0.2.100",
      "severity": "high",
      "description": "Multiple failed SSH attempts"
    }
  ],
  "suspicious_ips": ["192.0.2.100", "198.51.100.50"],
  "blocked_attempts": 1250,
  "failed_auth_attempts": 45,
  "anomaly_score": 35,
  "findings": [
    {
      "category": "Log Analysis",
      "severity": "high",
      "title": "SSH Brute Force Detected",
      "description": "IP 192.0.2.100 made 45 failed login attempts",
      "recommendation": "Add IP to blocklist, enable fail2ban"
    }
  ]
}
```

## Performance Considerations

- **Log Size Limiting**: Only last 500 lines per log type sent to AI
- **Compression**: Logs stored compressed (gzip) to save disk space
- **Timeout**: 120 second timeout for AI API calls
- **Token Management**: Prompt construction limits total size to prevent API errors

## Security

- SSH keys required for log access
- Logs stored in protected directory (`/var/www/opnsense/logs/fetched`)
- API keys encrypted at rest in database
- Log data included in scan reports for audit trail

## Future Enhancements

1. **Real-time Monitoring**
   - Webhook triggers for critical threats
   - Email alerts for high/critical findings
   - Slack/Teams integration

2. **Historical Trending**
   - Track threat patterns over time
   - Anomaly detection using ML
   - Predictive threat intelligence

3. **Automated Response**
   - Auto-block suspicious IPs
   - Dynamic rule creation
   - Ticket creation for SOC teams

4. **GeoIP Integration**
   - Map threat sources geographically
   - Country-based blocking rules
   - Traffic visualization

## Troubleshooting

### Logs Not Fetching
- Verify SSH key exists in `/var/www/opnsense/keys/`
- Check firewall SSH access and permissions
- Ensure log files exist on firewall
- Review PHP error logs for SSH connection issues

### AI Analysis Fails
- Check AI provider API key in settings
- Verify provider is active
- Review token limits (logs can be large)
- Check API quotas and rate limits

### No Results in Dashboard
- Ensure scans are run with `config_with_logs` option
- Check database for log_analysis_results entries
- Verify scan completed successfully
- Review scan reports for errors

## Command Line Tools

### Fetch Logs Manually
```bash
php /var/www/opnsense/scripts/fetch_logs.php <firewall_id>
```

### View Log Processing Jobs
```sql
SELECT * FROM log_processing_jobs 
WHERE status = 'failed' 
ORDER BY created_at DESC;
```

### Check Threat Detection
```sql
SELECT dt.*, lar.log_type 
FROM detected_threats dt
JOIN log_analysis_results lar ON dt.log_analysis_id = lar.id
WHERE severity IN ('high', 'critical')
ORDER BY dt.last_seen DESC;
```
