# OPNsense Agent Architecture

## Current: Single Agent Architecture

**This system uses a SINGLE agent architecture.**

### Primary Agent: `tunnel_agent.sh`
- **Purpose**: Full functionality (checkins, commands, proxy requests, diagnostics, speedtest, latency monitoring)
- **Update Method**: Can be updated via commands from management server
- **Location**: `/usr/local/bin/tunnel_agent.sh`
- **Checkin**: Reports to `agent_checkin.php` with `agent_type=primary`

## Architecture History

**Previous Dual-Agent System (Deprecated):**
The system previously used two agents (primary + update agent) to prevent the agent from killing itself during updates. This approach was deprecated in favor of safer update mechanisms.

**Current Single-Agent Approach:**
- Agent updates are handled more carefully to prevent self-termination issues
- Update commands use proper process management
- Simpler architecture with less complexity

## File Locations

- `/usr/local/bin/tunnel_agent.sh` - Primary agent
- Agent runs continuously via cron job every 2 minutes

## Installation

Install the primary agent:
```bash
curl -k https://opn.agit8or.net/download_tunnel_agent.php?firewall_id=X -o /usr/local/bin/tunnel_agent.sh
chmod +x /usr/local/bin/tunnel_agent.sh
echo "*/2 * * * * /usr/local/bin/tunnel_agent.sh" | crontab -
```

## Agent Features

- System monitoring (CPU, memory, disk, uptime)
- Network configuration reporting
- Command execution via queue system
- HTTP proxy for web UI access
- Speedtest and latency monitoring
- Automatic updates when requested
