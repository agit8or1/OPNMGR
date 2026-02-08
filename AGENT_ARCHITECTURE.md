# OPNsense Agent Architecture

## CRITICAL: Dual-Agent System

**This system MUST maintain TWO separate agents running simultaneously on each firewall.**

### Primary Agent: `opnsense_agent.sh`
- **Purpose**: Full functionality (checkins, commands, proxy requests, diagnostics)
- **Update Method**: Can be updated via commands from management server
- **Risk**: If broken, firewall loses all remote management capability

### Update Agent: `opnsense_update_agent.sh` 
- **Purpose**: Emergency recovery and updates ONLY
- **Functionality**: 
  - Checks in separately (different endpoint or flag)
  - ONLY processes update/download commands
  - Cannot be killed by primary agent commands
  - Minimal code to reduce risk of breaking
- **Risk**: Low - simple code that rarely changes

## Why Two Agents?

**Problem Scenario (October 5, 2025):**
- Primary agent was v2.3.0, needed update to v2.4.0
- Attempted to kill old agent processes and restart with new version
- Command killed the agent while it was executing the kill command
- Result: No agent running, no way to recover remotely (chicken-and-egg)

**Solution:**
- Update agent continues running even if primary agent dies
- Update agent can re-download and restart primary agent
- Update agent itself is NEVER updated via commands (manual only)

## Update Agent Design Rules

1. **Simplicity**: Minimal code, fewer things to break
2. **Independence**: Separate process, separate PID file
3. **Limited Scope**: Only handles update commands, ignores everything else
4. **Self-Protection**: Cannot kill itself via commands
5. **Failsafe**: If primary agent is dead, update agent can revive it

## File Locations

- `/usr/local/bin/opnsense_agent.sh` - Primary agent
- `/usr/local/bin/opnsense_update_agent.sh` - Update/recovery agent
- `/var/run/opnsense_agent.pid` - Primary agent PID
- `/var/run/opnsense_update_agent.pid` - Update agent PID

## Checkin Pattern

Both agents check in to `agent_checkin.php` but are distinguished by:
- Primary: `agent_type=primary` in checkin
- Update: `agent_type=update` in checkin

## Command Processing

- Primary agent: Processes ALL command types
- Update agent: ONLY processes commands with `is_update_command=1` flag

## Installation

Both agents must be installed together:
```bash
curl -k https://opn.agit8or.net/download_tunnel_agent.php?firewall_id=X -o /usr/local/bin/opnsense_agent.sh
curl -k https://opn.agit8or.net/download_update_agent.php?firewall_id=X -o /usr/local/bin/opnsense_update_agent.sh
chmod +x /usr/local/bin/opnsense_agent.sh
chmod +x /usr/local/bin/opnsense_update_agent.sh
nohup /usr/local/bin/opnsense_agent.sh &
nohup /usr/local/bin/opnsense_update_agent.sh &
```

## Emergency Recovery

If primary agent is dead:
1. Update agent still checks in
2. Queue update command with `is_update_command=1`
3. Update agent downloads new primary agent
4. Update agent starts new primary agent process
5. System recovered

## NEVER FORGET THIS DESIGN!

**This dual-agent system is NOT optional.** It's a critical failsafe that prevents complete loss of remote management when the primary agent breaks or dies during an update.

Last incident: October 5, 2025 - Agent killed itself during restart command
