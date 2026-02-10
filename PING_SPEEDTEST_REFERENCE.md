# Ping & Speedtest System - Quick Reference Card
**Last Updated:** October 29, 2025 | **Status:** ✅ Production Ready

---

## 🎯 What It Does

**Ping Monitoring** - Measures WAN latency
- Agents perform 4 pings before each checkin (every 3 minutes)
- Stores latency measurements in database
- Displays as hourly-aggregated cyan line chart

**Speedtest Monitoring** - Measures bandwidth
- Agents run speedtest daily at unique time (23:00-23:59)
- Stores download/upload speeds in database
- Displays as daily-aggregated dual-line chart (green/orange)

---

## 📊 Data Storage

| Table | Records | Range | Update |
|-------|---------|-------|--------|
| `firewall_latency` | 12 | 13.9-16.8 ms | Every 3 min |
| `firewall_speedtest` | 5 | 450-470 Mbps | Once daily |

---

## 🔌 API Endpoints

### Agent-to-Server (Send Data)
```
POST /api/agent_ping_data.php
POST /api/agent_speedtest_result.php
```

### Server-to-Dashboard (Get Data)
```
GET /api/get_latency_stats.php?firewall_id=21&days=7
GET /api/get_speedtest_results.php?firewall_id=21&days=7
```

---

## 📈 Dashboard Charts

**Location:** firewall_details.php → Overview tab (below CPU/Memory/Disk)

| Chart | Type | Color | Y-Axis |
|-------|------|-------|--------|
| Latency | Line | Cyan | milliseconds |
| SpeedTest | Dual Line | Green/Orange | Mbps |

---

## 🐛 Debugging

**To check if working:**
1. Open firewall_details.php?id=21
2. Press F12 (browser console)
3. Look for these messages:
   - `"Latency data received: {success: true, labels: Array(...), latency: Array(...)}"` ✅
   - `"SpeedTest data received: {success: true, labels: Array(...), download: Array(...), upload: Array(...)}"` ✅

**If not showing:**
- Check for red error messages in console
- Verify browser is logged in (authentication required)
- Try refreshing the page
- Check that firewall_id in URL is valid in database

---

## 📁 Key Files

| File | Purpose | Status |
|------|---------|--------|
| `firewall_details.php` | Dashboard with charts | ✅ Updated Oct 29 |
| `api/agent_ping_data.php` | Ping receiver | ✅ Working |
| `api/agent_speedtest_result.php` | Speedtest receiver | ✅ Working |
| `api/get_latency_stats.php` | Latency retrieval | ✅ Working |
| `api/get_speedtest_results.php` | Speedtest retrieval | ✅ Working |
| `inc/header.php` | Menu system | ✅ License Server restored |

---

## 🚀 Deploying to Agents

### Minimal Implementation

**Before Checkin:**
```bash
# Get 4 latency measurements
ping -c 4 8.8.8.8 | grep 'time='
# Extract: 14.5, 15.2, 14.8, 15.1 (example)

# Send to server
curl -X POST /api/agent_ping_data.php \
  -H "Content-Type: application/json" \
  -d '{"firewall_id": 21, "ping_results": [...]}'

# Then proceed with normal checkin
```

**Daily at 23:00:**
```bash
# Run speedtest
speedtest-cli --simple
# Output: download \n upload

# Send to server
curl -X POST /api/agent_speedtest_result.php \
  -H "Content-Type: application/json" \
  -d '{"firewall_id": 21, "download_mbps": 465, "upload_mbps": 96}'
```

---

## ✅ Verification Checklist

- [x] Database tables exist
- [x] Sample data in both tables
- [x] API endpoints working
- [x] Charts display data
- [x] Console debugging enabled
- [x] License Server menu restored
- [x] Time range selector works
- [x] All PHP syntax valid

---

## 📞 Common Issues

| Problem | Solution |
|---------|----------|
| Charts not showing | Check F12 console for errors, verify login |
| No data points | Verify database has records: `SELECT COUNT(*) FROM firewall_latency WHERE firewall_id=21` |
| API returns 401 | Check authentication - must be logged in |
| API returns 400 | Check firewall_id is valid number in database |
| API returns 500 | Check PHP error logs: `/var/log/php-fpm/error.log` |

---

## 📚 Full Documentation

- **Integration Guide:** `/var/www/opnsense/AGENT_INTEGRATION_GUIDE.md` (456 lines)
- **Quick Start:** `/var/www/opnsense/AGENT_QUICK_REFERENCE.md`
- **Complete Report:** `/var/www/opnsense/PING_SPEEDTEST_COMPLETE_REPORT.md`
- **Fixes Applied:** `/var/www/opnsense/PING_SPEEDTEST_FIXES_OCT29.md`

---

## 🎯 Next Steps

1. **View Dashboard** → firewall_details.php?id=21
2. **Check Charts** → Look for 2 new charts on Overview tab
3. **Debug if Needed** → Press F12, check console logs
4. **Deploy Agents** → Use AGENT_INTEGRATION_GUIDE.md
5. **Monitor Data** → Watch for new measurements appearing daily

---

**System Status:** ✅ **READY FOR PRODUCTION**
- All components working
- All APIs responding correctly
- All data displaying in charts
- All debugging enabled

**Last Change:** October 29, 2025 - Added console debugging, restored License Server menu
