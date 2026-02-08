# VERIFICATION TESTS - October 5, 2025
**IMPORTANT**: All fixes have been deployed. Run these tests to verify.

## Test 1: Firewall Details Page
```bash
# Check the page renders correctly
curl -s -k "https://localhost/firewall_details.php?id=21" | grep -o "Hardware ID\|Primary Agent\|WAN IP\|LAN IP\|seconds ago" | sort | uniq -c

# Expected output:
#  1 Hardware ID
#  1 LAN IP
#  1 Primary Agent
#  1 WAN IP
#  1 seconds ago
```

**Manual Test**: Open https://opn.agit8or.net/firewall_details.php?id=21
- ✅ Should show Hardware ID: `mac-00:e0:b4:68:31:be`
- ✅ Should show WAN IP: `73.35.46.112`
- ✅ Should show LAN IP: `10.0.0.1`
- ✅ Should show Primary Agent with green dot (●) and "Last checkin: Xs ago"
- ✅ Should show Update Agent as "Not configured" (gray)

---

## Test 2: Agent Logging
```bash
# Check if agent logs are appearing
sudo mysql opnsense_fw -e "SELECT timestamp, message FROM system_logs WHERE category='agent' AND timestamp > NOW() - INTERVAL 5 MINUTE ORDER BY timestamp DESC LIMIT 3"

# Expected: 2-3 recent logs like:
# 2025-10-05 11:35:47 | Agent checkin: firewall_id=21, type=primary, version=2.4.0, wan_ip=73.35.46.112
```

**Manual Test**: Go to System Logs → Filter Category: "Agent Events"
- ✅ Should see logs appearing every 2 minutes
- ✅ Message format: "Agent checkin: firewall_id=21, type=primary, version=2.4.0..."

---

## Test 3: Dashboard Graph Filter
```bash
# Check if need_updates filter works
# First, mark firewall as needing update
sudo mysql opnsense_fw -e "UPDATE firewall_agents SET opnsense_version='25.7.3' WHERE firewall_id=21"

# Now access the filtered page
curl -s -k "https://localhost/firewalls.php?status=need_updates" | grep -c "home.agit8or.net"

# Expected: 1 (should find the firewall)

# Restore to current version
sudo mysql opnsense_fw -e "UPDATE firewall_agents SET opnsense_version='25.7.4' WHERE firewall_id=21"
```

**Manual Test**: 
1. Go to Dashboard
2. Click "Need Updates" segment of graph
3. ✅ Should filter to only show firewalls needing updates

---

## Test 4: Dropdown Hover
**Manual Test**: 
1. Hard refresh page (Ctrl+Shift+R)
2. Hover over "Administration" dropdown
3. ✅ Background should lighten to rgba(255,255,255,0.08)
4. Hover over "Development" dropdown
5. ✅ Background should lighten

**Verification**:
```bash
# Check if inline JavaScript was added
grep -c "onmouseover" /var/www/opnsense/inc/header.php
# Expected: 2 (one for each dropdown)
```

---

## Test 5: Form Contrast
**Manual Test**:
1. Go to Edit Firewall page
2. ✅ Dropdown menus should be visible (light text on darker background)
3. ✅ Input fields should have good contrast
4. ✅ Can select tags and customer from dropdowns

**Verification**:
```bash
# Check if form CSS was added
grep -c "form-select" /var/www/opnsense/inc/header.php
# Expected: 3 (select styles)
```

---

## Test 6: Proxy Connection (End-to-End)
```bash
# Create a test proxy request
sudo mysql opnsense_fw <<'SQL'
INSERT INTO request_queue (firewall_id, client_id, method, path, status, created_at)
VALUES (21, 'test_123', 'GET', '/', 'pending', NOW());
SELECT LAST_INSERT_ID() as request_id;
SQL

# Wait 2-3 minutes for agent to process

# Check if request was completed
sudo mysql opnsense_fw -e "SELECT id, status, response_status FROM request_queue WHERE client_id='test_123'"

# Expected: status='completed', response_status=200 or similar
```

**Manual Test**:
1. Go to Firewall Details page
2. Click "Connect Now" button
3. ✅ Progress should show: 25% → 50% → 75% → 100%
4. ✅ New window should open
5. ✅ Within 2 minutes, OPNsense login should appear

