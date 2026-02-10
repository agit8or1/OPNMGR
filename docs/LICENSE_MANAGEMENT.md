# OPNManager License Management System

## Overview

The OPNManager License System provides comprehensive subscription and instance management for multi-tenant deployments. It enables:

- **License Tier Management** - Professional, Enterprise, Ultimate plans
- **Instance Creation** - Provision new customer licenses with automatic key generation
- **API Authentication** - Secure API key/secret pairs for license verification
- **Usage Tracking** - Monitor firewall count and resource usage per instance
- **License Check-ins** - Audit trail of license verification requests
- **Expiration Management** - Automatic expiration detection and renewal notifications

## Architecture

### Database Schema

#### 1. `license_tiers`
Subscription plans defining feature limits and pricing.

```sql
license_tiers:
  - tier_name (UNIQUE) - Plan identifier (Trial, Professional, Enterprise, Ultimate)
  - max_firewalls - Maximum firewalls allowed per instance
  - max_users - Maximum users per instance
  - max_api_keys - Maximum API keys per instance
  - price_monthly - Monthly subscription cost
  - price_annual - Annual subscription cost
  - features - JSON object of features enabled
  - is_active - Whether tier is available
  - created_at, updated_at
```

#### 2. `deployed_instances`
Customer license instances.

```sql
deployed_instances:
  - instance_name - Customer-friendly name
  - instance_key - LIC-XXXX-XXXX-XXXX-XXXX format license key
  - license_tier - Reference to license_tiers.tier_name
  - max_firewalls - From tier (denormalized for quick access)
  - current_firewalls - Active firewall count (updated on check-in)
  - status - trial | active | suspended | expired
  - license_expires - Expiration date/time
  - created_at, updated_at, last_checkin
```

#### 3. `license_checkins`
Audit trail of license verification requests.

```sql
license_checkins:
  - instance_id - Reference to deployed_instances
  - instance_key - Key provided by client
  - firewall_count - Number of firewalls reported by instance
  - status - Response status (active|trial|suspended|expired)
  - ip_address - Client IP address
  - user_agent - Client agent string
  - checkin_time - Request timestamp
```

#### 4. `license_activity_log`
Comprehensive activity logging for licensing operations.

```sql
license_activity_log:
  - instance_id - Reference to deployed_instances
  - action - Human-readable action description
  - action_type - Enum: create|extend|suspend|reactivate|delete|checkin
  - details - JSON or text details
  - user_id - Admin user who performed action
  - created_at
```

#### 5. `license_api_keys`
API authentication credentials for license verification.

```sql
license_api_keys:
  - instance_id - Reference to deployed_instances
  - api_key - API_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX (unique)
  - api_secret - XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX (hashed)
  - is_active - Whether key is enabled
  - last_used - Last verification request timestamp
  - expires_at - Key expiration (default 1 year)
  - created_at
```

## Setup & Initialization

### Step 1: Initialize License Tables

1. Navigate to **Development → License Setup** (`/license_init.php`)
2. Click **Initialize License Tables**
3. System will create:
   - `license_tiers` table with 4 default tiers
   - `deployed_instances`, `license_checkins`, `license_activity_log` tables
   - `license_api_keys` table for API authentication

### Step 2: Create Customer License Instances

1. Navigate to **Development → License Server** (`/license_server.php`)
2. Fill in instance creation form:
   - **Instance Name** - Customer identifier
   - **License Tier** - Select plan (Trial/Professional/Enterprise/Ultimate)
   - **Max Firewalls** - Auto-populated from tier
   - **Status** - Trial (30 days) or Active (365 days)
3. Click **Create Instance**
4. System generates and displays:
   - **Instance Key** (LIC-XXXX-XXXX-XXXX-XXXX format)
   - **API Key** (for server-to-server verification)

### Step 3: Configure Client Instance

Provide customer with:
- Instance Key for license validation
- API credentials for server check-ins
- Documentation on license check-in process

## License Key Format

```
LIC-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}
Example: LIC-A1B2-C3D4-E5F6-7890
```

All license keys are randomly generated 64-bit hexadecimal values formatted as above.

## API Integration

### License Check-in Endpoint

Client instances periodically check their license status:

```php
// POST /api/license_checkin.php
{
  "instance_key": "LIC-XXXX-XXXX-XXXX-XXXX",
  "firewall_count": 5,
  "api_key": "API_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX"
}

// Response:
{
  "success": true,
  "status": "active",
  "expires": "2026-10-28",
  "max_firewalls": 20,
  "current_firewalls": 5
}
```

