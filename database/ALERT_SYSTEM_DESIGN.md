# Alert System Database Design

## Overview
The alert system allows OpnMgr to send notifications via Email and/or Pushover when important events occur. Users can be assigned to different alert levels (info, warning, critical) and choose their preferred notification method.

## Database Tables

### 1. `alert_settings`
Stores global configuration for the alert system.

**Columns:**
- `id`: Primary key
- `setting_name`: Unique setting identifier (e.g., 'email_enabled', 'pushover_api_token')
- `setting_value`: The value for the setting (stored as TEXT for flexibility)
- `created_at`: Timestamp when setting was created
- `updated_at`: Timestamp when setting was last modified

**Default Settings:**
- `email_enabled`: false
- `email_from_address`: (empty, must be configured)
- `email_from_name`: "OpnMgr Alert System"
- `pushover_enabled`: false
- `pushover_api_token`: (empty, must be configured)
- `alerts_info_enabled`: true
- `alerts_warning_enabled`: true
- `alerts_critical_enabled`: true

### 2. `alert_recipients`
Stores users/recipients who should receive alerts.

**Columns:**
- `id`: Primary key
- `name`: Recipient's name (e.g., "John Doe", "NOC Team")
- `email`: Email address (optional if using Pushover only)
- `pushover_user_key`: Pushover user/group key (optional if using Email only)
- `alert_level`: Which level of alerts this recipient receives
  - `info`: All informational messages (backups completed, agent check-ins, etc.)
  - `warning`: Important warnings (backup failures, delayed check-ins)
  - `critical`: Critical alerts (firewall offline, multiple failures)
- `notification_method`: How to notify this recipient
  - `email`: Send via email only
  - `pushover`: Send via Pushover only
  - `both`: Send via both email and Pushover
- `enabled`: Whether this recipient is active (allows temporary disable)
- `created_at`: When recipient was added
- `updated_at`: When recipient was last modified

**Indexes:**
- `alert_level`: Fast filtering by alert level
- `enabled`: Fast filtering of active recipients

### 3. `alert_history`
Logs all alerts sent for tracking, auditing, and troubleshooting.

**Columns:**
- `id`: Primary key
- `alert_level`: The level of this alert (info/warning/critical)
- `alert_type`: Type of event that triggered the alert
  - `firewall_offline`: Firewall went offline
  - `backup_failed`: Backup creation or upload failed
  - `agent_timeout`: Agent hasn't checked in within expected interval
  - `cert_expiring`: SSL certificate expiring soon
  - `config_changed`: Configuration change detected
  - (More types can be added as needed)
- `firewall_id`: Which firewall this alert relates to (NULL for system-wide alerts)
- `subject`: Alert subject line
- `message`: Full alert message body
- `recipients_count`: Number of recipients notified
- `notification_method`: Method(s) used (email/pushover/both)
- `sent_at`: When the alert was sent
- `status`: Whether alert was sent successfully
  - `sent`: All notifications sent successfully
  - `failed`: All notifications failed
  - `partial`: Some notifications succeeded, some failed
- `error_message`: Details if status is 'failed' or 'partial'

**Indexes:**
- `alert_level`: Query alerts by level
- `alert_type`: Query alerts by type
- `firewall_id`: Query alerts for specific firewall
- `sent_at`: Query alerts by time range

**Foreign Key:**
- `firewall_id` → `firewalls(id)` ON DELETE SET NULL

## Alert Levels

### Info (Level 1)
**Purpose:** Informational messages, routine operations
**Examples:**
- Backup completed successfully
- Agent checked in normally
- Configuration sync completed
- Scheduled maintenance tasks completed

**Pushover Priority:** 0 (normal)

### Warning (Level 2)
**Purpose:** Issues that need attention but aren't critical
**Examples:**
- Backup failed (will retry)
- Agent check-in delayed (but not timeout)
- Certificate expiring in 30 days
- High memory/CPU usage on firewall
- Failed command execution

**Pushover Priority:** 0 (normal, but could be 1 for high-priority)

### Critical (Level 3)
**Purpose:** Urgent issues requiring immediate action
**Examples:**
- Firewall went offline
- Multiple backup failures in a row
- Agent timeout (no check-in for extended period)
- Certificate expired or expiring within 7 days
- Security events detected

**Pushover Priority:** 1 (high-priority, bypasses quiet hours)

## Usage Examples

### Adding a Recipient
```sql
INSERT INTO alert_recipients (name, email, pushover_user_key, alert_level, notification_method, enabled)
VALUES ('John Admin', 'john@example.com', 'u12345abcdef', 'critical', 'both', 1);
```

### Querying Recipients for Critical Alerts
```php
$stmt = $DB->prepare("
    SELECT * FROM alert_recipients 
    WHERE alert_level = 'critical' 
    AND enabled = 1
");
$stmt->execute();
$recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

### Logging an Alert
```php
$stmt = $DB->prepare("
    INSERT INTO alert_history 
    (alert_level, alert_type, firewall_id, subject, message, recipients_count, notification_method, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute(['critical', 'firewall_offline', 21, 'Firewall Offline', 'home.agit8or.net is offline', 3, 'both', 'sent']);
```

### Updating Settings
```php
$stmt = $DB->prepare("UPDATE alert_settings SET setting_value = ? WHERE setting_name = ?");
$stmt->execute(['true', 'email_enabled']);
```

## Integration Points

The alert system will be integrated into:
1. **Firewall monitoring** (agent_checkin.php) - Detect offline firewalls
2. **Backup system** (create_backup.php, automatic_firewall_backups.sh) - Report failures
3. **Certificate monitoring** (TBD) - Expiration warnings
4. **Configuration changes** (firewall_details.php) - Track important changes

## Security Considerations

1. **API Keys:** Pushover API token and email credentials stored in database
2. **Access Control:** Only admin users can view/modify alert settings
3. **Rate Limiting:** Prevent alert spam (TBD: max alerts per hour)
4. **Sensitive Data:** Alert messages should not contain passwords or sensitive credentials
5. **Encryption:** Consider encrypting API keys in database (future enhancement)

## Future Enhancements

- [ ] SMS notifications via Twilio
- [ ] Slack/Discord webhook integration
- [ ] Alert deduplication (don't send same alert repeatedly)
- [ ] Alert acknowledgment system
- [ ] Scheduled quiet hours (suppress info/warning alerts at night)
- [ ] Alert templates with variables
- [ ] Webhook notifications for custom integrations
