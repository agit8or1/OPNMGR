# Agent Ping & Speedtest - Quick Reference Card

## 🎯 What Changed?

Agents now do TWO things:

### 1. Ping Before Checkin (Every checkin cycle)
```
Agent: Ping 8.8.8.8 4 times
       ↓
       Send 4 latency values to /api/agent_ping_data.php
       ↓
       Then do normal checkin
```

### 2. Nightly Speedtest (Once per day at 23:XX)
```
Agent: Query /api/agent_config.php to get scheduled time
       ↓
       Wait until scheduled time (23:00-23:59)
       ↓
       Run speedtest-cli
       ↓
       Send results to /api/agent_speedtest_result.php
       ↓
       Retry up to 3 times if it fails
```

---

## 📡 API Endpoints

### POST /api/agent_ping_data.php
Send 4 ping results before checkin
```json
{
  "firewall_id": 21,
  "agent_token": "your_token",
  "ping_results": [
    {"latency_ms": 14.5},
    {"latency_ms": 15.2},
    {"latency_ms": 14.8},
    {"latency_ms": 15.1}
  ]
}
```

### POST /api/agent_speedtest_result.php
Send speedtest results after running test
```json
{
  "firewall_id": 21,
  "agent_token": "your_token",
  "download_mbps": 465.3,
  "upload_mbps": 95.7,
  "ping_ms": 15.2,
  "server_location": "New York"
}
```

### GET /api/agent_config.php?firewall_id=21&type=speedtest
Query scheduled speedtest time for this firewall
```
Response includes: scheduled_time (23:XX), frequency, retry settings
```

---

## 🔧 Implementation Checklist

For your agent, you need to:

- [ ] **Before every checkin:**
  1. Run `ping -c 4 8.8.8.8`
  2. Extract 4 latency values
  3. POST to `/api/agent_ping_data.php`
  4. Then do your normal checkin

- [ ] **Daily at 23:XX:**
  1. Query `/api/agent_config.php?firewall_id=X&type=speedtest`
  2. Get your scheduled time from response
  3. At that time, run `speedtest-cli --simple`
  4. Parse download/upload from output
  5. POST to `/api/agent_speedtest_result.php`
  6. If fails, retry up to 3 times

---

## 📊 Data Storage

### Ping Data
- **Table:** `firewall_latency`
- **Per ping:** ~10 bytes
- **Frequency:** ~480 pings/day (every 3 minutes)
- **Storage/year:** ~1.75MB (90-day retention)

### Speedtest Data
- **Table:** `firewall_speedtest`
- **Per test:** ~50 bytes
- **Frequency:** 1 test/day
- **Storage/year:** ~18KB (365-day retention)

**Total: ~1.77MB per firewall per year (negligible)**

---

## 📈 Charts

Dashboard automatically shows:
- **Latency Chart** - Shows ping latency over time (Overview tab)
- **Speedtest Chart** - Shows download/upload speeds over time (Overview tab)

Charts update in real-time as data arrives.

---

## 📚 Full Documentation

Read the complete guide:
```bash
/var/www/opnsense/AGENT_INTEGRATION_GUIDE.md
```

This includes:
- Complete bash examples
- Error handling patterns
- Retry logic templates
- Troubleshooting guide
- Cron job examples

---

## ✅ Test It

### Test Ping Endpoint
```bash
curl -X POST http://manager.local/api/agent_ping_data.php \
  -H "Content-Type: application/json" \
  -d '{
    "firewall_id": 21,
    "agent_token": "test",
    "ping_results": [
      {"latency_ms": 14.5},
      {"latency_ms": 15.2},
      {"latency_ms": 14.8},
      {"latency_ms": 15.1}
    ]
  }'
```

Expected response:
```json
{"success":true,"message":"Stored 4 ping results","average_latency_ms":14.9}
```

### Test Speedtest Endpoint
```bash
curl -X POST http://manager.local/api/agent_speedtest_result.php \
  -H "Content-Type: application/json" \
  -d '{
    "firewall_id": 21,
    "agent_token": "test",
    "download_mbps": 465.3,
    "upload_mbps": 95.7,
    "ping_ms": 15.2,
    "server_location": "New York"
  }'
```

Expected response:
```json
{"success":true,"message":"SpeedTest result stored successfully"}
```

### Test Config Endpoint
```bash
curl http://manager.local/api/agent_config.php?firewall_id=21
```

You'll get back your scheduled ping config + speedtest time.

---

## 🚀 Cron Jobs

### For Ping (runs with every checkin)
```bash
# Your existing checkin cron - just modify to include ping first
*/3 * * * * /path/to/agent.sh --with-ping
```

### For Speedtest (runs nightly)
```bash
# Add this to run speedtest at 23:00 every night
0 23 * * * /path/to/agent.sh speedtest
```

---

## 🐛 Common Issues

| Problem | Solution |
|---------|----------|
| Ping data not arriving | Check network connectivity: `ping 8.8.8.8` |
| Speedtest not running | Verify `speedtest-cli` installed: `pip install speedtest-cli` |
| Results not showing in charts | Check agent firewall_id matches manager firewall_id |
| 401 error on POST | Verify firewall exists in database |
| 400 error on POST | Check JSON format and required fields |

---

## 📞 Support

- Read full guide: `/var/www/opnsense/AGENT_INTEGRATION_GUIDE.md`
- See implementation details: `/var/www/opnsense/AGENT_PING_SPEEDTEST_IMPLEMENTATION.md`
- Use template script: `/usr/local/bin/opnsense_agent_with_ping.sh` (reference)
- Database: Query `firewall_latency` and `firewall_speedtest` tables

**Status:** ✅ Production Ready
