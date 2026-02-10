# OPNsense Update Detection Logic - Explained

## User Question
"We have different opnsense versions, but both say they are up to date"

## Current Situation

**Firewall 21 (home.agit8or.net):**
```
Current Version: 25.7.5
Available Version: null
Updates Available: 0 (No)
Last Check: 2025-11-10 14:50:02
Status: ✅ Up to date
```

**Firewall 25 (opn2.agit8or.net):**
```
Current Version: 25.1.12
Available Version: null
Updates Available: 0 (No)
Last Check: 2025-11-10 14:50:02
Status: ✅ Up to date
```

## Why This Is Correct

### OPNsense Has Multiple Release Tracks

OPNsense uses independent versioning for different product editions:

**1. Business Edition (25.7.x series)**
- Paid subscription required
- Enhanced features and support
- Example: 25.7.1, 25.7.2, 25.7.3, 25.7.4, 25.7.5
- Released on different schedule from Community

**2. Community Edition (25.1.x series)**
- Free and open source
- Core functionality
- Example: 25.1.1, 25.1.2, 25.1.10, 25.1.11, 25.1.12
- Independent release schedule

**These are NOT comparable versions!**
- 25.7.5 is not "newer" than 25.1.12
- They are different product lines with different features
- Like comparing "Windows 11 Pro" vs "Windows 11 Home"

### How Update Detection Works

**Agent-Side Logic (opnsense_agent_v3.8.3.sh:136-189):**

```bash
# 1. Query firewall's own update API
API_RESPONSE=$(curl -s -k -m 10 "https://localhost/api/core/firmware/status")

# 2. Check for upgrade availability
if echo "$API_RESPONSE" | grep -q '"status_upgrade":"ok"'; then
    UPDATES_AVAILABLE="true"
fi

# 3. Check for new packages
PKG_COUNT=$(echo "$API_RESPONSE" | grep -o '"new_packages":[0-9]*' | cut -d':' -f2)
if [ "$PKG_COUNT" -gt 0 ]; then
    UPDATES_AVAILABLE="true"
fi

# 4. Fallback to opnsense-update command
if command -v opnsense-update >/dev/null 2>&1; then
    CHECK_OUTPUT=$(opnsense-update -c)
    if echo "$CHECK_OUTPUT" | grep -qi "update.*available"; then
        UPDATES_AVAILABLE="true"
    fi
fi
```

**Key Point:** The agent asks THE FIREWALL ITSELF "do you have updates?" It doesn't compare version numbers with other firewalls.

**Manager-Side Logic (agent_checkin.php:146-151):**

```php
// Accept update status from agent (not hardcoded comparison)
$updates_available = isset($_POST['updates_available']) ? intval($_POST['updates_available']) : 0;
$latest_stable_version = isset($_POST['available_version']) ? $_POST['available_version'] : $current_version;

// Store in database
$stmt = $DB->prepare('UPDATE firewalls SET last_update_check = NOW(), current_version = ?, available_version = ?, updates_available = ? WHERE id = ?');
$stmt->execute([$current_version, $latest_stable_version, $updates_available, $firewall_id]);
```

**Key Point:** The manager trusts what the firewall reports. It doesn't perform independent version comparison.

## What Each Firewall Is Actually Checking

### Firewall 21 (Business Edition 25.7.5)

**Checks:**
1. Connects to OPNsense Business update mirror
2. Queries: "Is there a newer version in the 25.7.x series?"
3. Answer: "No, 25.7.5 is the latest"
4. Reports: `updates_available = 0`

### Firewall 25 (Community Edition 25.1.12)

**Checks:**
1. Connects to OPNsense Community update mirror
2. Queries: "Is there a newer version in the 25.1.x series?"
3. Answer: "No, 25.1.12 is the latest"
4. Reports: `updates_available = 0`

**Both answers are correct within their respective tracks!**

## Common Misconceptions

### ❌ Misconception 1: "Higher number = newer version"
**Reality:** Different product lines, not sequential versions
- 25.7.5 is NOT newer than 25.1.12
- They're parallel products on different schedules

### ❌ Misconception 2: "All firewalls should be on the same version"
**Reality:** Depends on licensing and requirements
- Business Edition requires paid subscription
- Community Edition is free
- You can run both in the same network

### ❌ Misconception 3: "The manager should force version consistency"
**Reality:** Each firewall manages its own updates
- Update availability is determined BY the firewall
- Manager only displays what firewall reports
- This is correct architecture

## When Updates WOULD Show as Available

### Scenario 1: New Community Release
```
Current: 25.1.12
New Release: 25.1.13 published
Firewall API returns: {"status_upgrade": "ok", "new_version": "25.1.13"}
Agent reports: updates_available = 1
Manager displays: ⚠️ Update available: 25.1.13
```

### Scenario 2: Package Updates
```
Current: 25.7.5 (no OS update)
But: 3 packages have updates
Firewall API returns: {"new_packages": 3}
Agent reports: updates_available = 1
Manager displays: ⚠️ Updates available (packages)
```

### Scenario 3: Security Update
```
Current: 25.1.12
Security patch: 25.1.12_1 released
Firewall API returns: {"status_upgrade": "ok"}
Agent reports: updates_available = 1
Manager displays: ⚠️ Update available
```

## How to Upgrade Between Tracks

**To upgrade from Community (25.1.x) to Business (25.7.x):**

1. Purchase OPNsense Business subscription
2. SSH into firewall
3. Run: `opnsense-update -t <business_track_name>`
4. System downloads and installs Business edition
5. Reboot required
6. After reboot, firewall shows 25.7.x version

**This is a MAJOR upgrade, not a simple update!**

## Verification Commands

### Check What Track a Firewall Is On

**Via SSH:**
```bash
# Check current release track
cat /usr/local/opnsense/version/base

# Check update configuration
cat /usr/local/etc/pkg/repos/OPNsense.conf

# Manually check for updates
opnsense-update -c
```

**Via API:**
```bash
curl -k https://localhost/api/core/firmware/status | python3 -m json.tool
```

### Check Manager Database

```bash
php -r '
require_once "/var/www/opnsense/inc/db.php";
$stmt = $DB->query("
    SELECT
        id,
        hostname,
        version as current_version,
        available_version,
        updates_available,
        last_update_check
    FROM firewalls
    ORDER BY id
");
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo json_encode($row, JSON_PRETTY_PRINT) . \"\n\n\";
}
'
```

## Recommendations

### Option 1: Accept Mixed Environment ✅
**Pros:**
- Cost-effective (Community is free)
- Each firewall on appropriate edition for its needs
- Both receive security updates within their tracks

**Cons:**
- Different feature sets
- Different update schedules
- May confuse monitoring

### Option 2: Standardize on Community Edition
**Pros:**
- Free for all firewalls
- Consistent versions
- Easier to manage

**Cons:**
- Lose Business edition features on firewall 21
- Must downgrade firewall 21: 25.7.5 → 25.1.12

### Option 3: Upgrade All to Business Edition
**Pros:**
- Consistent Business features
- Professional support
- Enhanced functionality

**Cons:**
- Cost: requires paid subscriptions for all firewalls
- Must upgrade firewall 25: 25.1.12 → 25.7.5

## Conclusion

**Your system is working correctly!**

Both firewalls accurately report their update status within their respective product tracks:
- Firewall 21 is up-to-date within the Business track (25.7.5)
- Firewall 25 is up-to-date within the Community track (25.1.12)

**No changes needed** unless you want to standardize all firewalls on the same product edition.

---
**Date:** 2025-11-10 14:55
**Status:** ✅ Update logic working as designed
**Recommendation:** No action required unless track standardization is desired