### Status Responses

| Status | Meaning | Action |
|--------|---------|--------|
| `active` | License valid and active | Continue normal operation |
| `trial` | Trial period active | Show trial expiration date |
| `suspended` | Manually suspended | Stop all operations |
| `expired` | License expiration date passed | Lock out features |

## License Tier Details

### Trial (Free - 30 Days)
- Max 5 Firewalls
- Max 3 Users
- Max 2 API Keys
- All features enabled
- Automatic expiration

### Professional ($99.99/month or $999.90/year)
- Max 20 Firewalls
- Max 10 Users
- Max 5 API Keys
- Full feature set
- Email support

### Enterprise ($499.99/month or $4,999.90/year)
- Max 100 Firewalls
- Max 50 Users
- Max 20 API Keys
- Advanced analytics
- Priority support
- Custom integrations

### Ultimate ($1,999.99/month or $19,999.90/year)
- Max 500 Firewalls
- Max 200 Users
- Max 100 API Keys
- Everything in Enterprise +
- Dedicated account manager
- Custom SLA

## Utility Functions

### inc/license_utils.php

```php
// Generate license key in LIC-XXXX-XXXX-XXXX-XXXX format
$key = generateLicenseKey();

// Generate API credentials
$creds = generateAPICredentials(); // Returns ['key' => '...', 'secret' => '...']

// Validate license key format
if (isValidLicenseKey($key)) { ... }

// Check current license status
$status = checkLicenseStatus($instanceId, $DB);

// Record license check-in (audit trail)
recordLicenseCheckIn($instanceId, $key, $firewallCount, $status, $DB);

// Log activity
logLicenseActivity($instanceId, $action, $actionType, $details, $userId, $DB);

// Get license statistics
$stats = getLicenseStats($DB);

// Export license key for download
$export = exportLicenseKey($instanceId, $DB);

// Initialize license tables (one-time setup)
$result = initializeLicenseTables($DB);
```

## Administration Tasks

### Extend License
1. Go to License Server
2. Find instance in list
3. Click "Extend" button
4. Enter number of days to extend
5. Click "Extend License"

### Suspend License
1. Click "Edit" on instance
2. Change Status to "Suspended"
3. Click "Update"
4. License will immediately return "suspended" on check-ins

### Reactivate Suspended License
1. Click "Edit" on instance
2. Change Status back to "Active"
3. Click "Update"

### Delete Instance
1. Click "Delete" button on instance
2. Confirm deletion
3. All associated check-ins and activity logs cascade delete

### View Activity
1. Go to License Server
2. Scroll to "Recent Activity"
3. View all create/extend/suspend/delete operations
4. Check-in history visible in "Recent Check-ins"

## Monitoring

### Dashboard Statistics
- **Total Instances** - Number of deployed licenses
- **Active Instances** - Licenses actively checked-in within last 24 hours
- **Trial Instances** - Licenses in trial period
- **Expired Instances** - Licenses past expiration date
- **Total Firewall Capacity** - Sum of all max_firewalls
- **Total Firewalls Used** - Sum of all current_firewalls

### Alerts
- **Expiring Soon** - License within 7 days of expiration
- **Expired** - License past expiration date
- **Inactive** - No check-in for 7+ days
- **Over Capacity** - Using more firewalls than tier allows

## Troubleshooting

### "License system not initialized" Message

**Problem:** Tables don't exist in database.

**Solution:**
1. Navigate to `/license_init.php`
2. Click "Initialize License Tables"
3. Refresh License Server page

### Instance Creation Hangs

**Causes & Fixes:**
- Database credentials incorrect → Check `config.php`
- PDO connection issue → Check MySQL service running
- Tables not initialized → Run initialization page

### Check-in Failures

**Client receiving "expired" when active:**
- Check server time synchronization
- Verify `license_expires` field in database
- Check API key hasn't been deleted

**Client receiving "suspended":**
- Check instance status in admin panel
- Verify not manually suspended

## Security Considerations

- API secrets stored in database (TODO: hash secrets on storage)
- License keys transmitted over HTTPS only
- Rate limiting on check-in endpoint (TODO: implement)
- IP whitelisting option (TODO: implement)
- Audit all license operations (✓ Implemented)

## Future Enhancements

- [ ] License key revocation
- [ ] Partial tier downgrade
- [ ] Usage-based billing integration
- [ ] Automatic renewal via payment processor
- [ ] White-label licensing for resellers
- [ ] License sharing between instances
- [ ] Cloud sync for license validation