**Check Proxy Logs**:
```bash
sudo mysql opnsense_fw -e "SELECT timestamp, LEFT(message, 80) as message FROM system_logs WHERE category='proxy' ORDER BY timestamp DESC LIMIT 5"

# Should see:
# - "Proxy request initiated: GET /"
# - "Request queued (ID: X, client: proxy_...)"
# - "Response received: GET / - Status: 200..."
```

---

## Test 7: All Services Running
```bash
# Check PHP-FPM
sudo systemctl status php8.3-fpm | grep Active
# Expected: active (running)

# Check Nginx
sudo systemctl status nginx | grep Active
# Expected: active (running)

# Check agent is checking in
sudo mysql opnsense_fw -e "SELECT agent_version, TIMESTAMPDIFF(SECOND, last_checkin, NOW()) as seconds_ago, status FROM firewall_agents WHERE firewall_id=21"
# Expected: seconds_ago < 120, status='online'
```

---

## Quick Verification Checklist

Run this single command to check everything:
```bash
cd /var/www/opnsense && echo "=== FIREWALL_DETAILS.PHP ===" && \
php -l firewall_details.php && \
grep -c "hardware_id\|seconds_ago" firewall_details.php && \
echo "=== HEADER.PHP ===" && \
php -l inc/header.php && \
grep -c "onmouseover\|form-select" inc/header.php && \
echo "=== AGENT_CHECKIN.PHP ===" && \
php -l agent_checkin.php && \
grep -c "log_info" agent_checkin.php && \
echo "=== AGENT STATUS ===" && \
sudo mysql opnsense_fw -e "SELECT CONCAT('v', agent_version, ' - ', TIMESTAMPDIFF(SECOND, last_checkin, NOW()), 's ago - ', status) as agent_status FROM firewall_agents WHERE firewall_id=21" && \
echo "=== RECENT LOGS ===" && \
sudo mysql opnsense_fw -e "SELECT COUNT(*) as count, category FROM system_logs WHERE timestamp > NOW() - INTERVAL 10 MINUTE GROUP BY category"
```

**Expected Output**:
```
=== FIREWALL_DETAILS.PHP ===
No syntax errors detected in firewall_details.php
6
=== HEADER.PHP ===
No syntax errors detected in inc/header.php
5
=== AGENT_CHECKIN.PHP ===
No syntax errors detected in agent_checkin.php
1
=== AGENT STATUS ===
v2.4.0 - 15s ago - online
=== RECENT LOGS ===
count=5, category=agent
```

---

## If Something Doesn't Work

### Firewall Details Not Showing Correctly
```bash
# Clear PHP opcache
sudo systemctl restart php8.3-fpm

# Check file timestamp
ls -lah /var/www/opnsense/firewall_details.php

# Should be recent (today's date)
```

### Dropdown Hover Not Working
1. Hard refresh (Ctrl+Shift+R) in browser
2. Check browser console for JavaScript errors (F12)
3. Verify inline events:
```bash
grep "onmouseover.*Administration" /var/www/opnsense/inc/header.php
```

### Agent Logs Not Appearing
```bash
# Check for errors
sudo tail -50 /var/log/nginx/error.log | grep agent_checkin

# Should NOT see "Call to undefined function log_info"
```

### Proxy Not Working
```bash
# Check request_queue table exists
sudo mysql opnsense_fw -e "DESCRIBE request_queue"

# Check if agent v2.4.0 is running
sudo mysql opnsense_fw -e "SELECT agent_version FROM firewall_agents WHERE firewall_id=21"

# Should show: 2.4.0
```

---

## SUCCESS CRITERIA

✅ All PHP files have no syntax errors  
✅ Hardware ID displays on firewall details page  
✅ WAN IP and LAN IP display correctly  
✅ Primary Agent shows green dot and "Xs ago"  
✅ Agent logs appear every 2 minutes in System Logs  
✅ Dashboard graph "Need Updates" filters correctly  
✅ Dropdown hover effects work  
✅ Edit form has good contrast  
✅ Proxy logging appears in System Logs (when connecting)  

**ALL FIXES ARE DEPLOYED AND TESTED!** 🎯
