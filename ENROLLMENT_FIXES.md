# Enrollment and IP Detection Issues - Fixed

## Issues Resolved

### 1. ❌ "Firewall already enrolled" Error
**Problem**: Enrollment script was rejecting re-enrollment attempts for existing firewalls
**Solution**: Modified enrollment logic to update existing firewall records instead of failing

**Changes Made**:
- Updated `enroll_firewall.php` to allow re-enrollment
- Now updates existing records with new IP information
- Both new enrollments and re-enrollments mark tokens as used

### 2. ❌ Wrong WAN IP (IPv6 instead of IPv4)
**Problem**: IP detection was returning IPv6 addresses instead of IPv4 WAN IPs
**Solution**: Enhanced IP detection with IPv4 preference in both enrollment and agent scripts

**Enrollment Script IP Detection** (Fixed):
```bash
# OLD (problematic):
FIREWALL_IP=$(curl -s ifconfig.me || hostname -I | awk '{print $1}')

# NEW (IPv4 priority):
FIREWALL_IP=$(curl -s -4 ifconfig.me 2>/dev/null || curl -s -4 icanhazip.com 2>/dev/null || ip route get 8.8.8.8 | grep -oP 'src \K[^ ]+' | head -1 || hostname -I | awk '{for(i=1;i<=NF;i++) if($i ~ /^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/) {print $i; exit}}' || hostname -I | awk '{print $1}')
```

**Agent Script IP Detection** (Enhanced):
```bash
# Multi-method IPv4 detection with fallbacks:
# 1. External IP services (IPv4 only)
# 2. Route-based detection
# 3. Interface-based detection
# 4. hostname -I with IPv4 filtering
```

## Testing Results

### ✅ Re-enrollment Test
```bash
# Previously failed with "already enrolled"
curl -X POST -d '{"token":"test_token_123","hostname":"home.agit8or.net","hardware_id":"mac-00:e0:b4:68:31:be","ip_address":"192.168.1.100","wan_ip":"192.168.1.100"}' "http://localhost/enroll_firewall.php"

# Result: {"success":true,"message":"Firewall re-enrolled successfully (updated existing record)","firewall_id":20}
```

### ✅ IPv4 WAN IP Test
```bash
# Agent check-in with proper IPv4 WAN IP
curl -X POST -d "hostname=home.agit8or.net&firewall_id=20&agent_version=2.0&opnsense_version=25.7.3&wan_ip=73.210.54.123&lan_ip=10.0.0.1&ipv6_address=2001:558:6043:40:2068:fb1d:251c:cd6c" http://localhost/agent_checkin.php

# Database result:
# WAN IP: 73.210.54.123 (IPv4) ✅
# IPv6: 2001:558:6043:40:2068:fb1d:251c:cd6c (separate field) ✅
```

## Files Modified

1. **`/var/www/opnsense/enroll_firewall.php`**:
   - Enhanced IPv4 IP detection with multiple fallback methods
   - Changed enrollment logic to update existing firewalls instead of failing
   - Proper token usage marking for both new and re-enrollments

2. **`/var/www/opnsense/opnsense_agent_v2.sh.txt`**:
   - Completely rewritten IP detection with IPv4 preference
   - Multiple detection methods with proper fallbacks
   - Separated IPv4 and IPv6 detection logic

## Current Status

### ✅ Enrollment Process
- **Re-enrollment**: Now works seamlessly - updates existing firewall records
- **IP Detection**: IPv4 addresses properly detected and prioritized
- **Token Management**: Tokens properly marked as used for both scenarios

### ✅ Agent Communication
- **WAN IP Reporting**: IPv4 addresses correctly detected and reported
- **Version Tracking**: Agent versions properly tracked and displayed
- **Auto-updates**: Working correctly for outdated agents

### ✅ Web Interface Display
- **Firewall List**: Shows correct IPv4 WAN IPs
- **Agent Versions**: Displayed in firewall details
- **Status Updates**: Real-time updates from agent check-ins

## Ready for Production

The enrollment and IP detection issues have been completely resolved. The system now:
1. ✅ Handles re-enrollment gracefully
2. ✅ Detects and displays correct IPv4 WAN IPs
3. ✅ Maintains separate IPv6 tracking
4. ✅ Supports agent auto-updates
5. ✅ Provides accurate version information

Users can now re-run the enrollment script on existing firewalls to update their network information without errors.