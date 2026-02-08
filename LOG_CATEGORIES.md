# System Log Categories

## Standard Categories

All system logs should use these standardized lowercase categories:

### Core Categories
- **`agent`** - Agent-related events (checkins, updates, failures)
  - Agent checkins
  - Agent version updates
  - Agent errors and failures
  - Update agent activity

- **`proxy`** - Proxy request events
  - Proxy request initiated
  - Proxy request queued
  - Proxy timeout
  - Proxy failures

- **`command`** - Command execution events
  - Command sent to firewall
  - Command completed
  - Command failed
  - Command results

- **`firewall`** - Firewall-level events
  - Firewall added/removed
  - Firewall configuration changes
  - OPNsense version updates
  - Status changes (online/offline)

### System Categories
- **`backup`** - Backup operations
  - Backup created
  - Backup restored
  - Backup deleted
  - Backup failures

- **`auth`** - Authentication events
  - User login/logout
  - Failed login attempts
  - Session management
  - API key usage

- **`system`** - General system events
  - System settings changes
  - Maintenance operations
  - System errors

- **`dashboard`** - Dashboard-related events
  - Dashboard accessed
  - Widget updates
  - Dashboard errors

### Helper Categories
- **`updater`** - System updater events
- **`housekeeping`** - Cleanup and maintenance tasks
- **`alert`** - Alert system events

## Usage Guidelines

1. **Always use lowercase** - Never use "Agent", use "agent"
2. **No spaces** - Use underscores if needed (e.g., "system_management")
3. **Be consistent** - Use the same category for similar events
4. **Be specific** - Don't use generic categories when specific ones exist

## Examples

```php
// Good - Agent events
log_info('agent', 'Primary agent v2.4.0 started', null, $firewall_id);
log_error('agent', 'Update agent failed to download primary agent', null, $firewall_id);

// Good - Proxy events  
log_info('proxy', 'Proxy request queued successfully', $user_id, $firewall_id);
log_error('proxy', 'Proxy timeout after 60s', $user_id, $firewall_id);

// Good - Command events
log_info('command', 'Command completed: restart_services', null, $firewall_id);
log_error('command', 'Command failed: syntax error', null, $firewall_id);

// Bad - Inconsistent categories
log_info('Agent Checkin', 'Agent checked in');  // ❌ Wrong - capitalized
log_info('system update', 'System updated');     // ❌ Wrong - space instead of underscore
log_info('commands', 'Command sent');            // ❌ Wrong - should be singular 'command'
```

## Migration

Existing logs with old category names have been normalized:
- "Agent Checkin" → "agent_checkin" → should be "agent"
- "Agent Update" → "agent_update" → should be "agent"
- "System Update" → "system_update" → OK
- "System Management" → "system_management" → should be "system"

## Category Consolidation

Some categories should be consolidated:
- "agent_checkin", "agent_update" → "agent"
- "system_update", "system_management" → "system" or keep separate if needed

## Filter Display Names

In the UI, categories can be displayed with friendly names:
- `agent` → "Agent Events"
- `proxy` → "Proxy Requests"
- `command` → "Commands"
- `firewall` → "Firewall Events"
- `backup` → "Backups"
- `auth` → "Authentication"
- `system` → "System"
