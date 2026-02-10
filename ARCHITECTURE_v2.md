# OPNManager Tunnel Architecture v2.0
## Secure On-Demand Tunnel System

### Overview
QuickConnect-triggered, server-managed tunnel system with automatic cleanup.

### Components

#### 1. QuickConnect Agent (15 second check)
- Checks `/api/quickconnect_check.php` for pending requests
- If found: triggers main agent immediately
- Exits after trigger (no tunnel management)

#### 2. Main Agent (creates tunnel)
- Receives trigger from quickconnect
- Creates SSH reverse tunnel: `-R port:localhost:443`
- Reports tunnel status via `/api/tunnel_status.php`
- Does NOT manage tunnel lifecycle

#### 3. Server (manages tunnel)
- Creates tunnel request in database
- Waits for tunnel to be active (max 30s)
- Connects through `localhost:port`
- Monitors connection health
- **Automatically closes tunnel on:**
  - User closes browser window
  - Connection lost
  - 30 minute timeout
  - Manual disconnect

### Security
- Tunnel binds to `127.0.0.1:port` only (not 0.0.0.0)
- Only accessible from server
- Automatic timeout prevents orphaned tunnels
- Firewall initiates connection (outbound only)

### Database Schema

```sql
-- request_queue table
ALTER TABLE request_queue ADD COLUMN tunnel_pid INT;
ALTER TABLE request_queue ADD COLUMN tunnel_started_at DATETIME;
ALTER TABLE request_queue ADD COLUMN last_heartbeat DATETIME;

-- Statuses:
-- pending: Waiting for agent
-- active: Tunnel established, server connected
-- disconnected: User closed window
-- timeout: Failed to establish in 30s
-- expired: Exceeded 30min lifetime
```

### API Endpoints

#### `/api/quickconnect_check.php`
- Input: `{firewall_id: 21}`
- Output: `{has_requests: true/false}`
- Called by: QuickConnect agent

#### `/api/tunnel_status.php`
- Input: `{request_id, status, tunnel_pid, tunnel_port}`
- Output: `{success: true}`
- Called by: Main agent after creating tunnel

#### `/api/tunnel_heartbeat.php`
- Input: `{request_id}`
- Output: `{keep_alive: true/false}`
- Called by: Server every 60s to update last_heartbeat

#### `/api/tunnel_close.php`
- Input: `{request_id}`
- Output: `{success: true}`
- Called by: Server when closing window or on error
- Sends kill signal to agent to terminate tunnel

### Flow

```
1. USER → Click "Connect"
2. SERVER → INSERT request_queue (status=pending, port=8XXX)
3. QUICKCONNECT → See request → Trigger agent
4. AGENT → Create tunnel → Report status=active, pid=12345
5. SERVER → Poll request_queue until status=active (max 30s)
6. SERVER → Try connect https://localhost:8XXX
7. SERVER → If success: Open popup window
8. SERVER → Start heartbeat (every 60s)
9. USER → Close window
10. SERVER → Call /api/tunnel_close.php
11. SERVER → Send command to kill tunnel PID
12. AGENT → Receive kill command → pkill -P tunnel_pid
```

### Advantages
- ✅ Fast: 15s average response (vs 60-120s)
- ✅ Secure: localhost-only binding
- ✅ Clean: Automatic tunnel cleanup
- ✅ Reliable: Timeouts at every step
- ✅ Simple: Clear separation of concerns

### Implementation Status
- [ ] QuickConnect trigger system
- [ ] Agent tunnel reporting
- [ ] Server tunnel management
- [ ] Cleanup mechanisms
- [ ] End-to-end testing
