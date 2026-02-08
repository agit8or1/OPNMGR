# Dual-Agent System Implementation Summary

## ✅ Completed Components

### 1. Architecture Documentation
- **File**: `/var/www/opnsense/AGENT_ARCHITECTURE.md`
- **Purpose**: Complete explanation of why two agents are needed and how they work
- **Key Point**: NEVER forget this design - it prevents complete loss of remote management

### 2. Update Agent
- **File**: `/var/www/opnsense/opnsense_update_agent.sh`
- **Version**: 1.0.0
- **Purpose**: Failsafe agent that only handles updates and recovery
- **Features**:
  - Checks in every 5 minutes separately
  - Downloads and installs new primary agent
  - Restarts primary agent if it dies
  - Cannot be killed by commands (no shell execution)
  - Simple code to minimize risk

### 3. Server-Side Updates

#### agent_checkin.php
- Added `agent_type` parameter support ('primary' or 'update')
- Separate tracking for both agents in database
- `checkQueuedCommandsForUpdateAgent()` function - only processes commands with `is_update_command=1`
- Regular commands go to primary agent, update commands go to update agent

#### download_update_agent.php
- New endpoint to serve the update agent
- Similar to `download_tunnel_agent.php` but for update agent
- Substitutes firewall_id into template

#### agent_update_status.php
- Receives status reports from update agent
- Logs success/failure of primary agent updates
- Tracks update progress in system_logs

### 4. Database Schema
**firewall_agents table**:
- Added `agent_type` column (VARCHAR(20), default 'primary')
- Changed unique key to `(firewall_id, agent_type)` - allows both agents to coexist
- Now tracks primary and update agents separately

**firewall_commands table**:
- Added `is_update_command` column (TINYINT(1), default 0)
- When set to 1, only update agent will process it
- When set to 0, only primary agent will process it

## 🔄 How It Works

### Normal Operation
1. Both agents run simultaneously on firewall
2. Primary agent: Handles all normal commands and proxy requests
3. Update agent: Checks in, watches primary agent health, waits for update commands

### Update Scenario
1. Admin queues command with `is_update_command=1` flag
2. Update agent picks it up on next checkin (within 5 min)
3. Update agent downloads new primary agent from server
4. Update agent kills old primary agent processes
5. Update agent starts new primary agent
6. Update agent reports success/failure to server
7. Primary agent checks in with new version

### Recovery Scenario (Today's Problem)
1. Primary agent dies/breaks (like it did today)
2. Update agent detects primary is dead
3. Update agent automatically restarts primary from disk
4. If primary file is also broken, admin queues update command
5. Update agent downloads fresh primary agent
6. System recovered

## 📋 Deployment Steps for Firewall 21

Since the primary agent is currently dead, we need to:

1. Download update agent to firewall
2. Start update agent  
3. Queue update command for primary agent
4. Update agent will download and start new primary agent
5. Both agents will be running

## 🚨 Critical Reminders

1. **NEVER update the update agent via commands** - SSH only
2. **ALWAYS maintain both agents** - this is not optional
3. **Test both agents** after any major changes
4. **Update agent is simple** - keep it that way
5. **Document all changes** to AGENT_ARCHITECTURE.md

## 📁 File Locations

- `/var/www/opnsense/AGENT_ARCHITECTURE.md` - Architecture docs
- `/var/www/opnsense/opnsense_update_agent.sh` - Update agent template
- `/var/www/opnsense/download_update_agent.php` - Download endpoint
- `/var/www/opnsense/agent_update_status.php` - Status reporting endpoint
- `/var/www/opnsense/agent_checkin.php` - Updated for dual agents

On firewall:
- `/usr/local/bin/opnsense_agent.sh` - Primary agent
- `/usr/local/bin/opnsense_update_agent.sh` - Update agent
- `/var/run/opnsense_agent.pid` - Primary PID
- `/var/run/opnsense_update_agent.pid` - Update PID

## 🎯 Next Steps

1. Deploy update agent to firewall 21 (manual - since primary is dead)
2. Verify update agent checks in
3. Queue update command to install/restart primary agent
4. Verify both agents running and checking in
5. Test connection functionality
6. Add UI feature to manage agent updates
