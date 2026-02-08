# OPNManager Agent Architecture Requirements
## CRITICAL DESIGN PRINCIPLES - READ BEFORE EVERY AGENT UPDATE

### Dual Independent Agent System

**MANDATORY**: The system MUST maintain TWO completely independent agents:

1. **Primary Agent** (`opnsense_agent_v2.sh`)
   - Purpose: Main management, command execution, monitoring
   - Checkin Interval: **120 seconds (every 2 minutes)**
   - Checkin Endpoint: `/agent_checkin.php`
   - Location: `/usr/local/bin/opnsense_agent_v2.sh`
   - Cron Schedule: `*/2 * * * *`
   - Agent Type: `primary`

2. **Update Agent** (`opnsense_update_agent.sh`)  
   - Purpose: Failsafe recovery, can update/repair primary agent
   - Checkin Interval: **300 seconds (every 5 minutes)**
   - Checkin Endpoint: `/updater_checkin.php`
   - Location: `/usr/local/bin/opnsense_update_agent.sh`
   - Service: `opnsense_update_agent` (rc.d service)
   - Agent Type: `update`

### Critical Rules

#### Independence
- Each agent MUST operate completely independently
- If one agent crashes/breaks, the other continues running
- Update agent can download and repair primary agent
- Primary agent should NOT be able to kill update agent
- Each agent has its own separate:
  - Process/daemon
  - Checkin endpoint  
  - Database table (`firewall_agents`)
  - Command queue system

#### Never Break These
1. **NEVER** set crontab to `*/5` in the primary agent script
2. **NEVER** make agents dependent on each other for checkins
3. **NEVER** use the same checkin endpoint for both agents
4. **NEVER** update the update agent via remote commands (SSH only)
5. **ALWAYS** maintain separate command queues (`firewall_commands` vs `updater_commands`)

#### Crontab Management
Primary agent scripts MUST NOT modify their own crontab. The crontab should be set once during deployment and never touched by the agent itself. If a script ends with:
```bash
echo "*/5 * * * * /usr/local/bin/opnsense_agent_v2.sh" | crontab -
```
**THIS IS A BUG** - Remove it immediately!

### Agent Dependencies

#### Required on Firewall (OPNsense/FreeBSD)
- `curl` or `fetch` - HTTP requests
- `base64` - Command encoding/decoding  
- Basic POSIX shell (`/bin/sh`)
- `jq` (recommended) OR pure shell JSON parsing
- **DO NOT** require `python3` unless verified available

#### Optional but Recommended
- `ssh` - For reverse tunnels
- `openssl` - For key generation
- `ps`, `kill`, `pkill` - Process management

### Database Schema

#### firewall_agents table
```sql
- id (primary key)
- firewall_id (foreign key)
- agent_type ENUM('primary', 'update')
- agent_version VARCHAR(20)
- status ENUM('online', 'offline', 'error')
- last_checkin TIMESTAMP
- last_command_at TIMESTAMP
```

#### firewall_commands table (for primary agent)
```sql
- id (primary key)
- firewall_id (foreign key)
- command TEXT
- description VARCHAR(255)
- status ENUM('pending', 'sent', 'completed', 'failed')
- result TEXT
- created_at TIMESTAMP
- completed_at TIMESTAMP
```

#### updater_commands table (for update agent)
```sql
- id (primary key)
- firewall_id (foreign key)  
- command_type ENUM('update_primary', 'restart_primary', 'health_check')
- command TEXT
- description VARCHAR(255)
- status ENUM('pending', 'sent', 'completed', 'failed')
- created_at TIMESTAMP
- completed_at TIMESTAMP
```

### Checkin Response Format

#### Primary Agent (/agent_checkin.php)
```json
{
  "success": true,
  "checkin_interval": 120,
  "queued_commands": [
    {"id": 123, "command": "base64encodedcommand"}
  ],
  "pending_requests": [
    {"id": 456, "tunnel_port": 8100, "method": "TUNNEL"}
  ],
  "opnsense_update_requested": true,
  "opnsense_update_command": "/usr/sbin/pkg update && /usr/sbin/pkg upgrade -y"
}
```

#### Update Agent (/updater_checkin.php)
```json
{
  "success": true,
  "checkin_interval": 300,
  "pending_commands": [
    {
      "id": 789,
      "command_type": "update_primary",
      "command": "curl -k -o /tmp/new_agent.sh https://... && cp /tmp/new_agent.sh /usr/local/bin/opnsense_agent_v2.sh"
    }
  ],
  "primary_agent_health": {
    "status": "offline",
    "last_seen": "2025-10-10 16:35:00",
    "minutes_ago": 20
  }
}
```

### Deployment Process

#### Initial Deployment
1. Deploy primary agent first via command queue
2. Verify primary agent is checking in every 2 minutes
3. Deploy update agent via primary agent command
4. Verify update agent starts as rc.d service
5. Verify update agent checks in every 5 minutes
6. Confirm both agents visible in `firewall_agents` table

#### Agent Updates
- **Primary Agent**: Can be updated remotely via update agent or command queue
- **Update Agent**: MUST be updated via manual SSH only (failsafe)

### Monitoring & Health Checks

#### What to Monitor
1. Primary agent checkin timing (should be ~120s intervals)
2. Update agent checkin timing (should be ~300s intervals)
3. Command execution success rate
4. Agent crash/restart frequency
5. Tunnel establishment success rate

#### Log Files
- Primary Agent: `/var/log/opnsense_agent.log`
- Update Agent: `/var/log/opnsense_update_agent.log`

### Common Mistakes to Avoid

1. ❌ Using `python3` without checking if installed
2. ❌ Agent modifying its own crontab schedule
3. ❌ Single agent doing everything (no redundancy)
4. ❌ Agents sharing the same checkin endpoint
5. ❌ Not handling base64 decode failures
6. ❌ Not setting proper execute permissions on deployed scripts
7. ❌ Forgetting to restart cron after crontab changes
8. ❌ Update agent that can be remotely killed/updated

### Testing Checklist

Before deploying any agent update:

- [ ] Verify syntax: `sh -n agent_script.sh`
- [ ] Check no python3 dependency: `grep -i python3 agent_script.sh`
- [ ] Confirm crontab is NOT modified by script
- [ ] Test on FreeBSD/OPNsense system if possible
- [ ] Verify both agents can run simultaneously
- [ ] Test failover: kill primary, verify update agent continues
- [ ] Test command execution via both agents
- [ ] Verify checkin timing with: `tail -f /var/log/nginx/access.log | grep checkin`

### Current Status (October 2025)

**Primary Agent**:
- Version: 3.2.0
- Status: ⚠️ BROKEN - Resets crontab to */5 every run
- Issue: Agent script contains `echo "*/5 * * * * /usr/local/bin/opnsense_agent_v2.sh" | crontab -` at end
- Fix Required: Remove crontab modification or change to */2

**Update Agent**:  
- Version: 1.1.0
- Status: ⚠️ DEPLOYED but checking in every 30 minutes (not 5 minutes)
- Issue: `CHECKIN_INTERVAL=1800` should be `300`
- Fix Required: Update to check in every 5 minutes

**Tunnel Support**:
- Status: ❌ NOT IMPLEMENTED in current agents
- Required: Agent v3.3.0+ with SSH reverse tunnel support
- Blocker: v3.3.0 requires python3 (not available on firewall)
- Fix Required: Create v3.3.1 with pure shell JSON parsing

---

**REMEMBER**: Every time you update an agent, review this document first!
