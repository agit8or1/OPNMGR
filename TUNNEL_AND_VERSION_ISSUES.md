# Tunnel & Version Issues - October 13, 2025

## Issue 1: Version Shows "Unknown"

**Problem**: OPNsense version shows as "Unknown" in dashboard

**Root Cause**: Agent v3.3.1 tries to read files from `/usr/local/opnsense/version/core*` but these files may not exist on all OPNsense installations.

**Solution**: Update agent to use `opnsense-version` command first, then fall back to file reading.

**New Version Detection Logic** (for agent v3.3.2):
```bash
# Try opnsense-version command first
if command -v opnsense-version >/dev/null 2>&1; then
    VERSION=$(opnsense-version 2>/dev/null | head -1 | tr -d '\n')
fi

# Fall back to version files
if [ -z "$VERSION" ] || [ "$VERSION" = "unknown" ]; then
    if [ -f "/usr/local/opnsense/version/core" ]; then
        VERSION=$(cat "/usr/local/opnsense/version/core" 2>/dev/null | head -1 | tr -d '\n')
    fi
fi

# Last resort: Try pkg info
if [ -z "$VERSION" ] || [ "$VERSION" = "unknown" ]; then
    VERSION=$(pkg info opnsense 2>/dev/null | grep Version | awk '{print $3}')
fi
```

## Issue 2: Reverse Tunnel Timeout

**Problem**: Clicking "Connect" shows "Timeout - Agent not responding"

**Root Cause**: Timing issue between request creation and agent checkin

**Current Flow**:
1. User clicks "Connect" → Creates request in `request_queue` with status='pending'
2. JavaScript polls `/check_tunnel_status.php` every 1 second for 30 seconds
3. Agent checks in every 2 minutes at :00 seconds
4. Agent reads `pending_requests` from checkin response
5. Agent creates tunnel and updates status to 'processing'
6. JavaScript detects 'processing' status and redirects

**Problem**: If user clicks between agent checkins, they may wait up to 2 minutes!

**Solutions**:

### Option A: Reduce Agent Checkin Interval (Quick Fix)
Change cron from */2 to */1 (every minute)
- Pro: Simple, just edit cron
- Con: Increases server load, still up to 1 min wait

### Option B: Wake Agent on Demand (Better)
Add endpoint `/api/wake_agent.php` that:
1. Creates pending request
2. Sends wake signal to agent (touch a file the agent watches)
3. Agent checks for requests immediately

### Option C: Persistent Tunnel (Best)
Keep one reverse tunnel always open for each firewall
- Pro: Instant access
- Con: More SSH connections, more complex

**Current Implementation**: Uses Option A (2 min checkin)

**Recommendation**: Implement Option B for better UX

