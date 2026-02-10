# QuickConnect Agent v1.0
## Instant Tunnel Response System

### Problem
- Main agent checks in every 2 minutes
- Even with wake signals, there's still a delay
- Users expect INSTANT connection

### Solution: QuickConnect Agent
Lightweight agent that runs every 15 seconds and ONLY checks for tunnel requests.
When found, it immediately triggers the main agent.

### Architecture

```
[User Clicks Connect]
        ↓
[Tunnel request created]
        ↓
[QuickConnect checks (within 15 sec)]
        ↓
[Triggers main agent immediately]
        ↓
[Main agent processes tunnel]
        ↓
[Connection established <20 seconds]
```

### Agent Details

**File**: `/usr/local/bin/quickconnect_agent.sh`
**Size**: 1.1 KB (vs 19KB main agent)
**Frequency**: Every 15 seconds
**Function**: Check for pending requests → Trigger main agent

**Cron Schedule**:
```cron
* * * * * sleep 0 && /usr/local/bin/quickconnect_agent.sh
* * * * * sleep 15 && /usr/local/bin/quickconnect_agent.sh
* * * * * sleep 30 && /usr/local/bin/quickconnect_agent.sh
* * * * * sleep 45 && /usr/local/bin/quickconnect_agent.sh
```
This creates 4 checks per minute = every 15 seconds

### API Endpoint

**URL**: `/api/quickconnect_check.php`
**Method**: POST
**Input**: `{"firewall_id": 21}`
**Output**: `{"has_requests": true/false}`
**Speed**: <50ms (single DB query)

### What It Does NOT Do
- ❌ Collect system data
- ❌ Send uptime/version info
- ❌ Process commands
- ❌ Write logs
- ❌ Update firewall status

### What It DOES Do
- ✅ Check for pending tunnel requests (1 query)
- ✅ Trigger main agent if found
- ✅ Exit immediately

### Resource Usage
- **Network**: 1 HTTP request per 15 seconds = 4 KB/min
- **CPU**: <0.01% (runs for <100ms every 15 sec)
- **Memory**: 0 MB (no persistent process)
- **Disk**: No logging

### Performance Metrics

**Before QuickConnect**:
- Average wait: 60 seconds (half of 2-min interval)
- Best case: 5 seconds (if checked in just before request)
- Worst case: 120 seconds

**With QuickConnect**:
- Average wait: 7.5 seconds (half of 15-sec interval)
- Best case: <1 second (if checking right when request created)
- Worst case: 15 seconds
- **8x faster on average!**

### Three-Agent Architecture

1. **Main Agent** (opnsense_agent_v2.sh)
   - Runs every 2 minutes
   - Collects data, processes commands
   - Updates firewall status

2. **Update Agent** (update_agent)
   - Runs every 5 minutes at :22
   - Checks for OPNsense updates
   - Reports update status

3. **QuickConnect Agent** (quickconnect_agent.sh) ← NEW
   - Runs every 15 seconds
   - Only checks for tunnel requests
   - Triggers main agent when needed

### Deployment

Command ID: 874
Status: Pending
Will deploy on next main agent checkin

### Testing

After deployment:
1. Click "Connect" button
2. Connection should establish in 10-20 seconds
3. Check logs: `tail -f /var/log/nginx/access.log | grep quickconnect_check`
4. Should see requests every 15 seconds

### Rollback

If needed:
```bash
crontab -l | grep -v quickconnect | crontab -
rm /usr/local/bin/quickconnect_agent.sh
```

### Future Enhancements

Possible improvements:
- WebSocket connection for instant push
- Shared memory flag instead of HTTP
- Adaptive checking (faster when request pending)
- Multiple firewall support

---

**Status**: Deployed, waiting for command execution
**Next**: Test connection speed after quickconnect active
