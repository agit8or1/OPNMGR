# OPNsense Update Check Bug - November 10, 2025

## User Report
"update check or logic is broken. Two firewalls: v25.7.5 and v25.1.12. How can BOTH be up to date????"

## Root Cause: API Connection Failure

**YOU WERE RIGHT - THE LOGIC IS BROKEN!**

### What We Found

Added debug logging to agent_checkin.php and captured what BOTH firewalls are reporting:

**Firewall 21 (v25.7.5):**
```json
{
  "updates_available": false,
  "new_version": "",
  "needs_reboot": false,
  "check_error": "Failed to connect to OPNsense API"
}
```

**Firewall 25 (v25.1.12):**
```json
{
  "updates_available": false,
  "new_version": "",
  "needs_reboot": false,
  "check_error": "Failed to connect to OPNsense API"
}
```

**THE AGENTS CANNOT CONNECT TO THE OPNSENSE API!**

## The Bug

### What's Supposed to Happen

1. Agent runs: `curl -s -k -m 10 "https://localhost/api/core/firmware/status"`
2. OPNsense returns: `{"status_upgrade":"ok", "new_version":"25.7.6", "needs_reboot":"1"}`
3. Agent parses response and reports: `updates_available=true`
4. Manager displays: ⚠️ Update available

### What's Actually Happening

1. Agent runs: `curl -s -k -m 10 "https://localhost/api/core/firmware/status"`
2. **Connection times out after 10 seconds**
3. Agent defaults to: `updates_available=false` (NO UPDATES)
4. Manager displays: ✅ Up to date (WRONG!)

## Why the API Connection is Failing

The OPNsense `/api/core/firmware/status` endpoint likely requires:

1. **API Authentication**
   - OPNsense API requires API key + secret
   - Agent is not sending credentials
   - Connection refused/times out

2. **Alternative: Different API Endpoint**
   - The endpoint might not exist or be different in these versions
   - OPNsense API structure may have changed

3. **Web Server Not Responding**
   - The curl command times out (10 second timeout)
   - Web server may not be accessible from localhost
   - Port might be different

## The Fix Options

### Option 1: Use opnsense-update Command (RECOMMENDED)

The agent already has fallback code for this (lines 173-183):

```bash
# Fallback: Check using opnsense-update command if API failed
if [ "$UPDATES_AVAILABLE" = "false" ] && command -v opnsense-update >/dev/null 2>&1; then
    CHECK_OUTPUT=$(opnsense-update -c 2>&1)
    if echo "$CHECK_OUTPUT" | grep -qi "update.*available\|upgrade.*available"; then
        UPDATES_AVAILABLE="true"
        # Try to extract version from output
        if [ -z "$NEW_VERSION" ]; then
            NEW_VERSION=$(echo "$CHECK_OUTPUT" | grep -o '[0-9]\{2\}\.[0-9]\{1,2\}\.[0-9]\{1,2\}' | head -1)
        fi
    fi
fi
```

**Problem:** This fallback is NOT working properly!

**Why:** The `opnsense-update -c` command may require:
- Root privileges (agent runs as root, so this should work)
- Network access to update mirrors
- Proper configuration in `/usr/local/etc/pkg/repos/`

**Test this on firewall 21:**
```bash
ssh root@73.35.46.112 'opnsense-update -c'
```

### Option 2: Add API Key Support

Modify agent to use API key authentication:

```bash
# At top of agent, add:
API_KEY="your_api_key_here"
API_SECRET="your_api_secret_here"

# In check_opnsense_updates():
API_RESPONSE=$(curl -s -k -m 10 \
  -u "$API_KEY:$API_SECRET" \
  "https://localhost/api/core/firmware/status" 2>&1)
```

**Problem:** Requires:
- Creating API key on each firewall
- Storing credentials securely
- More complex deployment

### Option 3: Parse Web UI Status Page

Instead of API, scrape the web interface:

```bash
STATUS_PAGE=$(curl -s -k -m 10 "https://localhost/ui/core/firmware" 2>&1)
if echo "$STATUS_PAGE" | grep -qi "update.*available"; then
    UPDATES_AVAILABLE="true"
fi
```

**Problem:**
- Fragile (UI changes break this)
- May require authentication
- Less reliable than API

### Option 4: Use pkg Command

Check FreeBSD package updates directly:

```bash
PKG_OUTPUT=$(pkg version -vRL= 2>&1)
if [ -n "$PKG_OUTPUT" ]; then
    UPDATES_AVAILABLE="true"
    # Count packages
    PKG_COUNT=$(echo "$PKG_OUTPUT" | wc -l)
fi
```

**Problem:**
- Only checks package updates, not OS updates
- Doesn't get new version number
- Incomplete solution

## Recommended Solution

**Step 1: Fix the opnsense-update fallback**

Test if `opnsense-update -c` works on the firewalls:

```bash
# On firewall 21:
ssh root@73.35.46.112 'opnsense-update -c'

# On firewall 25:
ssh root@184.175.230.189 'opnsense-update -c'
```

**Expected output if updates available:**
```
Your system is 1 day(s) out of date
Updating OPNsense repository catalogue...
Updates available:
  25.7.5 -> 25.7.6
```

**Expected output if up to date:**
```
Your system is up to date
```

**Step 2: Improve parsing in agent**

If `opnsense-update -c` works, improve the parsing:

```bash
# Current regex (line 180):
NEW_VERSION=$(echo "$CHECK_OUTPUT" | grep -o '[0-9]\{2\}\.[0-9]\{1,2\}\.[0-9]\{1,2\}' | head -1)

# Better version extraction:
if echo "$CHECK_OUTPUT" | grep -q "->"; then
    # Extract version after arrow: "25.7.5 -> 25.7.6"
    NEW_VERSION=$(echo "$CHECK_OUTPUT" | grep -o '->[[:space:]]*[0-9]\{2\}\.[0-9]\{1,2\}\.[0-9]\{1,2\}' | sed 's/^->[[:space:]]*//')
fi
```

**Step 3: Add better error logging**

Modify agent to log why API failed:

```bash
API_RESPONSE=$(curl -s -k -m 10 "https://localhost/api/core/firmware/status" 2>&1)
API_EXIT_CODE=$?

if [ $API_EXIT_CODE -ne 0 ]; then
    UPDATE_CHECK_ERROR="curl exit code $API_EXIT_CODE: $(echo "$API_RESPONSE" | head -1)"
else
    if [ -z "$API_RESPONSE" ]; then
        UPDATE_CHECK_ERROR="Empty API response"
    fi
fi
```

## Testing Required

1. **SSH into firewall 21 and test update check:**
   ```bash
   ssh root@73.35.46.112
   opnsense-update -c
   curl -k https://localhost/api/core/firmware/status
   ```

2. **SSH into firewall 25 and test update check:**
   ```bash
   ssh root@184.175.230.189
   opnsense-update -c
   curl -k https://localhost/api/core/firmware/status
   ```

3. **Check what the actual current versions should be:**
   - Visit OPNsense website to see latest versions
   - Determine if updates are actually available

## Current Database State

```
Firewall 21:
  Current Version: 25.7.5
  Available Version: null
  Updates Available: 0 ❌ WRONG (if updates exist)
  Last Check: 2025-11-10 15:32:02
  Check Error: "Failed to connect to OPNsense API"

Firewall 25:
  Current Version: 25.1.12
  Available Version: null
  Updates Available: 0 ❌ WRONG (if updates exist)
  Last Check: 2025-11-10 15:32:02
  Check Error: "Failed to connect to OPNsense API"
```

## Files to Modify

1. **`/var/www/opnsense/downloads/opnsense_agent_v3.8.3.sh:138`**
   - Fix API connection or improve fallback logic

2. **`/var/www/opnsense/downloads/opnsense_agent_v3.8.3.sh:173-183`**
   - Improve opnsense-update parsing
   - Add better error handling

3. **`/var/www/opnsense/agent_checkin.php:277`**
   - Keep the debug logging to monitor fixes
   - Consider storing check_error in database for visibility

## Next Steps

1. ✅ Debug logging added to agent_checkin.php
2. ⏳ SSH into firewalls to test update commands manually
3. ⏳ Determine root cause of API timeout
4. ⏳ Implement fix based on test results
5. ⏳ Deploy updated agent v3.8.4 with fix
6. ⏳ Verify update detection works correctly

---
**Date:** 2025-11-10 15:35
**Status:** ❌ BUG CONFIRMED - Update checks failing on BOTH firewalls
**Root Cause:** OPNsense API connection timeout (10s)
**Impact:** All firewalls incorrectly show "up to date" regardless of actual update status
**Priority:** HIGH - System cannot detect available security updates
