# OPNsense Agent Timing Rules

## Overview
The OPNsense management system uses multiple agent types with different check-in frequencies to balance responsiveness with resource usage.

## Agent Types and Schedules

### 1. Quick Agent (Command Checker)
- **Frequency**: Every 15 seconds
- **Purpose**: Rapidly detect and execute pending commands
- **Behavior**:
  - Checks for queued commands in `firewall_commands` table
  - If commands found, triggers Main Agent to execute them
  - Minimal overhead - only checks for command presence
  - Does NOT perform full system checkin
- **Implementation**: Cron job `*/15 * * * * /usr/local/bin/quick_agent.sh`

### 2. Main Agent (Primary Checkin)
- **Frequency**: Every 2 minutes (120 seconds)
- **Purpose**: Full system status reporting and command execution
- **Sends**:
  - Firewall ID and hardware ID
  - Agent version
  - Hostname
  - WAN IP, LAN IP, IPv6 address
  - System uptime
  - OPNsense version
  - Web server port (detected via sockstat)
  - WAN interface traffic statistics (NEW in v3.5.0)
  - Network configuration (netmask, gateway, DNS)
- **Behavior**:
  - Executes any pending commands
  - Reports command results back to manager
  - Can be triggered early by Quick Agent if commands are queued
- **Implementation**: Cron job `*/2 * * * * /usr/local/bin/opnsense_agent_v2.sh`

### 3. Update Check Agent
- **Frequency**: Every 5 minutes (300 seconds)
- **Purpose**: Check for available OPNsense updates
- **Sends**:
  - Current OPNsense version
  - Available updates
  - Update status
- **Behavior**:
  - Queries OPNsense update system
  - Reports available package updates
  - Does not automatically install updates
- **Implementation**: Cron job `*/5 * * * * /usr/local/bin/opnsense_update_agent.sh`

## Command Queue Behavior

### Priority Levels
Commands are executed in order of submission (FIFO), with these exceptions:
1. **Critical commands** (reboot, shutdown) - Always executed last
2. **Update commands** - Queued for Update Agent
3. **Standard commands** - Executed by Main Agent in order

### Command Execution Flow
```
1. Command queued in firewall_commands table (status='pending')
2. Quick Agent detects pending command (15s cycle)
3. Quick Agent triggers Main Agent immediately
4. Main Agent executes command
5. Main Agent reports result (success/error/timeout)
6. Command status updated to 'completed' or 'failed'
```

### Timeouts
- **Command execution timeout**: 30 seconds (default)
- **Long-running commands**: 300 seconds (5 minutes)
- **Network commands**: 60 seconds

## Traffic Statistics Collection (NEW in v3.5.0)

### Collection Method
- Main Agent collects WAN interface traffic stats on each checkin
- Uses `netstat -ibn` to get interface byte counters
- Calculates delta since last checkin
- Sends incremental statistics to manager

### Data Points
For each WAN interface:
- Interface name (e.g., igb0, em0)
- Bytes received (inbound)
- Bytes transmitted (outbound)
- Packets received
- Packets transmitted
- Timestamp of collection

### Storage
- Stored in `firewall_traffic_stats` table
- Retained for 90 days (configurable)
- Aggregated for graph display (hourly/daily rollups)

## Checkin Response

The manager server responds to each Main Agent checkin with:
- **next_interval**: Seconds until next checkin (dynamic)
- **commands**: Array of pending commands to execute
- **config_updates**: Configuration changes to apply
- **force_update_check**: Boolean to trigger immediate update check

### Dynamic Interval Adjustment
The manager can adjust checkin frequency based on:
- Pending command queue length
- Firewall status (online/offline/degraded)
- Network conditions
- Manager load

Default intervals:
- Normal operation: 120 seconds (2 minutes)
- Commands pending: 30 seconds
- Offline recovery: 60 seconds
- High load: 300 seconds (5 minutes)

## SSH Tunnel Management

### Temporary SSH Access
- Duration: 30 minutes (default)
- Idle timeout: 10 minutes
- Port range: 8100-8200
- Automatic firewall rule cleanup via cron (every 5 minutes)

### Tunnel Lifecycle
```
1. User requests tunnel via web interface
2. Manager creates temporary SSH firewall rule
3. Manager allocates available port (8100-8200)
4. Manager establishes SSH tunnel to firewall
5. Tunnel forwards to firewall's detected web_port
6. User accesses firewall GUI through tunnel
7. Session expires after 30 minutes or 10 minutes idle
8. Cron cleanup removes firewall rule and kills tunnel
```

## Best Practices

### For Command Execution
1. Keep commands short and focused
2. Use timeouts for network operations
3. Return structured output (pipe to head/awk)
4. Handle errors gracefully

### For Checkin Timing
1. Don't reduce Main Agent below 2 minutes (manager load)
2. Quick Agent at 15 seconds is optimal (balance detection vs overhead)
3. Update Agent at 5 minutes minimizes OPNsense repo load
4. Use dynamic intervals for special cases (recovery, commands pending)

### For Traffic Statistics
1. Main Agent sends incremental data (not cumulative)
2. Manager calculates rates and trends
3. Store raw data, aggregate for display
4. Implement data retention policy (90 days default)

## Monitoring and Debugging

### Check Agent Status
```bash
# On firewall
ps aux | grep opnsense_agent
crontab -l | grep opnsense
tail -50 /var/log/opnsense_agent.log

# On manager
SELECT * FROM firewalls WHERE id=X;
SELECT * FROM firewall_commands WHERE firewall_id=X ORDER BY id DESC LIMIT 10;
SELECT * FROM ssh_access_sessions WHERE firewall_id=X AND status='active';
```

### Common Issues
- **Agent not checking in**: Check cron job, verify network connectivity
- **Commands not executing**: Check Quick Agent detection, verify command syntax
- **Traffic stats missing**: Verify WAN interface name, check netstat output format
- **Tunnel connection fails**: Check web_port detection, verify SSH key auth

## Version History

- **v3.4.9**: Added web_port auto-detection
- **v3.4.8**: Fixed result reporting (head -1 | awk)
- **v3.3.2**: Added wake signal for immediate recheck
- **v3.3.0**: Added SSH reverse tunnel support
- **v3.5.0**: Traffic statistics collection (PLANNED)

---

Last Updated: October 15, 2025
